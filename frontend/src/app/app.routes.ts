import { Routes } from '@angular/router';

import { adminGuard } from './core/auth/admin.guard';
import { authGuard } from './core/auth/auth.guard';
import { guestGuard } from './core/auth/guest.guard';
import { changePasswordPageGuard, passwordChangeGuard } from './core/auth/password-change.guard';

export const routes: Routes = [
  {
    path: '',
    pathMatch: 'full',
    loadComponent: () =>
      import('./features/public/join/join-page').then(({ JoinPage }) => JoinPage),
  },
  {
    path: 'login',
    pathMatch: 'full',
    canActivate: [guestGuard],
    loadComponent: () =>
      import('./features/public/login/login-page').then(({ LoginPage }) => LoginPage),
  },
  {
    path: 'change-password',
    pathMatch: 'full',
    canActivate: [changePasswordPageGuard],
    loadComponent: () =>
      import('./features/public/change-password/change-password-page').then(
        ({ ChangePasswordPage }) => ChangePasswordPage,
      ),
  },
  {
    path: 'app',
    canActivate: [authGuard, passwordChangeGuard],
    canActivateChild: [authGuard, passwordChangeGuard],
    loadComponent: () =>
      import('./core/layout/staff-shell/staff-shell').then(({ StaffShell }) => StaffShell),
    children: [
      {
        path: '',
        pathMatch: 'full',
        redirectTo: 'dashboard',
      },
      {
        path: 'dashboard',
        loadComponent: () =>
          import('./features/staff/dashboard/dashboard-page').then(
            ({ DashboardPage }) => DashboardPage,
          ),
      },
      {
        path: 'sessions/:sessionId',
        loadComponent: () =>
          import('./features/staff/play/pages/quiz-lobby/quiz-lobby-page').then(
            ({ QuizLobbyPage }) => QuizLobbyPage,
          ),
      },
      {
        path: 'account/password',
        loadComponent: () =>
          import('./features/staff/account/password/account-password-page').then(
            ({ AccountPasswordPage }) => AccountPasswordPage,
          ),
      },
      {
        path: 'students',
        loadChildren: () =>
          import('./features/staff/students/students.routes').then(
            ({ studentsRoutes }) => studentsRoutes,
          ),
      },
      {
        path: 'quizzes',
        loadChildren: () =>
          import('./features/staff/quizzes/quizzes.routes').then(
            ({ quizzesRoutes }) => quizzesRoutes,
          ),
      },
      {
        path: 'results',
        loadChildren: () =>
          import('./features/staff/results/results.routes').then(
            ({ resultsRoutes }) => resultsRoutes,
          ),
      },
      {
        path: 'users',
        canActivate: [adminGuard],
        canActivateChild: [adminGuard],
        loadChildren: () =>
          import('./features/staff/users/users.routes').then(({ usersRoutes }) => usersRoutes),
      },
    ],
  },
  {
    path: 'join/:gamePin',
    pathMatch: 'full',
    // TODO: Replace this temporary redirect when the participant join feature is implemented.
    redirectTo: '',
  },
];
