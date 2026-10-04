import { ChangeDetectionStrategy, Component, effect, inject, signal } from '@angular/core';
import { rxResource } from '@angular/core/rxjs-interop';
import { FormField, applyWhen, email, form, max, min, required, submit } from '@angular/forms/signals';
import { TranslatePipe } from '@ngx-translate/core';
import { firstValueFrom } from 'rxjs';
import { TranslatedError, apiError } from '../../core/api/api-error';
import { MailEncryption } from '../../core/models';
import { FieldErrors } from '../../shared/field-errors';
import { AdminApi } from './admin.api';

interface MailFormModel {
  enabled: boolean;
  host: string;
  port: number;
  encryption: MailEncryption;
  username: string;
  password: string;
  from_address: string;
  from_name: string;
}

@Component({
  selector: 'app-admin-settings-page',
  imports: [FormField, TranslatePipe, FieldErrors],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { class: 'mx-auto block max-w-7xl' },
  template: `
    <h1 class="mb-6 text-2xl font-bold">{{ 'admin.settingsTitle' | translate }}</h1>

    <div class="grid max-w-3xl gap-6">
      <section class="card space-y-3">
        <h2 class="font-semibold">{{ 'admin.general' | translate }}</h2>
        @if (settings.value(); as s) {
          <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" class="size-4" [checked]="s.allow_registration" (change)="setRegistration($any($event.target).checked)" />
            {{ 'admin.allowRegistration' | translate }}
          </label>
        }
      </section>

      <form class="card space-y-4" (submit)="saveMail($event)" novalidate>
        <div>
          <h2 class="font-semibold">{{ 'admin.mail' | translate }}</h2>
          <p class="text-sm text-slate-500">{{ 'admin.mailHelp' | translate }}</p>
        </div>

        @if (mailError(); as err) {
          <p class="alert-error">{{ err.key | translate: err.params }}</p>
        }
        @if (mailNotice(); as n) {
          <p class="alert-success">{{ n.key | translate: n.params }}</p>
        }

        <label class="flex items-center gap-2 text-sm">
          <input type="checkbox" class="size-4" [formField]="mailForm.enabled" />
          {{ 'admin.mailEnabled' | translate }}
        </label>

        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <label class="label" for="mail-host">{{ 'admin.mailHost' | translate }}</label>
            <input id="mail-host" class="input" placeholder="smtp.example.com" [formField]="mailForm.host" />
            <app-field-errors [field]="mailForm.host()" />
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="label" for="mail-port">{{ 'admin.mailPort' | translate }}</label>
              <input id="mail-port" type="number" class="input" [formField]="mailForm.port" />
              <app-field-errors [field]="mailForm.port()" />
            </div>
            <div>
              <label class="label" for="mail-encryption">{{ 'admin.mailEncryption' | translate }}</label>
              <select id="mail-encryption" class="input" [formField]="mailForm.encryption">
                <option value="tls">{{ 'admin.encryption.tls' | translate }}</option>
                <option value="ssl">{{ 'admin.encryption.ssl' | translate }}</option>
                <option value="none">{{ 'admin.encryption.none' | translate }}</option>
              </select>
            </div>
          </div>
          <div>
            <label class="label" for="mail-username">{{ 'admin.mailUsername' | translate }}</label>
            <input id="mail-username" class="input" autocomplete="off" [formField]="mailForm.username" />
          </div>
          <div>
            <label class="label" for="mail-password">{{ 'admin.mailPassword' | translate }}</label>
            <input
              id="mail-password"
              type="password"
              class="input"
              autocomplete="new-password"
              [placeholder]="hasPassword() ? ('admin.mailPasswordKeep' | translate) : ''"
              [formField]="mailForm.password"
            />
          </div>
          <div>
            <label class="label" for="mail-from">{{ 'admin.mailFromAddress' | translate }}</label>
            <input id="mail-from" type="email" class="input" placeholder="noreply@example.com" [formField]="mailForm.from_address" />
            <app-field-errors [field]="mailForm.from_address()" />
          </div>
          <div>
            <label class="label" for="mail-from-name">{{ 'admin.mailFromName' | translate }}</label>
            <input id="mail-from-name" class="input" [formField]="mailForm.from_name" />
          </div>
        </div>

        <div class="flex flex-wrap gap-2">
          <button type="submit" class="btn btn-primary" [disabled]="mailForm().submitting()">{{ 'admin.save' | translate }}</button>
          <button type="button" class="btn btn-secondary" [disabled]="testing()" (click)="sendTest()">{{ 'admin.sendTest' | translate }}</button>
        </div>
      </form>
    </div>
  `,
})
export class AdminSettingsPage {
  private readonly api = inject(AdminApi);

  protected readonly settings = rxResource({ stream: () => this.api.settings() });
  private readonly mail = rxResource({ stream: () => this.api.mailSettings() });

  protected readonly hasPassword = signal(false);
  protected readonly testing = signal(false);
  protected readonly mailError = signal<TranslatedError | null>(null);
  protected readonly mailNotice = signal<TranslatedError | null>(null);

  protected readonly mailModel = signal<MailFormModel>({
    enabled: false,
    host: '',
    port: 587,
    encryption: 'tls',
    username: '',
    password: '',
    from_address: '',
    from_name: 'Kaneas',
  });
  protected readonly mailForm = form(this.mailModel, (path) => {
    min(path.port, 1);
    max(path.port, 65535);
    email(path.from_address);
    applyWhen(path, ({ value }) => value().enabled, (enabled) => {
      required(enabled.host);
      required(enabled.from_address);
    });
  });

  constructor() {
    // Fill the form once the stored settings are loaded.
    effect(() => {
      const stored = this.mail.value();
      if (stored) {
        const { has_password, ...rest } = stored;
        this.hasPassword.set(has_password);
        this.mailModel.set({ ...rest, password: '' });
      }
    });
  }

  protected async setRegistration(allow: boolean): Promise<void> {
    await firstValueFrom(this.api.updateSettings({ allow_registration: allow }));
    this.settings.reload();
  }

  protected async saveMail(event: Event): Promise<void> {
    event.preventDefault();
    this.mailError.set(null);
    this.mailNotice.set(null);
    await submit(this.mailForm, async () => {
      try {
        const { password, ...rest } = this.mailModel();
        // An empty password field means "keep the current one".
        await firstValueFrom(this.api.updateMailSettings({ ...rest, password: password === '' ? null : password }));
        this.mailNotice.set({ key: 'admin.saved' });
        this.mail.reload();
      } catch (err) {
        this.mailError.set(apiError(err));
      }
      return undefined;
    });
  }

  protected async sendTest(): Promise<void> {
    this.mailError.set(null);
    this.mailNotice.set(null);
    this.testing.set(true);
    try {
      const result = await firstValueFrom(this.api.sendTestMail());
      this.mailNotice.set({ key: 'admin.testSent', params: { to: result.to } });
    } catch (err) {
      this.mailError.set(apiError(err));
    } finally {
      this.testing.set(false);
    }
  }
}
