import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { AdminUser, MailSettings, SystemRole } from '../../core/models';

export interface AppSettings {
  allow_registration: boolean;
}

/** Mail settings update; omit `password` (or null) to keep the stored password. */
export type MailSettingsUpdate = Omit<MailSettings, 'has_password'> & { password?: string | null };

@Injectable({ providedIn: 'root' })
export class AdminApi {
  private readonly http = inject(HttpClient);

  users(): Observable<AdminUser[]> {
    return this.http.get<AdminUser[]>('api/admin/users');
  }

  updateUser(id: number, data: { role?: SystemRole; is_active?: boolean }): Observable<AdminUser> {
    return this.http.patch<AdminUser>(`api/admin/users/${id}`, data);
  }

  deleteUser(id: number): Observable<void> {
    return this.http.delete<void>(`api/admin/users/${id}`);
  }

  settings(): Observable<AppSettings> {
    return this.http.get<AppSettings>('api/admin/settings');
  }

  updateSettings(data: Partial<AppSettings>): Observable<AppSettings> {
    return this.http.patch<AppSettings>('api/admin/settings', data);
  }

  mailSettings(): Observable<MailSettings> {
    return this.http.get<MailSettings>('api/admin/settings/mail');
  }

  updateMailSettings(data: MailSettingsUpdate): Observable<MailSettings> {
    return this.http.put<MailSettings>('api/admin/settings/mail', data);
  }

  sendTestMail(to?: string): Observable<{ sent: boolean; to: string }> {
    return this.http.post<{ sent: boolean; to: string }>('api/admin/settings/mail/test', to ? { to } : {});
  }
}
