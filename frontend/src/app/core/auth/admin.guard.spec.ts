import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import {
  ActivatedRouteSnapshot,
  Router,
  RouterStateSnapshot,
  UrlTree,
  provideRouter,
} from '@angular/router';

import { adminGuard } from './admin.guard';
import { AuthStore } from './auth.store';

@Component({ template: '' })
class EmptyPage {}

describe('adminGuard', () => {
  const authenticated = signal(false);
  const administrator = signal(false);
  const authStore = {
    isAuthenticated: authenticated.asReadonly(),
    isAdmin: administrator.asReadonly(),
  };

  let router: Router;

  beforeEach(() => {
    authenticated.set(false);
    administrator.set(false);

    TestBed.configureTestingModule({
      providers: [
        provideRouter([
          { path: 'login', component: EmptyPage },
          { path: 'app/dashboard', component: EmptyPage },
          { path: 'app/users', canActivate: [adminGuard], component: EmptyPage },
        ]),
        { provide: AuthStore, useValue: authStore },
      ],
    });

    router = TestBed.inject(Router);
  });

  function runGuard() {
    return TestBed.runInInjectionContext(() =>
      adminGuard({} as ActivatedRouteSnapshot, {} as RouterStateSnapshot),
    );
  }

  function expectRedirect(result: unknown, expectedUrl: string): void {
    expect(result).toBeInstanceOf(UrlTree);
    expect(router.serializeUrl(result as UrlTree)).toBe(expectedUrl);
  }

  it('allows authenticated administrators', () => {
    authenticated.set(true);
    administrator.set(true);

    expect(runGuard()).toBe(true);
  });

  it('blocks an authenticated teacher who enters an admin URL directly', async () => {
    authenticated.set(true);

    await expect(router.navigateByUrl('/app/users')).resolves.toBe(true);

    expect(router.url).toBe('/app/dashboard');
  });

  it('redirects anonymous visitors to login', () => {
    expectRedirect(runGuard(), '/login');
  });
});
