import { ChangeDetectionStrategy, Component, OnInit, inject, input, signal } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { TranslatedError, apiError } from '../../core/api/api-error';
import { AuthService } from '../../core/auth/auth.service';

/** Target of the link in the verification email: verify-email?token=... */
@Component({
  selector: 'app-verify-email-page',
  imports: [RouterLink, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="card space-y-4">
      <h2 class="text-lg font-semibold">{{ 'verify.title' | translate }}</h2>
      @if (error(); as err) {
        <p class="alert-error" role="alert">{{ err.key | translate: err.params }}</p>
        <p class="text-sm text-slate-600">{{ 'verify.invalidHelp' | translate }}</p>
        <a routerLink="/login" class="btn btn-secondary w-full">{{ 'auth.login' | translate }}</a>
      } @else {
        <p class="text-sm text-slate-600" role="status">{{ 'verify.checking' | translate }}</p>
      }
    </div>
  `,
})
export class VerifyEmailPage implements OnInit {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  /** Query param, bound via withComponentInputBinding(). */
  readonly token = input<string>();

  protected readonly error = signal<TranslatedError | null>(null);

  async ngOnInit(): Promise<void> {
    const token = this.token();
    if (!token) {
      this.error.set({ key: 'errors.verification_invalid' });
      return;
    }
    try {
      await this.auth.verifyEmail(token);
      // Drop the token from the URL/history.
      await this.router.navigateByUrl('/boards', { replaceUrl: true });
    } catch (err) {
      this.error.set(apiError(err));
    }
  }
}
