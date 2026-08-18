import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import {
  ActivatedRouteSnapshot,
  CanActivateFn,
  Router,
  RouterStateSnapshot,
  UrlTree,
  provideRouter,
} from '@angular/router';

import { authGuard } from './auth.guard';
import { AuthStore } from './auth.store';
import { guestGuard } from './guest.guard';
import { changePasswordPageGuard, passwordChangeGuard } from './password-change.guard';

describe('authentication guards', () => {
  const authenticated = signal(false);
  const passwordChangeRequired = signal(false);
  const authStore = {
    isAuthenticated: authenticated.asReadonly(),
    mustChangePassword: passwordChangeRequired.asReadonly(),
    restoreSession: vi.fn().mockResolvedValue(undefined),
  };

  let router: Router;

  beforeEach(() => {
    authenticated.set(false);
    passwordChangeRequired.set(false);
    authStore.restoreSession.mockClear();

    TestBed.configureTestingModule({
      providers: [provideRouter([]), { provide: AuthStore, useValue: authStore }],
    });

    router = TestBed.inject(Router);
  });

  function runGuard(guard: CanActivateFn) {
    return TestBed.runInInjectionContext(() =>
      guard({} as ActivatedRouteSnapshot, {} as RouterStateSnapshot),
    );
  }

  function expectRedirect(result: unknown, expectedUrl: string): void {
    expect(result).toBeInstanceOf(UrlTree);
    expect(router.serializeUrl(result as UrlTree)).toBe(expectedUrl);
  }

  describe('authGuard', () => {
    it('restores and allows authenticated staff', async () => {
      authenticated.set(true);

      expect(await runGuard(authGuard)).toBe(true);
      expect(authStore.restoreSession).toHaveBeenCalledOnce();
    });

    it('restores and redirects anonymous visitors to login', async () => {
      expectRedirect(await runGuard(authGuard), '/login');
      expect(authStore.restoreSession).toHaveBeenCalledOnce();
    });
  });

  describe('guestGuard', () => {
    it('allows anonymous visitors', async () => {
      expect(await runGuard(guestGuard)).toBe(true);
    });

    it('redirects authenticated staff who must change their password', async () => {
      authenticated.set(true);
      passwordChangeRequired.set(true);

      expectRedirect(await runGuard(guestGuard), '/change-password');
    });

    it('redirects other authenticated staff to the dashboard', async () => {
      authenticated.set(true);

      expectRedirect(await runGuard(guestGuard), '/app/dashboard');
    });
  });

  describe('passwordChangeGuard', () => {
    it('redirects anonymous visitors to login', async () => {
      expectRedirect(await runGuard(passwordChangeGuard), '/login');
    });

    it('redirects staff who must change their password away from /app', async () => {
      authenticated.set(true);
      passwordChangeRequired.set(true);

      expectRedirect(await runGuard(passwordChangeGuard), '/change-password');
    });

    it('allows authenticated staff without a password-change requirement', async () => {
      authenticated.set(true);

      expect(await runGuard(passwordChangeGuard)).toBe(true);
    });
  });

  describe('changePasswordPageGuard', () => {
    it('redirects anonymous visitors to login', async () => {
      expectRedirect(await runGuard(changePasswordPageGuard), '/login');
    });

    it('allows staff who must change their password', async () => {
      authenticated.set(true);
      passwordChangeRequired.set(true);

      expect(await runGuard(changePasswordPageGuard)).toBe(true);
    });

    it('redirects staff without a password-change requirement to the voluntary page', async () => {
      authenticated.set(true);
      passwordChangeRequired.set(false);

      expectRedirect(await runGuard(changePasswordPageGuard), '/app/account/password');
    });
  });
});
