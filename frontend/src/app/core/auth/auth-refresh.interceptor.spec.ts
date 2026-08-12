import {
  HttpClient,
  HttpErrorResponse,
  provideHttpClient,
  withInterceptors,
  withXsrfConfiguration,
} from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { firstValueFrom } from 'rxjs';

import { authRefreshInterceptor } from './auth-refresh.interceptor';
import { AuthStore } from './auth.store';

describe('authRefreshInterceptor', () => {
  let http: HttpClient;
  let httpTesting: HttpTestingController;
  let authStore: AuthStore;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(
          withInterceptors([authRefreshInterceptor]),
          withXsrfConfiguration({
            cookieName: 'codeland_csrf',
            headerName: 'X-CSRF-Token',
          }),
        ),
        provideHttpClientTesting(),
        provideRouter([]),
      ],
    });

    http = TestBed.inject(HttpClient);
    httpTesting = TestBed.inject(HttpTestingController);
    authStore = TestBed.inject(AuthStore);
    document.cookie = 'codeland_csrf=; Max-Age=0; path=/';
  });

  afterEach(() => {
    httpTesting.verify();
    document.cookie = 'codeland_csrf=; Max-Age=0; path=/';
  });

  it('refreshes after a 401 and retries the original request once', async () => {
    const result = firstValueFrom(http.get<{ value: string }>('/api/protected'));

    httpTesting
      .expectOne('/api/protected')
      .flush({ error: 'Authentication required.' }, { status: 401, statusText: 'Unauthorized' });
    httpTesting.expectOne('/api/auth/refresh').flush({ expiresInSeconds: 3600 });

    const retry = httpTesting.expectOne('/api/protected');
    expect(retry.request.method).toBe('GET');
    retry.flush({ value: 'restored' });

    await expect(result).resolves.toEqual({ value: 'restored' });
  });

  it('does not refresh a login 401', async () => {
    const result = firstValueFrom(
      http.post('/api/auth/login', { email: 'staff@example.com', password: 'wrong' }),
    );

    httpTesting
      .expectOne('/api/auth/login')
      .flush({ error: 'Invalid credentials.' }, { status: 401, statusText: 'Unauthorized' });

    await expect(result).rejects.toBeInstanceOf(HttpErrorResponse);
    httpTesting.expectNone('/api/auth/refresh');
  });

  it('does not recursively refresh a refresh 401', async () => {
    const result = firstValueFrom(http.post('/api/auth/refresh', null));

    httpTesting
      .expectOne('/api/auth/refresh')
      .flush(
        { error: 'Refresh token is invalid or expired.' },
        { status: 401, statusText: 'Unauthorized' },
      );

    await expect(result).rejects.toBeInstanceOf(HttpErrorResponse);
    httpTesting.expectNone('/api/auth/refresh');
  });

  it('shares one refresh across concurrent 401 responses', async () => {
    const first = firstValueFrom(http.get<{ request: number }>('/api/first'));
    const second = firstValueFrom(http.get<{ request: number }>('/api/second'));

    httpTesting
      .expectOne('/api/first')
      .flush({ error: 'Authentication required.' }, { status: 401, statusText: 'Unauthorized' });
    httpTesting
      .expectOne('/api/second')
      .flush({ error: 'Authentication required.' }, { status: 401, statusText: 'Unauthorized' });

    const refreshRequests = httpTesting.match('/api/auth/refresh');
    expect(refreshRequests).toHaveLength(1);
    refreshRequests[0].flush({ expiresInSeconds: 3600 });

    httpTesting.expectOne('/api/first').flush({ request: 1 });
    httpTesting.expectOne('/api/second').flush({ request: 2 });

    await expect(Promise.all([first, second])).resolves.toEqual([{ request: 1 }, { request: 2 }]);
  });

  it('does not attempt a second refresh when the retried request returns 401', async () => {
    const result = firstValueFrom(http.get('/api/protected'));

    httpTesting
      .expectOne('/api/protected')
      .flush({ error: 'Authentication required.' }, { status: 401, statusText: 'Unauthorized' });
    httpTesting.expectOne('/api/auth/refresh').flush({ expiresInSeconds: 3600 });
    httpTesting
      .expectOne('/api/protected')
      .flush({ error: 'Authentication required.' }, { status: 401, statusText: 'Unauthorized' });

    await expect(result).rejects.toBeInstanceOf(HttpErrorResponse);
    httpTesting.expectNone('/api/auth/refresh');
    expect(authStore.status()).toBe('unauthenticated');
  });

  it('lets Angular apply the rotated XSRF token to a retried mutation', async () => {
    document.cookie = 'codeland_csrf=old-token; path=/';
    const result = firstValueFrom(http.post<{ saved: boolean }>('/api/protected', { value: 1 }));

    const initialRequest = httpTesting.expectOne('/api/protected');
    expect(initialRequest.request.headers.get('X-CSRF-Token')).toBe('old-token');
    initialRequest.flush(
      { error: 'Authentication required.' },
      { status: 401, statusText: 'Unauthorized' },
    );

    const refreshRequest = httpTesting.expectOne('/api/auth/refresh');
    document.cookie = 'codeland_csrf=new-token; path=/';
    refreshRequest.flush({ expiresInSeconds: 3600 });

    const retriedRequest = httpTesting.expectOne('/api/protected');
    expect(retriedRequest.request.headers.get('X-CSRF-Token')).toBe('new-token');
    retriedRequest.flush({ saved: true });

    await expect(result).resolves.toEqual({ saved: true });
  });

  it('clears authentication state when refresh fails', async () => {
    const result = firstValueFrom(http.get('/api/protected'));

    httpTesting
      .expectOne('/api/protected')
      .flush({ error: 'Authentication required.' }, { status: 401, statusText: 'Unauthorized' });
    httpTesting
      .expectOne('/api/auth/refresh')
      .flush(
        { error: 'Refresh token is invalid or expired.' },
        { status: 401, statusText: 'Unauthorized' },
      );

    await expect(result).rejects.toBeInstanceOf(HttpErrorResponse);
    expect(authStore.status()).toBe('unauthenticated');
  });
});
