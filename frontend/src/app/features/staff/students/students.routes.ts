import { Routes } from '@angular/router';

export const studentsRoutes: Routes = [
  {
    path: '',
    pathMatch: 'full',
    loadComponent: () =>
      import('./pages/students-list/students-list-page').then(
        ({ StudentsListPage }) => StudentsListPage,
      ),
  },
  {
    path: 'new',
    pathMatch: 'full',
    loadComponent: () =>
      import('./pages/student-create/student-create-page').then(
        ({ StudentCreatePage }) => StudentCreatePage,
      ),
  },
  {
    path: ':id',
    loadComponent: () =>
      import('./pages/student-details/student-details-page').then(
        ({ StudentDetailsPage }) => StudentDetailsPage,
      ),
  },
];
