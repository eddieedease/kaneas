import { ChangeDetectionStrategy, Component, computed, inject, input, output, signal } from '@angular/core';
import { FormField, email, form, required, submit } from '@angular/forms/signals';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import { Observable, firstValueFrom } from 'rxjs';
import { TranslatedError, apiError, apiFieldErrors } from '../../core/api/api-error';
import { AuthService } from '../../core/auth/auth.service';
import { Board, BoardMember } from '../../core/models';
import { FieldErrors } from '../../shared/field-errors';
import { BoardsApi, InviteRole } from './boards.api';

/** Board collaboration: the owner adds people by email and manages roles. */
@Component({
  selector: 'app-members-panel',
  imports: [FormField, TranslatePipe, FieldErrors],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <section class="card space-y-4 p-4">
      <h2 class="font-semibold">{{ 'members.title' | translate }}</h2>

      @if (error(); as err) {
        <p class="alert-error">{{ err.key | translate: err.params }}</p>
      }
      @if (notice(); as n) {
        <p class="alert-success">{{ n | translate }}</p>
      }

      <ul class="divide-y divide-slate-100">
        @for (member of board().members; track member.user_id) {
          <li class="flex items-center gap-2 py-2">
            <div class="min-w-0 flex-1">
              <p class="truncate text-sm font-medium">{{ member.name }}</p>
              <p class="truncate text-xs text-slate-500">{{ member.email }}</p>
            </div>
            @if (isOwner() && member.role !== 'owner') {
              <select class="rounded border border-slate-300 px-1 py-0.5 text-xs" [value]="member.role" (change)="changeRole(member, $any($event.target).value)">
                <option value="editor">{{ 'boards.role.editor' | translate }}</option>
                <option value="viewer">{{ 'boards.role.viewer' | translate }}</option>
              </select>
              <button type="button" class="text-xs text-red-600 hover:underline" (click)="remove(member)">{{ 'members.remove' | translate }}</button>
            } @else {
              <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{{ 'boards.role.' + member.role | translate }}</span>
            }
          </li>
        }
      </ul>

      @if (isOwner()) {
        @if (board().invitations.length) {
          <div>
            <h3 class="mb-1 text-xs font-semibold tracking-wide text-slate-500 uppercase">{{ 'members.pending' | translate }}</h3>
            <ul class="space-y-1">
              @for (invitation of board().invitations; track invitation.id) {
                <li class="flex items-center gap-2 text-sm">
                  <span class="min-w-0 flex-1 truncate">{{ invitation.email }}</span>
                  <span class="text-xs text-slate-500">{{ 'boards.role.' + invitation.role | translate }}</span>
                  <button type="button" class="text-xs text-red-600 hover:underline" (click)="cancelInvitation(invitation.id)">{{ 'members.remove' | translate }}</button>
                </li>
              }
            </ul>
          </div>
        }

        <form class="space-y-2 border-t border-slate-100 pt-4" (submit)="invite($event)" novalidate>
          <h3 class="text-sm font-semibold">{{ 'members.invite' | translate }}</h3>
          <p class="text-xs text-slate-500">{{ 'members.inviteHelp' | translate }}</p>
          <div>
            <input type="email" class="input" [placeholder]="'members.email' | translate" [formField]="inviteForm.email" />
            <app-field-errors [field]="inviteForm.email()" />
          </div>
          <div class="flex gap-2">
            <select class="input" [formField]="inviteForm.role">
              <option value="editor">{{ 'boards.role.editor' | translate }}</option>
              <option value="viewer">{{ 'boards.role.viewer' | translate }}</option>
            </select>
            <button type="submit" class="btn btn-primary" [disabled]="inviteForm().submitting()">{{ 'members.add' | translate }}</button>
          </div>
        </form>
      } @else {
        <button type="button" class="btn btn-secondary w-full" (click)="leave()">{{ 'members.leave' | translate }}</button>
      }
    </section>
  `,
})
export class MembersPanel {
  private readonly api = inject(BoardsApi);
  private readonly auth = inject(AuthService);
  private readonly translate = inject(TranslateService);

  readonly board = input.required<Board>();
  /** Emitted after a change so the parent can reload the board. */
  readonly changed = output<void>();
  /** Emitted after the current user left the board. */
  readonly left = output<void>();

  protected readonly isOwner = computed(() => this.board().my_role === 'owner');
  protected readonly error = signal<TranslatedError | null>(null);
  protected readonly notice = signal<string | null>(null);

  protected readonly inviteModel = signal<{ email: string; role: InviteRole }>({ email: '', role: 'editor' });
  protected readonly inviteForm = form(this.inviteModel, (path) => {
    required(path.email);
    email(path.email);
  });

  protected async invite(event: Event): Promise<void> {
    event.preventDefault();
    this.reset();
    await submit(this.inviteForm, async (f) => {
      try {
        const { email: address, role } = this.inviteModel();
        const result = await firstValueFrom(this.api.addMember(this.board().id, address, role));
        this.notice.set(result.type === 'member' ? 'members.addedMember' : 'members.addedInvitation');
        this.inviteModel.set({ email: '', role });
        f.email().reset();
        this.changed.emit();
        return undefined;
      } catch (err) {
        const fieldError = apiFieldErrors(err)['email'];
        if (fieldError) {
          return [{ kind: fieldError.key.replace('validation.', ''), fieldTree: f.email }];
        }
        this.error.set(apiError(err));
        return undefined;
      }
    });
  }

  protected changeRole(member: BoardMember, role: InviteRole): Promise<void> {
    return this.run(() => this.api.updateMember(this.board().id, member.user_id, role));
  }

  protected remove(member: BoardMember): Promise<void> {
    return this.run(() => this.api.removeMember(this.board().id, member.user_id));
  }

  protected cancelInvitation(invitationId: number): Promise<void> {
    return this.run(() => this.api.deleteInvitation(this.board().id, invitationId));
  }

  protected async leave(): Promise<void> {
    if (!confirm(this.translate.instant('members.leave') + '?')) {
      return;
    }
    this.reset();
    try {
      await firstValueFrom(this.api.removeMember(this.board().id, this.auth.user()!.id));
      this.left.emit();
    } catch (err) {
      this.error.set(apiError(err));
    }
  }

  private async run(action: () => Observable<unknown>): Promise<void> {
    this.reset();
    try {
      await firstValueFrom(action());
      this.changed.emit();
    } catch (err) {
      this.error.set(apiError(err));
    }
  }

  private reset(): void {
    this.error.set(null);
    this.notice.set(null);
  }
}
