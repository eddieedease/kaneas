import { registerLocaleData } from '@angular/common';
import localeNl from '@angular/common/locales/nl';
import { provideHttpClient, withFetch, withInterceptors } from '@angular/common/http';
import { ApplicationConfig, inject, provideAppInitializer, provideBrowserGlobalErrorListeners } from '@angular/core';
import { provideRouter, withComponentInputBinding } from '@angular/router';
import { provideTranslateService } from '@ngx-translate/core';
import { provideTranslateHttpLoader } from '@ngx-translate/http-loader';
import { routes } from './app.routes';
import { authInterceptor } from './core/auth/auth.interceptor';
import { AuthService } from './core/auth/auth.service';
import { DEFAULT_LOCALE, LanguageService } from './core/i18n/language.service';

// Dutch formats for the date/number pipes (English is built in).
registerLocaleData(localeNl);

// Zoneless change detection is the default since Angular 21 — no zone.js in this app.
export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    provideRouter(routes, withComponentInputBinding()),
    provideHttpClient(withFetch(), withInterceptors([authInterceptor])),
    provideTranslateService({
      // Relative URL: resolves against <base href>, so it works in a sub folder.
      loader: provideTranslateHttpLoader({ prefix: 'i18n/', suffix: '.json' }),
      fallbackLang: DEFAULT_LOCALE,
      lang: DEFAULT_LOCALE,
    }),
    provideAppInitializer(async () => {
      const language = inject(LanguageService);
      const auth = inject(AuthService);
      await language.init();
      await auth.restoreSession();
    }),
  ],
};
