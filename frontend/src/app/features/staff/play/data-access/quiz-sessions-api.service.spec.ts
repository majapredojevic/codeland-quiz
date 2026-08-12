import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { firstValueFrom } from 'rxjs';

import { QuizSessionsApiService } from './quiz-sessions-api.service';

describe('QuizSessionsApiService', () => {
  let service: QuizSessionsApiService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    service = TestBed.inject(QuizSessionsApiService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('creates a quiz session without inventing a request body contract', async () => {
    const pending = firstValueFrom(service.create(8));
    const request = http.expectOne('/api/quizzes/8/sessions');
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toBeNull();
    request.flush({ session: { id: 41 } });
    expect((await pending).session.id).toBe(41);
  });

  it('requests recent finished sessions with the backend enum values', async () => {
    const pending = firstValueFrom(service.listRecentFinished(20));
    const request = http.expectOne(
      (candidate) =>
        candidate.url === '/api/sessions' &&
        candidate.params.get('pageIndex') === '0' &&
        candidate.params.get('pageSize') === '20' &&
        candidate.params.get('status') === 'FINISHED' &&
        candidate.params.get('sort') === 'RECENT',
    );
    expect(request.request.method).toBe('GET');
    request.flush({ sessions: [], pagination: {} });
    await pending;
  });

  it('uses the canonical session read, participant, removal and start routes', async () => {
    const getPending = firstValueFrom(service.get(41));
    const get = http.expectOne('/api/sessions/41');
    expect(get.request.method).toBe('GET');
    get.flush({
      session: {},
      currentQuestion: null,
      questionResult: null,
      finalResult: null,
    });
    await getPending;

    const participantsPending = firstValueFrom(service.listParticipants(41));
    const participants = http.expectOne('/api/sessions/41/participants');
    expect(participants.request.method).toBe('GET');
    participants.flush({ participants: [] });
    await participantsPending;

    const removePending = firstValueFrom(service.removeParticipant(41, 9));
    const remove = http.expectOne('/api/sessions/41/participants/9');
    expect(remove.request.method).toBe('DELETE');
    remove.flush(null, { status: 204, statusText: 'No Content' });
    await removePending;

    const startPending = firstValueFrom(service.start(41));
    const start = http.expectOne('/api/sessions/41/start');
    expect(start.request.method).toBe('POST');
    expect(start.request.body).toBeNull();
    start.flush({ session: { id: 41 } });
    await startPending;
  });

  it('uses the existing close, next-question and finish lifecycle routes', async () => {
    const closePending = firstValueFrom(service.closeCurrentQuestion(41));
    const close = http.expectOne('/api/sessions/41/questions/current/close');
    expect(close.request.method).toBe('POST');
    expect(close.request.body).toBeNull();
    close.flush({ session: {}, questionResult: {}, stateChanged: true });
    await closePending;

    const nextPending = firstValueFrom(service.startNextQuestion(41));
    const next = http.expectOne('/api/sessions/41/questions/next');
    expect(next.request.method).toBe('POST');
    expect(next.request.body).toBeNull();
    next.flush({ session: {}, currentQuestion: {}, questionCount: 8 });
    await nextPending;

    const finishPending = firstValueFrom(service.finish(41));
    const finish = http.expectOne('/api/sessions/41/finish');
    expect(finish.request.method).toBe('POST');
    expect(finish.request.body).toBeNull();
    finish.flush({ session: {}, finalResult: {}, stateChanged: true });
    await finishPending;
  });
});
