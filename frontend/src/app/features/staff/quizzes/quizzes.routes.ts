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
  {
    path: 'new',
    pathMatch: 'full',
    loadComponent: () =>
      import('./pages/quiz-create/quiz-create-page').then(({ QuizCreatePage }) => QuizCreatePage),
  },
  {
    path: ':quizId/questions/new',
    pathMatch: 'full',
    loadComponent: () =>
      import('./pages/question-editor/question-editor-page').then(
        ({ QuestionEditorPage }) => QuestionEditorPage,
      ),
  },
  {
    path: ':quizId/questions/:questionId',
    pathMatch: 'full',
    loadComponent: () =>
      import('./pages/question-editor/question-editor-page').then(
        ({ QuestionEditorPage }) => QuestionEditorPage,
      ),
  },
  {
    path: ':id',
    loadComponent: () =>
      import('./pages/quiz-details/quiz-details-page').then(
        ({ QuizDetailsPage }) => QuizDetailsPage,
      ),
  },
];
