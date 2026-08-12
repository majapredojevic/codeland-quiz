import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';

import { QuestionsApiService } from './questions-api.service';

describe('QuestionsApiService', () => {
  let api: QuestionsApiService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(QuestionsApiService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('uses the exact list and direct-detail routes', () => {
    api.list(9).subscribe();
    expect(http.expectOne('/api/quizzes/9/questions').request.method).toBe('GET');

    api.get(9, 11).subscribe();
    expect(http.expectOne('/api/quizzes/9/questions/11').request.method).toBe('GET');
  });

  it('creates without order or option identifiers', () => {
    const body = {
      questionText: 'Koja naredba ispisuje tekst?',
      questionType: 'SINGLE_CHOICE' as const,
      imagePath: null,
      timeLimitSeconds: 30,
      maxPoints: 1000,
      options: [
        { optionText: 'echo', isCorrect: true },
        { optionText: 'read', isCorrect: false },
      ],
    };
    api.create(9, body).subscribe();
    const request = http.expectOne('/api/quizzes/9/questions');

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual(body);
    expect(request.request.body).not.toHaveProperty('questionOrder');
    expect(request.request.body.options[0]).not.toHaveProperty('id');
    expect(request.request.body.options[0]).not.toHaveProperty('optionOrder');
  });

  it('uses partial PATCH, DELETE and full-list PUT reorder contracts', () => {
    api.update(9, 11, { maxPoints: 2000 }).subscribe();
    const update = http.expectOne('/api/quizzes/9/questions/11');
    expect(update.request.method).toBe('PATCH');
    expect(update.request.body).toEqual({ maxPoints: 2000 });

    api.delete(9, 11).subscribe();
    expect(http.expectOne('/api/quizzes/9/questions/11').request.method).toBe('DELETE');

    api.reorder(9, { questionIds: [20, 7, 11] }).subscribe();
    const reorder = http.expectOne('/api/quizzes/9/questions/order');
    expect(reorder.request.method).toBe('PUT');
    expect(reorder.request.body).toEqual({ questionIds: [20, 7, 11] });
    expect(reorder.request.body).not.toHaveProperty('questionId');
    expect(reorder.request.body).not.toHaveProperty('newIndex');
  });
});
