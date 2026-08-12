import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';

import { QuestionImagesApiService } from './question-images-api.service';

describe('QuestionImagesApiService', () => {
  let api: QuestionImagesApiService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(QuestionImagesApiService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('uploads the image as browser-managed multipart data', () => {
    const image = new File(['image bytes'], 'pitanje.webp', { type: 'image/webp' });

    api.upload(9, image).subscribe();

    const request = http.expectOne('/api/quizzes/9/question-images');
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toBeInstanceOf(FormData);
    expect((request.request.body as FormData).get('image')).toBe(image);
    expect(request.request.headers.has('Content-Type')).toBe(false);
  });

  it('cleans up a newly uploaded asset through the quiz-scoped route', () => {
    api.cleanup(9, 'a1b2c3.webp').subscribe();

    const request = http.expectOne('/api/quizzes/9/question-images/a1b2c3.webp');
    expect(request.request.method).toBe('DELETE');
    expect(request.request.body).toBeNull();
  });
});
