import { ChangeDetectionStrategy, Component } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { LanguageSwitcher } from './language-switcher';

/** Centered layout for login and registration. */
@Component({
  selector: 'app-auth-layout',
  imports: [RouterOutlet, TranslatePipe, LanguageSwitcher],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="flex min-h-dvh flex-col items-center justify-center px-4 py-10">
      <div class="w-full max-w-sm">
        <div class="mb-6 flex items-end justify-between">
          <div>
            <h1 class="text-2xl font-bold tracking-tight">{{ 'app.name' | translate }}</h1>
            <p class="text-sm text-slate-500">{{ 'app.tagline' | translate }}</p>
          </div>
          <app-language-switcher />
        </div>
        <router-outlet />
      </div>
    </div>
  `,
})
export class AuthLayout {}
