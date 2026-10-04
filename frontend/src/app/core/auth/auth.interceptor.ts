import { HttpErrorResponse, HttpInterceptorFn, HttpRequest } from '@angular/common/http';
import { inject } from '@angular/core';
import { switchMap, throwError, catchError } from 'rxjs';
import { AuthService } from './auth.service';

/** Auth endpoints that must never trigger a token refresh/retry loop. */
const SESSION_ENDPOINTS = /^api\/auth\/(login|register|refresh|logout)$/;

/**
 * Adds the bearer token to API calls. On a 401 it refreshes the session once and retries;
 * if that fails the user is sent to the login page.
 */
export const authInterceptor: HttpInterceptorFn = (req, next) => {
  if (!req.url.startsWith('api/')) {
    return next(req);
  }

  const auth = inject(AuthService);
  const withToken = (request: HttpRequest<unknown>, token: string | null) =>
    token ? request.clone({ setHeaders: { Authorization: `Bearer ${token}` } }) : request;

  return next(withToken(req, auth.token)).pipe(
    catchError((error: unknown) => {
      if (!(error instanceof HttpErrorResponse) || error.status !== 401 || SESSION_ENDPOINTS.test(req.url)) {
        return throwError(() => error);
      }
      return auth.refresh().pipe(
        switchMap((token) => {
          if (token === null) {
            auth.sessionExpired();
            return throwError(() => error);
          }
          return next(withToken(req, token));
        }),
      );
    }),
  );
};
