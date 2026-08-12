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

  it('uses exact detail, create, partial update, status and delete contracts', () => {
    api.get(9).subscribe();
    expect(http.expectOne('/api/quizzes/9').request.method).toBe('GET');

    const createBody = { title: 'Petlje', version: 1, description: null, topicId: 4 };
    api.create(createBody).subscribe();
    const create = http.expectOne('/api/quizzes');
    expect(create.request.method).toBe('POST');
    expect(create.request.body).toEqual(createBody);

    api.update(9, { topicId: null }).subscribe();
    const update = http.expectOne('/api/quizzes/9');
    expect(update.request.method).toBe('PATCH');
    expect(update.request.body).toEqual({ topicId: null });

    api.activate(9).subscribe();
    const activate = http.expectOne('/api/quizzes/9/activate');
    expect(activate.request.method).toBe('PATCH');
    expect(activate.request.body).toBeNull();

    api.deactivate(9).subscribe();
    const deactivate = http.expectOne('/api/quizzes/9/deactivate');
    expect(deactivate.request.method).toBe('PATCH');
    expect(deactivate.request.body).toBeNull();

    api.delete(9).subscribe();
    expect(http.expectOne('/api/quizzes/9').request.method).toBe('DELETE');
  });
});
