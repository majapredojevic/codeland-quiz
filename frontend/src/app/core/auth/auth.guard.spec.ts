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
  };

  let router: Router;

  beforeEach(() => {
    authenticated.set(false);
    passwordChangeRequired.set(false);

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
    it('allows authenticated staff', () => {
      authenticated.set(true);

      expect(runGuard(authGuard)).toBe(true);
    });

    it('redirects anonymous visitors to login', () => {
      expectRedirect(runGuard(authGuard), '/login');
    });
  });

  describe('guestGuard', () => {
    it('allows anonymous visitors', () => {
      expect(runGuard(guestGuard)).toBe(true);
    });

    it('redirects authenticated staff who must change their password', () => {
      authenticated.set(true);
      passwordChangeRequired.set(true);

      expectRedirect(runGuard(guestGuard), '/change-password');
    });

    it('redirects other authenticated staff to the dashboard', () => {
      authenticated.set(true);

      expectRedirect(runGuard(guestGuard), '/app/dashboard');
    });
  });

  describe('passwordChangeGuard', () => {
    it('redirects anonymous visitors to login', () => {
      expectRedirect(runGuard(passwordChangeGuard), '/login');
    });

    it('redirects staff who must change their password away from /app', () => {
      authenticated.set(true);
      passwordChangeRequired.set(true);

      expectRedirect(runGuard(passwordChangeGuard), '/change-password');
    });

    it('allows authenticated staff without a password-change requirement', () => {
      authenticated.set(true);

      expect(runGuard(passwordChangeGuard)).toBe(true);
    });
  });

  describe('changePasswordPageGuard', () => {
    it('redirects anonymous visitors to login', () => {
      expectRedirect(runGuard(changePasswordPageGuard), '/login');
    });

    it('allows staff who must change their password', () => {
      authenticated.set(true);
      passwordChangeRequired.set(true);

      expect(runGuard(changePasswordPageGuard)).toBe(true);
    });

    it('redirects staff without a password-change requirement to the voluntary page', () => {
      authenticated.set(true);
      passwordChangeRequired.set(false);

      expectRedirect(runGuard(changePasswordPageGuard), '/app/account/password');
    });
  });
});
