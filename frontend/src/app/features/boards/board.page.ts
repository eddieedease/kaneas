import { CdkDrag, CdkDragDrop, CdkDropList, CdkDropListGroup, moveItemInArray, transferArrayItem } from '@angular/cdk/drag-drop';
import { ChangeDetectionStrategy, Component, computed, inject, input, numberAttribute, signal } from '@angular/core';
import { rxResource } from '@angular/core/rxjs-interop';
import { FormField, form, maxLength, required, submit } from '@angular/forms/signals';
import { Router, RouterLink } from '@angular/router';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import { Observable, firstValueFrom } from 'rxjs';
import { TranslatedError, apiError } from '../../core/api/api-error';
import { Board, BoardColumn, Card } from '../../core/models';
import { BoardsApi } from './boards.api';
import { Autofocus } from '../../shared/autofocus';
import { MembersPanel } from './members-panel';

@Component({
  selector: 'app-board-page',
  imports: [CdkDropListGroup, CdkDropList, CdkDrag, FormField, RouterLink, TranslatePipe, MembersPanel, Autofocus],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { '(document:keydown.escape)': 'sidebarOpen.set(false)' },
  template: `
    <a routerLink="/boards" class="text-sm text-slate-500 hover:text-slate-900">&larr; {{ 'boards.back' | translate }}</a>

    @if (boardRes.error(); as err) {
      <p class="alert-error mt-4">{{ errorKey(err) | translate }}</p>
    }
    @if (error(); as err) {
      <p class="alert-error mt-4">{{ err.key | translate: err.params }}</p>
    }

    @if (board(); as board) {
      <div class="mt-2 mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 class="text-2xl font-bold">{{ board.name }}</h1>
          @if (board.description) {
            <p class="mt-1 max-w-2xl text-sm text-slate-600">{{ board.description }}</p>
          }
          @if (!canEdit()) {
            <p class="mt-2 text-xs text-slate-500">{{ 'board.readOnly' | translate }}</p>
          }
        </div>
        <button
          type="button"
          class="btn btn-secondary"
          aria-controls="board-sidebar"
          [attr.aria-expanded]="sidebarOpen()"
          (click)="sidebarOpen.set(!sidebarOpen())"
        >
          {{ 'board.options' | translate }}
          <span class="rounded-full bg-slate-100 px-2 text-xs text-slate-600">{{ board.members.length }}</span>
        </button>
      </div>

      <!-- Lanes share the full width; below their minimum width the board scrolls sideways. -->
      <div class="flex items-start gap-4 overflow-x-auto pb-4" cdkDropListGroup>
        @for (column of board.columns; track column.id) {
          <section class="flex min-w-64 flex-1 flex-col rounded-xl bg-slate-200/70 p-3">
            <header class="mb-2 flex items-center justify-between gap-2">
              <h2 class="text-sm font-semibold">
                {{ column.name }} <span class="font-normal text-slate-500">{{ column.cards.length }}</span>
              </h2>
              @if (canEdit()) {
                <button type="button" class="text-slate-400 hover:text-red-600" [attr.aria-label]="'board.deleteColumn' | translate" (click)="deleteColumn(column)">&times;</button>
              }
            </header>

            <ul
              class="flex min-h-12 flex-col gap-2"
              cdkDropList
              [cdkDropListData]="column"
              [cdkDropListDisabled]="!canEdit()"
              (cdkDropListDropped)="drop($event)"
            >
              @for (card of column.cards; track card.id) {
                <li cdkDrag [cdkDragData]="card" class="group rounded-lg bg-white p-3 text-sm shadow-sm" [class.cursor-grab]="canEdit()">
                  <div class="flex items-start justify-between gap-2">
                    <span class="break-words">{{ card.title }}</span>
                    @if (canEdit()) {
                      <button type="button" class="text-slate-300 opacity-0 group-hover:opacity-100 hover:text-red-600" [attr.aria-label]="'board.deleteCard' | translate" (click)="deleteCard(card)">&times;</button>
                    }
                  </div>
                  @if (card.assignee_id && memberNames().get(card.assignee_id); as assignee) {
                    <p class="mt-2 text-xs text-slate-500">{{ assignee }}</p>
                  }
                </li>
              }
            </ul>

            @if (canEdit()) {
              @if (addingCardTo() === column.id) {
                <form class="mt-2 space-y-2" (submit)="addCard($event, column)" novalidate>
                  <input class="input" appAutofocus [placeholder]="'board.cardTitle' | translate" [formField]="cardForm.title" (keydown.escape)="addingCardTo.set(null)" />
                  <div class="flex gap-2">
                    <button type="submit" class="btn btn-primary">{{ 'board.add' | translate }}</button>
                    <button type="button" class="btn btn-secondary" (click)="addingCardTo.set(null)">{{ 'board.cancel' | translate }}</button>
                  </div>
                </form>
              } @else {
                <button type="button" class="mt-2 rounded-md px-2 py-1.5 text-left text-sm text-slate-600 hover:bg-slate-300/50" (click)="openCardForm(column.id)">
                  + {{ 'board.addCard' | translate }}
                </button>
              }
            }
          </section>
        }

        @if (canEdit()) {
          <form class="w-56 shrink-0 space-y-2 rounded-xl bg-slate-200/40 p-3" (submit)="addColumn($event, board)" novalidate>
            <input class="input" [placeholder]="'board.columnName' | translate" [formField]="columnForm.name" />
            <button type="submit" class="btn btn-secondary w-full">+ {{ 'board.addColumn' | translate }}</button>
          </form>
        }
      </div>

      <!-- Board options: collapsed by default, slides in from the right. -->
      @if (sidebarOpen()) {
        <div class="fixed inset-0 z-40 bg-slate-900/20" aria-hidden="true" (click)="sidebarOpen.set(false)"></div>
      }
      <aside
        id="board-sidebar"
        class="fixed inset-y-0 right-0 z-50 flex w-full max-w-sm flex-col gap-4 overflow-y-auto border-l border-slate-200 bg-slate-50 p-4 shadow-xl transition-transform duration-200"
        [class.translate-x-full]="!sidebarOpen()"
        [attr.inert]="sidebarOpen() ? null : ''"
        [attr.aria-label]="'board.options' | translate"
      >
        <div class="flex items-center justify-between">
          <h2 class="font-semibold">{{ 'board.options' | translate }}</h2>
          <button type="button" class="rounded p-1 text-xl leading-none text-slate-500 hover:bg-slate-200" [attr.aria-label]="'board.close' | translate" (click)="sidebarOpen.set(false)">&times;</button>
        </div>

        <app-members-panel [board]="board" (changed)="boardRes.reload()" (left)="router.navigateByUrl('/boards')" />

        @if (board.my_role === 'owner') {
          <button type="button" class="btn btn-danger w-full" (click)="deleteBoard(board)">{{ 'boards.delete' | translate }}</button>
        }
      </aside>
    }
  `,
})
export class BoardPage {
  private readonly api = inject(BoardsApi);
  private readonly translate = inject(TranslateService);
  protected readonly router = inject(Router);

