import { Route } from '@angular/router';

import { adminGuard } from './core/auth/admin.guard';
import { authGuard } from './core/auth/auth.guard';
import { passwordChangeGuard } from './core/auth/password-change.guard';
import { StaffShell } from './core/layout/staff-shell/staff-shell';
import { ChangePasswordPage } from './features/public/change-password/change-password-page';
import { AccountPasswordPage } from './features/staff/account/password/account-password-page';
import { routes } from './app.routes';

describe('password route structure', () => {
  async function loadRouteComponent(route: Route): Promise<unknown> {
    if (!route.loadComponent) {
      throw new Error(`Expected ${route.path} to lazy-load a component.`);
    }

    return await route.loadComponent();
  }

  it('renders the voluntary password page as a child of StaffShell', async () => {
    const staffRoute = routes.find(({ path }) => path === 'app');
    const accountPasswordRoute = staffRoute?.children?.find(
      ({ path }) => path === 'account/password',
    );

    expect(staffRoute).toBeDefined();
    expect(accountPasswordRoute).toBeDefined();
    expect(await loadRouteComponent(staffRoute!)).toBe(StaffShell);
    expect(await loadRouteComponent(accountPasswordRoute!)).toBe(AccountPasswordPage);
  });

  it('keeps the required password page top-level and outside StaffShell', async () => {
    const staffRoute = routes.find(({ path }) => path === 'app');
    const requiredPasswordRoute = routes.find(({ path }) => path === 'change-password');

    expect(requiredPasswordRoute).toBeDefined();
    expect(staffRoute?.children?.some(({ path }) => path === 'change-password')).toBe(false);
    expect(await loadRouteComponent(requiredPasswordRoute!)).toBe(ChangePasswordPage);
  });
});

describe('admin users route structure', () => {
  it('lazy-loads the users feature behind the administrator guard', async () => {
    const staffRoute = routes.find(({ path }) => path === 'app');
    const usersRoute = staffRoute?.children?.find(({ path }) => path === 'users');

    expect(usersRoute).toBeDefined();
    expect(usersRoute?.canActivate).toEqual([adminGuard]);
    expect(usersRoute?.canActivateChild).toEqual([adminGuard]);
    expect(usersRoute?.loadChildren).toBeTypeOf('function');
    expect(usersRoute?.loadComponent).toBeUndefined();

    const childRoutes = (await usersRoute?.loadChildren?.()) as Route[];

    expect(childRoutes.map(({ path }) => path)).toEqual(['', 'new', ':id']);
  });
});

describe('staff students route structure', () => {
  it('lazy-loads the students feature for all staff without an administrator guard', async () => {
    const staffRoute = routes.find(({ path }) => path === 'app');
    const studentsRoute = staffRoute?.children?.find(({ path }) => path === 'students');

    expect(studentsRoute).toBeDefined();
    expect(studentsRoute?.canActivate).toBeUndefined();
    expect(studentsRoute?.canActivateChild).toBeUndefined();
    expect(studentsRoute?.loadChildren).toBeTypeOf('function');
    expect(studentsRoute?.loadComponent).toBeUndefined();
    expect(staffRoute?.canActivate).toEqual([authGuard, passwordChangeGuard]);
    expect(staffRoute?.canActivateChild).toEqual([authGuard, passwordChangeGuard]);

    const childRoutes = (await studentsRoute?.loadChildren?.()) as Route[];

    expect(childRoutes.map(({ path }) => path)).toEqual(['', 'new', ':id']);
  });
});
