import { HttpClient } from '@angular/common/http';
import { DestroyRef, Injectable, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { Observable, catchError, finalize, firstValueFrom, map, of, shareReplay, tap } from 'rxjs';
import { LanguageService } from '../i18n/language.service';
import { Locale, SessionResponse, User } from '../models';

export interface LoginRequest {
  email: string;
  password: string;
}

export interface RegisterRequest extends LoginRequest {
  name: string;
  locale: Locale;
}

/**
 * Session handling:
 * - The access token (JWT, ~15 min) lives only in memory, never in localStorage.
 * - The refresh token is an httpOnly, SameSite=Strict cookie scoped to api/auth,
 *   so JavaScript can't read it. It is rotated on every refresh.
 */
@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly http = inject(HttpClient);
  private readonly router = inject(Router);
  private readonly language = inject(LanguageService);

  private readonly _user = signal<User | null>(null);
  private accessToken: string | null = null;
  private refreshTimer: ReturnType<typeof setTimeout> | undefined;
  private refreshInFlight: Observable<string | null> | null = null;

  readonly user = this._user.asReadonly();
  readonly isLoggedIn = computed(() => this._user() !== null);
  readonly isAdmin = computed(() => this._user()?.role === 'admin');

  constructor() {
    inject(DestroyRef).onDestroy(() => clearTimeout(this.refreshTimer));
  }

  get token(): string | null {
    return this.accessToken;
  }

  /** Called once at startup: resumes the session from the refresh cookie, if any. */
  async restoreSession(): Promise<void> {
    await firstValueFrom(this.refresh());
  }

  async login(credentials: LoginRequest): Promise<User> {
    const session = await firstValueFrom(this.http.post<SessionResponse>('api/auth/login', credentials));
    return this.startSession(session);
  }

  async register(data: RegisterRequest): Promise<User> {
    const session = await firstValueFrom(this.http.post<SessionResponse>('api/auth/register', data));
    return this.startSession(session);
  }

  async logout(): Promise<void> {
    try {
      await firstValueFrom(this.http.post('api/auth/logout', {}));
    } finally {
      this.clearSession();
      await this.router.navigateByUrl('/login');
    }
  }

  /** Session can't be renewed any more (e.g. revoked); send the user to the login page. */
  sessionExpired(): void {
    this.clearSession();
    void this.router.navigate(['/login'], { queryParams: { expired: 1 } });
  }

  /**
   * Exchanges the refresh cookie for a new access token. Concurrent callers share
   * one request, so parallel 401s don't trigger refresh token reuse detection.
   */
  refresh(): Observable<string | null> {
    this.refreshInFlight ??= this.http.post<SessionResponse>('api/auth/refresh', {}).pipe(
      tap((session) => this.startSession(session)),
      map((session) => session.access_token),
      catchError(() => {
        this.clearSession();
        return of(null);
      }),
      finalize(() => (this.refreshInFlight = null)),
      shareReplay(1),
    );
    return this.refreshInFlight;
  }

  async updateProfile(patch: Partial<Pick<User, 'name' | 'locale'>>): Promise<void> {
    const user = await firstValueFrom(this.http.patch<User>('api/auth/me', patch));
    this._user.set(user);
  }

  private startSession(session: SessionResponse): User {
    this.accessToken = session.access_token;
    this._user.set(session.user);
    void this.language.use(session.user.locale);
    this.scheduleRefresh(session.expires_in);
    return session.user;
  }

  private clearSession(): void {
    this.accessToken = null;
    this._user.set(null);
    clearTimeout(this.refreshTimer);
  }

  /** Renews the access token shortly before it expires to avoid failing requests. */
  private scheduleRefresh(expiresInSeconds: number): void {
    clearTimeout(this.refreshTimer);
    const delay = Math.max(expiresInSeconds - 60, 10) * 1000;
    this.refreshTimer = setTimeout(() => {
      this.refresh().subscribe((token) => {
        if (token === null) {
          this.sessionExpired();
        }
      });
    }, delay);
  }
}
