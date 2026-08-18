import { provideHttpClient } from '@angular/common/http';
import { HttpErrorResponse } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';

import { StaffUser } from './auth.models';
import { AuthStore } from './auth.store';

describe('AuthStore', () => {
  const normalUser: StaffUser = {
    id: 7,
    name: 'Ana Anić',
    email: 'ana@example.com',
    role: 'TEACHER',
    mustChangePassword: false,
  };

  const requiredPasswordUser: StaffUser = {
    ...normalUser,
    id: 8,
    name: 'Admin User',
    role: 'ADMIN',
    mustChangePassword: true,
  };

  let authStore: AuthStore;
  let httpTesting: HttpTestingController;
  let router: Router;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([])],
    });

    authStore = TestBed.inject(AuthStore);
    httpTesting = TestBed.inject(HttpTestingController);
    router = TestBed.inject(Router);
  });

  afterEach(() => {
    httpTesting.verify();
  });

  async function restoreUser(user: StaffUser): Promise<void> {
    const restoration = authStore.restoreSession();
    httpTesting.expectOne('/api/auth/me').flush({ user });
    await restoration;
  }

  it('uses /me as the canonical user after login and routes normal staff to the dashboard', async () => {
    const navigate = vi.spyOn(router, 'navigateByUrl').mockResolvedValue(true);
    const loginUser = { ...normalUser, name: 'Stale login response' };
    const login = authStore.login('  ana@example.com  ', 'Unchanged password ');

    const loginRequest = httpTesting.expectOne('/api/auth/login');
    expect(loginRequest.request.method).toBe('POST');
    expect(loginRequest.request.body).toEqual({
      email: 'ana@example.com',
      password: 'Unchanged password ',
    });
    loginRequest.flush({ expiresInSeconds: 3600, user: loginUser });

    await Promise.resolve();
    const meRequest = httpTesting.expectOne('/api/auth/me');
    expect(meRequest.request.method).toBe('GET');
    meRequest.flush({ user: normalUser });

    await expect(login).resolves.toEqual(normalUser);
    expect(authStore.user()).toEqual(normalUser);
    expect(authStore.status()).toBe('authenticated');
    expect(navigate).toHaveBeenCalledWith('/app/dashboard');
  });

  it('routes a user who must change their password to the required screen', async () => {
    const navigate = vi.spyOn(router, 'navigateByUrl').mockResolvedValue(true);
    const login = authStore.login('admin@example.com', 'Password1!');

    httpTesting
      .expectOne('/api/auth/login')
      .flush({ expiresInSeconds: 3600, user: requiredPasswordUser });
    await Promise.resolve();
    httpTesting.expectOne('/api/auth/me').flush({ user: requiredPasswordUser });

    await login;
    expect(authStore.mustChangePassword()).toBe(true);
    expect(navigate).toHaveBeenCalledWith('/change-password');
  });

  it('does not retain a user after a failed login', async () => {
    await restoreUser(normalUser);
    expect(authStore.isAuthenticated()).toBe(true);

    const login = authStore.login('ana@example.com', 'incorrect');
    httpTesting
      .expectOne('/api/auth/login')
      .flush({ error: 'Invalid credentials.' }, { status: 401, statusText: 'Unauthorized' });

    await expect(login).rejects.toBeInstanceOf(HttpErrorResponse);
    expect(authStore.user()).toBeNull();
    expect(authStore.status()).toBe('unauthenticated');
  });

  it('treats an anonymous session restoration as a normal unauthenticated state', async () => {
    const restoration = authStore.restoreSession();
    httpTesting
      .expectOne('/api/auth/me')
      .flush({ error: 'Authentication required.' }, { status: 401, statusText: 'Unauthorized' });

    await expect(restoration).resolves.toBeUndefined();
    expect(authStore.status()).toBe('unauthenticated');
  });

  it('shares one session restoration across simultaneous staff guards', async () => {
    const first = authStore.restoreSession();
    const second = authStore.restoreSession();

    expect(first).toBe(second);
    httpTesting.expectOne('/api/auth/me').flush({ user: normalUser });
    await expect(Promise.all([first, second])).resolves.toEqual([undefined, undefined]);
  });

  it('clears authenticated state only after logout succeeds', async () => {
    const navigate = vi.spyOn(router, 'navigateByUrl').mockResolvedValue(true);
    await restoreUser(normalUser);
    const logout = authStore.logout();

    const request = httpTesting.expectOne('/api/auth/logout');
    expect(request.request.method).toBe('POST');
    request.flush(null, { status: 204, statusText: 'No Content' });

    await logout;
    expect(authStore.user()).toBeNull();
    expect(authStore.status()).toBe('unauthenticated');
    expect(navigate).toHaveBeenCalledWith('/login');
  });

  it('clears the session and exposes a safe notice after password change', async () => {
    const navigate = vi.spyOn(router, 'navigateByUrl').mockResolvedValue(true);
    await restoreUser(requiredPasswordUser);
    const change = authStore.changePassword({
      currentPassword: 'OldPass1!',
      newPassword: 'NewPass2!',
      newPasswordConfirmation: 'NewPass2!',
    });

    const request = httpTesting.expectOne('/api/auth/change-password');
    expect(request.request.body).toEqual({
      currentPassword: 'OldPass1!',
      newPassword: 'NewPass2!',
      newPasswordConfirmation: 'NewPass2!',
    });
    request.flush(null, { status: 204, statusText: 'No Content' });

    await change;
    expect(authStore.status()).toBe('unauthenticated');
    expect(authStore.notice()).toBe('password-changed');
    expect(navigate).toHaveBeenCalledWith('/login');
  });
});
