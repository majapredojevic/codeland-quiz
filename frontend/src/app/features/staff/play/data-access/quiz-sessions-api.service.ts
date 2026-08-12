import { HttpClient, HttpParams } from '@angular/common/http';
import { Service, inject } from '@angular/core';
import { Observable } from 'rxjs';

import {
  CloseQuestionResponse,
  FinishQuizSessionResponse,
  QuizSessionHistoryResponse,
  QuizSessionResponse,
  QuizSessionStateResponse,
  SessionParticipantsResponse,
  StartNextQuestionResponse,
  StartQuizSessionResponse,
} from './play.models';

@Service()
export class QuizSessionsApiService {
  private readonly http = inject(HttpClient);

  create(quizId: number): Observable<QuizSessionResponse> {
    return this.http.post<QuizSessionResponse>(`/api/quizzes/${quizId}/sessions`, null);
  }

  get(sessionId: number): Observable<QuizSessionStateResponse> {
    return this.http.get<QuizSessionStateResponse>(`/api/sessions/${sessionId}`);
  }

  listRecentFinished(pageSize = 20): Observable<QuizSessionHistoryResponse> {
    const params = new HttpParams()
      .set('pageIndex', 0)
      .set('pageSize', pageSize)
      .set('status', 'FINISHED')
      .set('sort', 'RECENT');

    return this.http.get<QuizSessionHistoryResponse>('/api/sessions', { params });
  }

  listParticipants(sessionId: number): Observable<SessionParticipantsResponse> {
    return this.http.get<SessionParticipantsResponse>(`/api/sessions/${sessionId}/participants`);
  }

  removeParticipant(sessionId: number, participantId: number): Observable<void> {
    return this.http.delete<void>(`/api/sessions/${sessionId}/participants/${participantId}`);
  }

  start(sessionId: number): Observable<StartQuizSessionResponse> {
    return this.http.post<StartQuizSessionResponse>(`/api/sessions/${sessionId}/start`, null);
  }

  closeCurrentQuestion(sessionId: number): Observable<CloseQuestionResponse> {
    return this.http.post<CloseQuestionResponse>(
      `/api/sessions/${sessionId}/questions/current/close`,
      null,
    );
  }

  startNextQuestion(sessionId: number): Observable<StartNextQuestionResponse> {
    return this.http.post<StartNextQuestionResponse>(
      `/api/sessions/${sessionId}/questions/next`,
      null,
    );
  }

  finish(sessionId: number): Observable<FinishQuizSessionResponse> {
    return this.http.post<FinishQuizSessionResponse>(`/api/sessions/${sessionId}/finish`, null);
  }
}
