import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { rxResource } from '@angular/core/rxjs-interop';
import { FormField, form, maxLength, required, submit } from '@angular/forms/signals';
import { Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { firstValueFrom } from 'rxjs';
import { TranslatedError, apiError } from '../../core/api/api-error';
import { FieldErrors } from '../../shared/field-errors';
import { BoardsApi } from './boards.api';

@Component({
  selector: 'app-board-list-page',
  imports: [FormField, RouterLink, TranslatePipe, FieldErrors],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { class: 'mx-auto block max-w-7xl' },
  template: `
    <div class="mb-6 flex items-center justify-between">
      <h1 class="text-2xl font-bold">{{ 'boards.title' | translate }}</h1>
    </div>

    <div class="grid gap-6 lg:grid-cols-[1fr_320px]">
      <section>
        @if (boards.error()) {
          <p class="alert-error">{{ 'errors.unknown' | translate }}</p>
        } @else if (boards.hasValue() && boards.value().length === 0) {
          <p class="card text-slate-600">{{ 'boards.empty' | translate }}</p>
        }
        <ul class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
          @for (board of boards.value() ?? []; track board.id) {
            <li>
              <a [routerLink]="['/boards', board.id]" class="card block h-full transition hover:border-blue-400 hover:shadow-md">
                <div class="flex items-start justify-between gap-2">
                  <h2 class="font-semibold">{{ board.name }}</h2>
                  <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{{ 'boards.role.' + board.my_role | translate }}</span>
                </div>
                @if (board.description) {
                  <p class="mt-1 line-clamp-2 text-sm text-slate-600">{{ board.description }}</p>
                }
                <p class="mt-3 text-xs text-slate-500">
                  {{ 'boards.members' | translate: { count: board.member_count } }} · {{ 'boards.cards' | translate: { count: board.card_count } }}
                </p>
              </a>
            </li>
          }
        </ul>
      </section>

      <aside>
        <form class="card space-y-3" (submit)="onCreate($event)" novalidate>
          <h2 class="font-semibold">{{ 'boards.new' | translate }}</h2>
          @if (error(); as err) {
            <p class="alert-error">{{ err.key | translate: err.params }}</p>
          }
          <div>
            <label class="label" for="board-name">{{ 'boards.name' | translate }}</label>
            <input id="board-name" class="input" [formField]="createForm.name" />
            <app-field-errors [field]="createForm.name()" />
          </div>
          <div>
            <label class="label" for="board-description">{{ 'boards.description' | translate }}</label>
            <textarea id="board-description" class="input" rows="3" [formField]="createForm.description"></textarea>
          </div>
          <button type="submit" class="btn btn-primary w-full" [disabled]="createForm().submitting()">{{ 'boards.create' | translate }}</button>
        </form>
      </aside>
    </div>
  `,
})
export class BoardListPage {
  private readonly api = inject(BoardsApi);
  private readonly router = inject(Router);

  protected readonly boards = rxResource({ stream: () => this.api.list() });

  protected readonly model = signal({ name: '', description: '' });
  protected readonly createForm = form(this.model, (path) => {
    required(path.name);
    maxLength(path.name, 150);
  });
  protected readonly error = signal<TranslatedError | null>(null);

  protected async onCreate(event: Event): Promise<void> {
    event.preventDefault();
    this.error.set(null);
    await submit(this.createForm, async () => {
      try {
        const { name, description } = this.model();
        const board = await firstValueFrom(this.api.create({ name, description: description || undefined }));
        await this.router.navigate(['/boards', board.id]);
      } catch (err) {
        this.error.set(apiError(err));
      }
      return undefined;
    });
  }
}
