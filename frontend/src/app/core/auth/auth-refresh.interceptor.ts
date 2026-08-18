import {
  HttpClient,
  HttpContextToken,
  HttpErrorResponse,
  HttpInterceptorFn,
} from '@angular/common/http';
import { inject } from '@angular/core';
import { catchError, switchMap, throwError } from 'rxjs';

import { AuthStore } from './auth.store';

const AUTH_REQUEST_RETRIED = new HttpContextToken(() => false);
const REFRESH_EXCLUDED_PATHS = new Set([
  '/api/auth/login',
  '/api/auth/logout',
  '/api/auth/refresh',
]);
const PUBLIC_PLAYER_API_PREFIX = '/api/game/';
const XSRF_HEADER_NAME = 'X-CSRF-Token';

export const authRefreshInterceptor: HttpInterceptorFn = (request, next) => {
  const requestPath = request.url.split('?', 1)[0];

  if (
    !requestPath.startsWith('/api/') ||
    requestPath.startsWith(PUBLIC_PLAYER_API_PREFIX) ||
    REFRESH_EXCLUDED_PATHS.has(requestPath) ||
    request.context.get(AUTH_REQUEST_RETRIED)
  ) {
    return next(request);
  }

  const authStore = inject(AuthStore);
  const http = inject(HttpClient);

  if (authStore.status() !== 'authenticated' && requestPath !== '/api/auth/me') {
    return next(request);
  }

  return next(request).pipe(
    catchError((error: unknown) => {
      if (!(error instanceof HttpErrorResponse) || error.status !== 401) {
        return throwError(() => error);
      }

      return authStore.refreshSession().pipe(
        switchMap(() => {
          // Refresh rotates the CSRF token too. Re-enter the full chain so Angular's
          // built-in XSRF interceptor reads the new cookie for a mutation retry.
          const retriedRequest = request.clone({
            context: request.context.set(AUTH_REQUEST_RETRIED, true),
            headers: request.headers.delete(XSRF_HEADER_NAME),
          });

          return http.request(retriedRequest).pipe(
            catchError((retryError: unknown) => {
              if (retryError instanceof HttpErrorResponse && retryError.status === 401) {
                authStore.clearAuthentication();
              }

              return throwError(() => retryError);
            }),
          );
        }),
      );
    }),
  );
};
