import { ChangeDetectionStrategy, Component, inject, input, signal } from '@angular/core';
import { TranslatePipe } from '@ngx-translate/core';
import { TranslatedError, apiError } from '../../core/api/api-error';
import { AuthService } from '../../core/auth/auth.service';

/** "Send the verification email again" button, used after registering and on a blocked login. */
@Component({
  selector: 'app-resend-verification',
  imports: [TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (sent()) {
      <p class="text-sm text-green-700">{{ 'verify.resent' | translate }}</p>
    } @else {
      <button type="button" class="btn btn-secondary w-full" [disabled]="busy()" (click)="resend()">
        {{ 'verify.resend' | translate }}
      </button>
    }
    @if (error(); as err) {
      <p class="mt-2 text-sm text-red-600">{{ err.key | translate: err.params }}</p>
    }
  `,
})
export class ResendVerification {
  private readonly auth = inject(AuthService);

  readonly email = input.required<string>();

  protected readonly busy = signal(false);
  protected readonly sent = signal(false);
  protected readonly error = signal<TranslatedError | null>(null);

  protected async resend(): Promise<void> {
    this.busy.set(true);
    this.error.set(null);
    try {
      await this.auth.resendVerification(this.email());
      this.sent.set(true);
    } catch (err) {
      this.error.set(apiError(err));
    } finally {
      this.busy.set(false);
    }
  }
}
