import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';

import { ResultsApiService } from './results-api.service';

describe('ResultsApiService', () => {
  let api: ResultsApiService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(ResultsApiService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('sends the canonical FINISHED session-history query', () => {
    api
      .listSessions({
        pageIndex: 2,
        pageSize: 20,
        search: '  Petlje  ',
        status: 'FINISHED',
        quizId: 7,
        sort: 'QUIZ_TITLE_ASC',
      })
      .subscribe();

    const request = http.expectOne((candidate) => candidate.url === '/api/sessions');
    expect(request.request.method).toBe('GET');
    expect(request.request.params.get('pageIndex')).toBe('2');
    expect(request.request.params.get('pageSize')).toBe('20');
    expect(request.request.params.get('status')).toBe('FINISHED');
    expect(request.request.params.get('search')).toBe('Petlje');
    expect(request.request.params.get('quizId')).toBe('7');
    expect(request.request.params.get('sort')).toBe('QUIZ_TITLE_ASC');
    request.flush({
      sessions: [],
      pagination: { pageIndex: 2, pageSize: 20, totalItems: 0, totalPages: 0 },
    });
  });

  it('loads canonical reports and statistics only through detail endpoints', () => {
    api.getSessionReport(4).subscribe();
    expect(http.expectOne('/api/sessions/4/results').request.method).toBe('GET');

    api.getQuizStatistics(5).subscribe();
    expect(http.expectOne('/api/quizzes/5/statistics').request.method).toBe('GET');

    api.getStudentStatistics(6).subscribe();
    expect(http.expectOne('/api/students/6/statistics').request.method).toBe('GET');
  });

  it('uses zero-based student performance pagination and an optional quiz filter', () => {
    api.listStudentSessions(6, 0, 5, 9).subscribe();
    const request = http.expectOne(
      '/api/students/6/statistics/sessions?pageIndex=0&pageSize=5&quizId=9',
    );
    expect(request.request.method).toBe('GET');
    request.flush({
      sessions: [],
      pagination: { pageIndex: 0, pageSize: 5, totalItems: 0, totalPages: 0 },
    });
  });
});
