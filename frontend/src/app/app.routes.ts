import { Routes } from '@angular/router';

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
    // TODO: Replace this temporary redirect when the staff login feature is implemented.
    redirectTo: '',
  },
  {
    path: 'join/:gamePin',
    pathMatch: 'full',
    // TODO: Replace this temporary redirect when the participant join feature is implemented.
    redirectTo: '',
  },
];
