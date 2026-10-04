import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthService } from '../core/auth/auth.service';
import { LanguageService, SUPPORTED_LOCALES } from '../core/i18n/language.service';
import { Locale } from '../core/models';

@Component({
  selector: 'app-language-switcher',
  imports: [TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="flex items-center gap-1 text-sm" role="group" [attr.aria-label]="'nav.language' | translate">
      @for (locale of locales; track locale) {
        <button
          type="button"
          class="rounded px-2 py-1 font-medium uppercase transition"
          [class]="locale === language.current() ? 'bg-slate-900 text-white' : 'text-slate-500 hover:bg-slate-200'"
          [attr.aria-pressed]="locale === language.current()"
          (click)="select(locale)"
        >
          {{ locale }}
        </button>
      }
    </div>
  `,
})
export class LanguageSwitcher {
  protected readonly language = inject(LanguageService);
  private readonly auth = inject(AuthService);
  protected readonly locales = SUPPORTED_LOCALES;

  protected async select(locale: Locale): Promise<void> {
    await this.language.use(locale);
    if (this.auth.isLoggedIn() && this.auth.user()?.locale !== locale) {
      await this.auth.updateProfile({ locale });
    }
  }
}
