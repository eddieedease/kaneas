import { Routes } from '@angular/router';
import { adminGuard, authGuard, guestGuard } from './core/auth/auth.guards';

export const routes: Routes = [
  { path: '', pathMatch: 'full', redirectTo: 'boards' },
  {
    path: '',
    loadComponent: () => import('./layout/auth-layout').then((m) => m.AuthLayout),
    canActivate: [guestGuard],
    children: [
      { path: 'login', title: 'Kaneas', loadComponent: () => import('./features/auth/login.page').then((m) => m.LoginPage) },
      { path: 'register', title: 'Kaneas', loadComponent: () => import('./features/auth/register.page').then((m) => m.RegisterPage) },
      { path: 'verify-email', title: 'Kaneas', loadComponent: () => import('./features/auth/verify-email.page').then((m) => m.VerifyEmailPage) },
    ],
  },
  {
    path: '',
    loadComponent: () => import('./layout/shell').then((m) => m.Shell),
    canActivate: [authGuard],
    children: [
      { path: 'boards', title: 'Kaneas', loadComponent: () => import('./features/boards/board-list.page').then((m) => m.BoardListPage) },
      { path: 'boards/:id', title: 'Kaneas', loadComponent: () => import('./features/boards/board.page').then((m) => m.BoardPage) },
      {
        path: 'admin',
        canActivate: [adminGuard],
        children: [
          { path: '', pathMatch: 'full', redirectTo: 'users' },
          { path: 'users', title: 'Kaneas', loadComponent: () => import('./features/admin/admin-users.page').then((m) => m.AdminUsersPage) },
          { path: 'settings', title: 'Kaneas', loadComponent: () => import('./features/admin/admin-settings.page').then((m) => m.AdminSettingsPage) },
        ],
      },
    ],
  },
  { path: '**', redirectTo: '' },
];
