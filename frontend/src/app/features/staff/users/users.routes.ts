import { Routes } from '@angular/router';

export const usersRoutes: Routes = [
  {
    path: '',
    pathMatch: 'full',
    loadComponent: () =>
      import('./pages/users-list/users-list-page').then(({ UsersListPage }) => UsersListPage),
  },
  {
    path: 'new',
    pathMatch: 'full',
    loadComponent: () =>
      import('./pages/user-create/user-create-page').then(({ UserCreatePage }) => UserCreatePage),
  },
  {
    path: ':id',
    loadComponent: () =>
      import('./pages/user-details/user-details-page').then(
        ({ UserDetailsPage }) => UserDetailsPage,
      ),
  },
];
