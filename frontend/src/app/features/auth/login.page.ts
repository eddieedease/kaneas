import { ChangeDetectionStrategy, Component, inject, input, signal } from '@angular/core';
import { FormField, email, form, required, submit } from '@angular/forms/signals';
import { Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { TranslatedError, apiError } from '../../core/api/api-error';
import { AuthService } from '../../core/auth/auth.service';
import { FieldErrors } from '../../shared/field-errors';

@Component({
  selector: 'app-login-page',
  imports: [FormField, RouterLink, TranslatePipe, FieldErrors],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <form class="card space-y-4" (submit)="onSubmit($event)" novalidate>
      <h2 class="text-lg font-semibold">{{ 'auth.loginTitle' | translate }}</h2>

      @if (expired() && !error()) {
        <p class="alert-error">{{ 'errors.session_expired' | translate }}</p>
      }
      @if (error(); as err) {
        <p class="alert-error" role="alert">{{ err.key | translate: err.params }}</p>
      }

      <div>
        <label class="label" for="email">{{ 'auth.email' | translate }}</label>
        <input id="email" type="email" class="input" autocomplete="username" [formField]="loginForm.email" />
        <app-field-errors [field]="loginForm.email()" />
      </div>

      <div>
        <label class="label" for="password">{{ 'auth.password' | translate }}</label>
        <input id="password" type="password" class="input" autocomplete="current-password" [formField]="loginForm.password" />
        <app-field-errors [field]="loginForm.password()" />
      </div>

      <button type="submit" class="btn btn-primary w-full" [disabled]="loginForm().submitting()">
        {{ 'auth.login' | translate }}
      </button>

      <p class="text-center text-sm text-slate-600">
        {{ 'auth.noAccount' | translate }}
        <a routerLink="/register" class="font-medium text-blue-600 hover:underline">{{ 'auth.register' | translate }}</a>
      </p>
    </form>
  `,
})
export class LoginPage {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  /** Query params, bound via withComponentInputBinding(). */
  readonly returnUrl = input<string>();
  readonly expired = input<string>();

  protected readonly model = signal({ email: '', password: '' });
  protected readonly loginForm = form(this.model, (path) => {
    required(path.email);
    email(path.email);
    required(path.password);
  });
  protected readonly error = signal<TranslatedError | null>(null);

  protected async onSubmit(event: Event): Promise<void> {
    event.preventDefault();
    this.error.set(null);
    await submit(this.loginForm, async () => {
      try {
        await this.auth.login(this.model());
        await this.router.navigateByUrl(this.safeReturnUrl());
      } catch (err) {
        this.error.set(apiError(err));
      }
      return undefined;
    });
  }

  /** Only allow in-app paths to prevent open redirects. */
  private safeReturnUrl(): string {
    const url = this.returnUrl();
    return url && url.startsWith('/') && !url.startsWith('//') ? url : '/boards';
  }
}
