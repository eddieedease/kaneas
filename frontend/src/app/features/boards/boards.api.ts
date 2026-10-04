import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { Board, BoardColumn, BoardInvitation, BoardMember, BoardRole, BoardSummary, Card } from '../../core/models';

export type InviteRole = Exclude<BoardRole, 'owner'>;

export interface AddMemberResult {
  type: 'member' | 'invitation';
  mailed: boolean;
  members?: BoardMember[];
  invitations?: BoardInvitation[];
}

@Injectable({ providedIn: 'root' })
export class BoardsApi {
  private readonly http = inject(HttpClient);

  list(): Observable<BoardSummary[]> {
    return this.http.get<BoardSummary[]>('api/boards');
  }

  get(id: number): Observable<Board> {
    return this.http.get<Board>(`api/boards/${id}`);
  }

  create(data: { name: string; description?: string }): Observable<Board> {
    return this.http.post<Board>('api/boards', data);
  }

  update(id: number, data: { name?: string; description?: string | null }): Observable<Board> {
    return this.http.patch<Board>(`api/boards/${id}`, data);
  }

  delete(id: number): Observable<void> {
    return this.http.delete<void>(`api/boards/${id}`);
  }

  addColumn(boardId: number, name: string): Observable<BoardColumn> {
    return this.http.post<BoardColumn>(`api/boards/${boardId}/columns`, { name });
  }

  renameColumn(columnId: number, name: string): Observable<BoardColumn> {
    return this.http.patch<BoardColumn>(`api/columns/${columnId}`, { name });
  }

  reorderColumns(boardId: number, columnIds: number[]): Observable<void> {
    return this.http.put<void>(`api/boards/${boardId}/columns/order`, { column_ids: columnIds });
  }

  deleteColumn(columnId: number): Observable<void> {
    return this.http.delete<void>(`api/columns/${columnId}`);
  }

  addCard(columnId: number, data: { title: string; description?: string; assignee_id?: number }): Observable<Card> {
    return this.http.post<Card>(`api/columns/${columnId}/cards`, data);
  }

  updateCard(cardId: number, data: Partial<Pick<Card, 'title' | 'description' | 'assignee_id'>>): Observable<Card> {
    return this.http.patch<Card>(`api/cards/${cardId}`, data);
  }

  moveCard(cardId: number, columnId: number, position: number): Observable<Card> {
    return this.http.post<Card>(`api/cards/${cardId}/move`, { column_id: columnId, position });
  }

  deleteCard(cardId: number): Observable<void> {
    return this.http.delete<void>(`api/cards/${cardId}`);
  }

  addMember(boardId: number, email: string, role: InviteRole): Observable<AddMemberResult> {
    return this.http.post<AddMemberResult>(`api/boards/${boardId}/members`, { email, role });
  }

  updateMember(boardId: number, userId: number, role: InviteRole): Observable<BoardMember[]> {
    return this.http.patch<BoardMember[]>(`api/boards/${boardId}/members/${userId}`, { role });
  }

  removeMember(boardId: number, userId: number): Observable<void> {
    return this.http.delete<void>(`api/boards/${boardId}/members/${userId}`);
  }

  deleteInvitation(boardId: number, invitationId: number): Observable<void> {
    return this.http.delete<void>(`api/boards/${boardId}/invitations/${invitationId}`);
  }
}