  /** Route param, bound via withComponentInputBinding(). */
  readonly id = input.required({ transform: numberAttribute });

  protected readonly boardRes = rxResource({
    params: () => this.id(),
    stream: ({ params: id }) => this.api.get(id),
  });
  protected readonly board = computed(() => (this.boardRes.hasValue() ? this.boardRes.value() : null));
  protected readonly canEdit = computed(() => ['owner', 'editor'].includes(this.board()?.my_role ?? ''));
  protected readonly memberNames = computed(() => new Map(this.board()?.members.map((m) => [m.user_id, m.name]) ?? []));
  protected readonly error = signal<TranslatedError | null>(null);
  protected readonly sidebarOpen = signal(false);

  protected readonly addingCardTo = signal<number | null>(null);
  protected readonly cardModel = signal({ title: '' });
  protected readonly cardForm = form(this.cardModel, (path) => {
    required(path.title);
    maxLength(path.title, 200);
  });

  protected readonly columnModel = signal({ name: '' });
  protected readonly columnForm = form(this.columnModel, (path) => {
    required(path.name);
    maxLength(path.name, 100);
  });

  protected errorKey(err: unknown): string {
    return apiError(err).key;
  }

  protected openCardForm(columnId: number): void {
    this.cardModel.set({ title: '' });
    this.cardForm().reset();
    this.addingCardTo.set(columnId);
  }

  /** Optimistic drag & drop: update the UI immediately, reload from the server if the move fails. */
  protected drop(event: CdkDragDrop<BoardColumn, BoardColumn, Card>): void {
    const board = this.board();
    const from = event.previousContainer.data;
    const to = event.container.data;
    if (!board || (from.id === to.id && event.previousIndex === event.currentIndex)) {
      return;
    }

    const columns = board.columns.map((c) => ({ ...c, cards: [...c.cards] }));
    const source = columns.find((c) => c.id === from.id)!;
    const target = columns.find((c) => c.id === to.id)!;
    if (source === target) {
      moveItemInArray(target.cards, event.previousIndex, event.currentIndex);
    } else {
      transferArrayItem(source.cards, target.cards, event.previousIndex, event.currentIndex);
    }
    target.cards[event.currentIndex] = { ...event.item.data, column_id: target.id };
    this.boardRes.value.set({ ...board, columns });

    this.persist(this.api.moveCard(event.item.data.id, target.id, event.currentIndex));
  }

  protected async addCard(event: Event, column: BoardColumn): Promise<void> {
    event.preventDefault();
    await submit(this.cardForm, async () => {
      await this.persist(this.api.addCard(column.id, { title: this.cardModel().title }));
      this.openCardForm(column.id);
      return undefined;
    });
  }

  protected async addColumn(event: Event, board: Board): Promise<void> {
    event.preventDefault();
    await submit(this.columnForm, async () => {
      await this.persist(this.api.addColumn(board.id, this.columnModel().name));
      this.columnModel.set({ name: '' });
      this.columnForm().reset();
      return undefined;
    });
  }

  protected deleteCard(card: Card): void {
    void this.persist(this.api.deleteCard(card.id));
  }

  protected deleteColumn(column: BoardColumn): void {
    if (confirm(this.translate.instant('board.deleteColumnConfirm', { name: column.name }))) {
      void this.persist(this.api.deleteColumn(column.id));
    }
  }

  protected async deleteBoard(board: Board): Promise<void> {
    if (!confirm(this.translate.instant('boards.deleteConfirm', { name: board.name }))) {
      return;
    }
    try {
      await firstValueFrom(this.api.delete(board.id));
      await this.router.navigateByUrl('/boards');
    } catch (err) {
      this.error.set(apiError(err));
    }
  }

  /** Runs a mutation, then reloads the board so all collaborators' changes are picked up too. */
  private async persist(request: Observable<unknown>): Promise<void> {
    this.error.set(null);
    try {
      await firstValueFrom(request);
    } catch (err) {
      this.error.set(apiError(err));
    }
    this.boardRes.reload();
  }
}
