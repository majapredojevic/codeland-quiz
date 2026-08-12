import { HttpClient } from '@angular/common/http';
import { Service, inject } from '@angular/core';
import { Observable } from 'rxjs';

import {
  CreateQuestionRequest,
  QuestionResponse,
  QuestionsListResponse,
  ReorderQuestionsRequest,
  UpdateQuestionRequest,
} from './questions.models';

@Service()
export class QuestionsApiService {
  private readonly http = inject(HttpClient);

  list(quizId: number): Observable<QuestionsListResponse> {
    return this.http.get<QuestionsListResponse>(`/api/quizzes/${quizId}/questions`);
  }

  get(quizId: number, questionId: number): Observable<QuestionResponse> {
    return this.http.get<QuestionResponse>(`/api/quizzes/${quizId}/questions/${questionId}`);
  }

  create(quizId: number, request: CreateQuestionRequest): Observable<QuestionResponse> {
    return this.http.post<QuestionResponse>(`/api/quizzes/${quizId}/questions`, request);
  }

  update(
    quizId: number,
    questionId: number,
    request: UpdateQuestionRequest,
  ): Observable<QuestionResponse> {
    return this.http.patch<QuestionResponse>(
      `/api/quizzes/${quizId}/questions/${questionId}`,
      request,
    );
  }

  delete(quizId: number, questionId: number): Observable<void> {
    return this.http.delete<void>(`/api/quizzes/${quizId}/questions/${questionId}`);
  }

  reorder(quizId: number, request: ReorderQuestionsRequest): Observable<QuestionsListResponse> {
    return this.http.put<QuestionsListResponse>(`/api/quizzes/${quizId}/questions/order`, request);
  }
}
