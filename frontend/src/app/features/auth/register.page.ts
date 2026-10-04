import { HttpClient } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { rxResource } from '@angular/core/rxjs-interop';
import { FormField, ValidationError, email, form, maxLength, minLength, required, submit } from '@angular/forms/signals';
import { Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { TranslatedError, apiError, apiFieldErrors } from '../../core/api/api-error';
import { AuthService } from '../../core/auth/auth.service';
import { LanguageService } from '../../core/i18n/language.service';
import { FieldErrors } from '../../shared/field-errors';
import { ResendVerification } from './resend-verification';

@Component({
  selector: 'app-register-page',
  imports: [FormField, RouterLink, TranslatePipe, FieldErrors, ResendVerification],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (config.value()?.allow_registration === false) {
      <div class="card space-y-4">
        <h2 class="text-lg font-semibold">{{ 'auth.registerTitle' | translate }}</h2>
        <p class="text-sm text-slate-600">{{ 'auth.registrationDisabled' | translate }}</p>
        <a routerLink="/login" class="btn btn-secondary w-full">{{ 'auth.login' | translate }}</a>
      </div>
    } @else if (pendingEmail(); as email) {
      <div class="card space-y-4" role="status">
        <h2 class="text-lg font-semibold">{{ 'verify.checkInbox' | translate }}</h2>
        <p class="text-sm text-slate-600">{{ 'verify.sentTo' | translate: { email } }}</p>
        <app-resend-verification [email]="email" />
        <a routerLink="/login" class="block text-center text-sm font-medium text-blue-600 hover:underline">{{ 'auth.login' | translate }}</a>
      </div>
    } @else {
      <form class="card space-y-4" (submit)="onSubmit($event)" novalidate>
        <h2 class="text-lg font-semibold">{{ 'auth.registerTitle' | translate }}</h2>

        @if (error(); as err) {
          <p class="alert-error" role="alert">{{ err.key | translate: err.params }}</p>
        }

        <div>
          <label class="label" for="name">{{ 'auth.name' | translate }}</label>
          <input id="name" type="text" class="input" autocomplete="name" [formField]="registerForm.name" />
          <app-field-errors [field]="registerForm.name()" />
        </div>

        <div>
          <label class="label" for="email">{{ 'auth.email' | translate }}</label>
          <input id="email" type="email" class="input" autocomplete="email" [formField]="registerForm.email" />
          <app-field-errors [field]="registerForm.email()" />
        </div>

        <div>
          <label class="label" for="password">{{ 'auth.password' | translate }}</label>
          <input id="password" type="password" class="input" autocomplete="new-password" [formField]="registerForm.password" />
          <p class="mt-1 text-xs text-slate-500">{{ 'auth.passwordHint' | translate }}</p>
          <app-field-errors [field]="registerForm.password()" />
        </div>

        <button type="submit" class="btn btn-primary w-full" [disabled]="registerForm().submitting()">
          {{ 'auth.register' | translate }}
        </button>

        <p class="text-center text-sm text-slate-600">
          {{ 'auth.haveAccount' | translate }}
          <a routerLink="/login" class="font-medium text-blue-600 hover:underline">{{ 'auth.login' | translate }}</a>
        </p>
      </form>
    }
  `,
})
export class RegisterPage {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly language = inject(LanguageService);
  private readonly http = inject(HttpClient);

  protected readonly config = rxResource({
    stream: () => this.http.get<{ allow_registration: boolean }>('api/auth/config'),
  });

  protected readonly model = signal({ name: '', email: '', password: '' });
  protected readonly registerForm = form(this.model, (path) => {
    required(path.name);
    maxLength(path.name, 100);
    required(path.email);
    email(path.email);
    required(path.password);
    minLength(path.password, 8);
  });
  protected readonly error = signal<TranslatedError | null>(null);
  /** Set after registering when the account still has to be verified by email. */
  protected readonly pendingEmail = signal<string | null>(null);

  protected async onSubmit(event: Event): Promise<void> {
    event.preventDefault();
    this.error.set(null);
    await submit(this.registerForm, async (form) => {
      try {
        const result = await this.auth.register({ ...this.model(), locale: this.language.current() });
        if ('verification_required' in result) {
          this.pendingEmail.set(result.email);
        } else {
          await this.router.navigateByUrl('/boards');
        }
        return undefined;
      } catch (err) {
        // Map server side field errors (e.g. email taken) onto the form fields.
        const fieldErrors = apiFieldErrors(err);
        const fields = { name: form.name, email: form.email, password: form.password } as const;
        const mapped: ValidationError.WithOptionalFieldTree[] = Object.entries(fieldErrors)
          .filter(([field]) => field in fields)
          .map(([field, e]) => ({ kind: e.key.replace('validation.', ''), fieldTree: fields[field as keyof typeof fields] }));
        if (mapped.length === 0) {
          this.error.set(apiError(err));
        }
        return mapped;
      }
    });
  }
}
