import { DOCUMENT, Injectable, inject, signal } from '@angular/core';
import { TranslateService } from '@ngx-translate/core';
import { firstValueFrom } from 'rxjs';
import { Locale } from '../models';

const STORAGE_KEY = 'kaneas.lang';

export const SUPPORTED_LOCALES: readonly Locale[] = ['nl', 'en'];
export const DEFAULT_LOCALE: Locale = 'nl';

@Injectable({ providedIn: 'root' })
export class LanguageService {
  private readonly translate = inject(TranslateService);
  private readonly document = inject(DOCUMENT);

  private readonly _current = signal<Locale>(DEFAULT_LOCALE);
  readonly current = this._current.asReadonly();

  /** Loads the stored language (or Dutch) before the first render. */
  init(): Promise<unknown> {
    return this.use(this.readStored() ?? DEFAULT_LOCALE);
  }

  async use(locale: Locale): Promise<void> {
    if (!SUPPORTED_LOCALES.includes(locale)) {
      locale = DEFAULT_LOCALE;
    }
    this._current.set(locale);
    this.document.documentElement.lang = locale;
    try {
      localStorage.setItem(STORAGE_KEY, locale);
    } catch {
      // Storage can be unavailable (private mode); the choice then lasts for this session only.
    }
    await firstValueFrom(this.translate.use(locale));
  }

  private readStored(): Locale | null {
    try {
      const stored = localStorage.getItem(STORAGE_KEY);
      return SUPPORTED_LOCALES.includes(stored as Locale) ? (stored as Locale) : null;
    } catch {
      return null;
    }
  }
}
