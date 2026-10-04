import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthService } from '../core/auth/auth.service';
import { LanguageSwitcher } from './language-switcher';

/** Layout for signed-in users: top navigation + page content. */
@Component({
  selector: 'app-shell',
  imports: [RouterOutlet, RouterLink, RouterLinkActive, TranslatePipe, LanguageSwitcher],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="border-b border-slate-200 bg-white">
      <nav class="mx-auto flex h-14 max-w-7xl items-center gap-6 px-4">
        <a routerLink="/boards" class="text-lg font-bold tracking-tight">{{ 'app.name' | translate }}</a>
        <div class="flex items-center gap-1 text-sm">
          <a routerLink="/boards" routerLinkActive="bg-slate-100 text-slate-900" class="rounded px-3 py-1.5 text-slate-600 hover:text-slate-900">
            {{ 'nav.boards' | translate }}
          </a>
          @if (auth.isAdmin()) {
            <a routerLink="/admin/users" routerLinkActive="bg-slate-100 text-slate-900" class="rounded px-3 py-1.5 text-slate-600 hover:text-slate-900">
              {{ 'nav.users' | translate }}
            </a>
            <a routerLink="/admin/settings" routerLinkActive="bg-slate-100 text-slate-900" class="rounded px-3 py-1.5 text-slate-600 hover:text-slate-900">
              {{ 'nav.settings' | translate }}
            </a>
          }
        </div>
        <div class="ml-auto flex items-center gap-4">
          <app-language-switcher />
          <span class="hidden text-sm text-slate-600 sm:inline">{{ auth.user()?.name }}</span>
          <button type="button" class="btn btn-secondary" (click)="auth.logout()">{{ 'nav.logout' | translate }}</button>
        </div>
      </nav>
    </header>
    <main class="mx-auto max-w-7xl px-4 py-6">
      <router-outlet />
    </main>
  `,
})
export class Shell {
  protected readonly auth = inject(AuthService);
}
