import { Route } from '@angular/router';

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
