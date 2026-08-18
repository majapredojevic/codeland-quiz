import { Route } from '@angular/router';

import { adminGuard } from './core/auth/admin.guard';
import { authGuard } from './core/auth/auth.guard';
import { passwordChangeGuard } from './core/auth/password-change.guard';
import { StaffShell } from './core/layout/staff-shell/staff-shell';
import { ChangePasswordPage } from './features/public/change-password/change-password-page';
import { JoinPage } from './features/public/join/join-page';
import { PlayerPage } from './features/public/player/player-page';
import { AccountPasswordPage } from './features/staff/account/password/account-password-page';
import { QuizLobbyPage } from './features/staff/play/pages/quiz-lobby/quiz-lobby-page';
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

describe('staff quizzes route structure', () => {
  it('lazy-loads the quiz library for all staff behind the existing staff guards', async () => {
    const staffRoute = routes.find(({ path }) => path === 'app');
    const quizzesRoute = staffRoute?.children?.find(({ path }) => path === 'quizzes');

    expect(quizzesRoute).toBeDefined();
    expect(quizzesRoute?.canActivate).toBeUndefined();
    expect(quizzesRoute?.canActivateChild).toBeUndefined();
    expect(staffRoute?.canActivate).toEqual([authGuard, passwordChangeGuard]);
    expect(staffRoute?.canActivateChild).toEqual([authGuard, passwordChangeGuard]);

    const childRoutes = (await quizzesRoute?.loadChildren?.()) as Route[];
    expect(childRoutes.map(({ path }) => path)).toEqual([
      '',
      'new',
      ':quizId/questions/new',
      ':quizId/questions/:questionId',
      ':id',
    ]);
  });
});

describe('staff play route structure', () => {
  it('lazy-loads the teacher lobby behind the existing staff guards', async () => {
    const staffRoute = routes.find(({ path }) => path === 'app');
    const lobbyRoute = staffRoute?.children?.find(({ path }) => path === 'sessions/:sessionId');

    expect(lobbyRoute).toBeDefined();
    expect(staffRoute?.canActivate).toEqual([authGuard, passwordChangeGuard]);
    expect(staffRoute?.canActivateChild).toEqual([authGuard, passwordChangeGuard]);
    expect(await lobbyRoute?.loadComponent?.()).toBe(QuizLobbyPage);
  });
});

describe('public player route structure', () => {
  it('keeps PIN entry and player gameplay outside the staff shell', async () => {
    const joinRoute = routes.find(({ path }) => path === 'join');
    const playerRoute = routes.find(({ path }) => path === 'join/:gamePin');
    const staffRoute = routes.find(({ path }) => path === 'app');

    expect(await joinRoute?.loadComponent?.()).toBe(JoinPage);
    expect(await playerRoute?.loadComponent?.()).toBe(PlayerPage);
    expect(joinRoute?.canActivate).toBeUndefined();
    expect(playerRoute?.canActivate).toBeUndefined();
    expect(staffRoute?.children?.some(({ path }) => path === 'join/:gamePin')).toBe(false);
  });
});

describe('staff results route structure', () => {
  it('lazy-loads every Results view inside the guarded StaffShell', async () => {
    const staffRoute = routes.find(({ path }) => path === 'app');
    const resultsRoute = staffRoute?.children?.find(({ path }) => path === 'results');

    expect(resultsRoute).toBeDefined();
    expect(resultsRoute?.canActivate).toBeUndefined();
    expect(staffRoute?.canActivate).toEqual([authGuard, passwordChangeGuard]);
    expect(staffRoute?.canActivateChild).toEqual([authGuard, passwordChangeGuard]);

    const childRoutes = (await resultsRoute?.loadChildren?.()) as Route[];
    expect(childRoutes.map(({ path }) => path)).toEqual([
      '',
      'sessions/:id',
      'quizzes/:id',
      'students/:id',
    ]);
  });
});
