import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { firstValueFrom } from 'rxjs';

import { QuizzesApiService } from './quizzes-api.service';
import { QuizzesListResponse } from './quizzes.models';

describe('QuizzesApiService', () => {
  let api: QuizzesApiService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(QuizzesApiService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('sends every server-side quiz filter in one list request', async () => {
    const response: QuizzesListResponse = {
      quizzes: [],
      pagination: { pageIndex: 2, pageSize: 20, totalItems: 0, totalPages: 0 },
    };
    const pending = firstValueFrom(
      api.list({
        pageIndex: 2,
        pageSize: 20,
        search: 'Scratch',
        topicId: 4,
        status: 'active',
        sort: 'titleAsc',
      }),
    );
    const request = http.expectOne(
      '/api/quizzes?pageIndex=2&pageSize=20&status=active&sort=titleAsc&search=Scratch&topicId=4',
    );

    expect(request.request.method).toBe('GET');
    request.flush(response);
    await expect(pending).resolves.toEqual(response);
  });

  it('omits blank search and an unselected topic while sending backend defaults', () => {
    api
      .list({
        pageIndex: 0,
        pageSize: 10,
        search: '',
        topicId: null,
        status: 'all',
        sort: 'recent',
      })
      .subscribe();
    const request = http.expectOne('/api/quizzes?pageIndex=0&pageSize=10&status=all&sort=recent');
    expect(request.request.params.has('search')).toBe(false);
    expect(request.request.params.has('topicId')).toBe(false);
    request.flush({
      quizzes: [],
      pagination: { pageIndex: 0, pageSize: 10, totalItems: 0, totalPages: 0 },
    });
  });
});
