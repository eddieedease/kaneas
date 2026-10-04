import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { rxResource } from '@angular/core/rxjs-interop';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import { Observable, firstValueFrom } from 'rxjs';
import { TranslatedError, apiError } from '../../core/api/api-error';
import { AuthService } from '../../core/auth/auth.service';
import { AdminUser, SystemRole } from '../../core/models';
import { LanguageService } from '../../core/i18n/language.service';
import { AdminApi } from './admin.api';

@Component({
  selector: 'app-admin-users-page',
  imports: [TranslatePipe, DatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h1 class="mb-6 text-2xl font-bold">{{ 'admin.usersTitle' | translate }}</h1>

    @if (error(); as err) {
      <p class="alert-error mb-4">{{ err.key | translate: err.params }}</p>
    }

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
      <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
          <tr>
            <th class="px-4 py-3">{{ 'admin.name' | translate }}</th>
            <th class="px-4 py-3">{{ 'admin.email' | translate }}</th>
            <th class="px-4 py-3">{{ 'admin.role' | translate }}</th>
            <th class="px-4 py-3">{{ 'admin.status' | translate }}</th>
            <th class="px-4 py-3">{{ 'admin.boards' | translate }}</th>
            <th class="px-4 py-3">{{ 'admin.lastLogin' | translate }}</th>
            <th class="px-4 py-3"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          @for (user of users.value() ?? []; track user.id) {
            @let isMe = user.id === auth.user()?.id;
            <tr>
              <td class="px-4 py-3 font-medium">
                {{ user.name }}
                @if (isMe) {
                  <span class="text-xs text-slate-400">({{ 'admin.you' | translate }})</span>
                }
              </td>
              <td class="px-4 py-3 text-slate-600">{{ user.email }}</td>
              <td class="px-4 py-3">
                <select class="rounded border border-slate-300 px-2 py-1" [value]="user.role" [disabled]="isMe" (change)="setRole(user, $any($event.target).value)">
                  <option value="user">{{ 'admin.roles.user' | translate }}</option>
                  <option value="admin">{{ 'admin.roles.admin' | translate }}</option>
                </select>
              </td>
              <td class="px-4 py-3">
                <span class="rounded-full px-2 py-0.5 text-xs" [class]="user.is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'">
                  {{ (user.is_active ? 'admin.active' : 'admin.inactive') | translate }}
                </span>
              </td>
              <td class="px-4 py-3">{{ user.board_count }}</td>
              <td class="px-4 py-3 text-slate-600">{{ user.last_login_at ? (user.last_login_at + 'Z' | date: 'short' : undefined : language.current()) : '—' }}</td>
              <td class="px-4 py-3 text-right whitespace-nowrap">
                @if (!isMe) {
                  <button type="button" class="text-blue-600 hover:underline" (click)="toggleActive(user)">
                    {{ (user.is_active ? 'admin.deactivate' : 'admin.activate') | translate }}
                  </button>
                  <button type="button" class="ml-3 text-red-600 hover:underline" (click)="remove(user)">{{ 'admin.delete' | translate }}</button>
                }
              </td>
            </tr>
          }
        </tbody>
      </table>
    </div>
  `,
})
export class AdminUsersPage {
  private readonly api = inject(AdminApi);
  private readonly translate = inject(TranslateService);
  protected readonly auth = inject(AuthService);
  protected readonly language = inject(LanguageService);

  protected readonly users = rxResource({ stream: () => this.api.users() });
  protected readonly error = signal<TranslatedError | null>(null);

  protected setRole(user: AdminUser, role: SystemRole): Promise<void> {
    return this.run(this.api.updateUser(user.id, { role }));
  }

  protected toggleActive(user: AdminUser): Promise<void> {
    return this.run(this.api.updateUser(user.id, { is_active: !user.is_active }));
  }

  protected remove(user: AdminUser): Promise<void> | void {
    if (confirm(this.translate.instant('admin.deleteConfirm', { name: user.name }))) {
      return this.run(this.api.deleteUser(user.id));
    }
  }

  private async run(request: Observable<unknown>): Promise<void> {
    this.error.set(null);
    try {
      await firstValueFrom(request);
    } catch (err) {
      this.error.set(apiError(err));
    }
    this.users.reload();
  }
}
