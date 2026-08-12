import { provideHttpClient, withInterceptors, withXsrfConfiguration } from '@angular/common/http';
import {
  ApplicationConfig,
  inject,
  provideAppInitializer,
  provideBrowserGlobalErrorListeners,
} from '@angular/core';
import { provideRouter } from '@angular/router';

import { routes } from './app.routes';
import { authRefreshInterceptor } from './core/auth/auth-refresh.interceptor';
import { AuthStore } from './core/auth/auth.store';

export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    provideHttpClient(
      withInterceptors([authRefreshInterceptor]),
      withXsrfConfiguration({
        cookieName: 'codeland_csrf',
        headerName: 'X-CSRF-Token',
      }),
    ),
    provideRouter(routes),
    provideAppInitializer(() => inject(AuthStore).restoreSession()),
  ],
};
