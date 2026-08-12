import { Routes } from '@angular/router';

export const resultsRoutes: Routes = [
  {
    path: '',
    pathMatch: 'full',
    loadComponent: () =>
      import('./pages/results-home/results-home-page').then(
        ({ ResultsHomePage }) => ResultsHomePage,
      ),
  },
  {
    path: 'sessions/:id',
    loadComponent: () =>
      import('./pages/session-report/session-report-page').then(
        ({ SessionReportPage }) => SessionReportPage,
      ),
  },
  {
    path: 'quizzes/:id',
    loadComponent: () =>
      import('./pages/quiz-statistics/quiz-statistics-page').then(
        ({ QuizStatisticsPage }) => QuizStatisticsPage,
      ),
  },
  {
    path: 'students/:id',
    loadComponent: () =>
      import('./pages/student-statistics/student-statistics-page').then(
        ({ StudentStatisticsPage }) => StudentStatisticsPage,
      ),
  },
];
