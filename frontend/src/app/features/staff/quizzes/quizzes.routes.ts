import { Routes } from '@angular/router';

export const quizzesRoutes: Routes = [
  {
    path: '',
    pathMatch: 'full',
    loadComponent: () =>
      import('./pages/quiz-library/quiz-library-page').then(
        ({ QuizLibraryPage }) => QuizLibraryPage,
      ),
  },
];
