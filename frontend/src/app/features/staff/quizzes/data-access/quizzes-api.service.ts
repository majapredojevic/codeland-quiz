import { HttpClient, HttpParams } from '@angular/common/http';
import { Service, inject } from '@angular/core';
import { Observable } from 'rxjs';

import {
  CreateQuizRequest,
  QuizListQuery,
  QuizResponse,
  QuizzesListResponse,
  UpdateQuizRequest,
} from './quizzes.models';

@Service()
export class QuizzesApiService {
  private readonly http = inject(HttpClient);

  list(query: QuizListQuery): Observable<QuizzesListResponse> {
    let params = new HttpParams()
      .set('pageIndex', query.pageIndex)
      .set('pageSize', query.pageSize)
      .set('status', query.status)
      .set('sort', query.sort);

    if (query.search) {
      params = params.set('search', query.search);
    }

    if (query.topicId !== null) {
      params = params.set('topicId', query.topicId);
    }

    return this.http.get<QuizzesListResponse>('/api/quizzes', { params });
  }

  get(id: number): Observable<QuizResponse> {
    return this.http.get<QuizResponse>(`/api/quizzes/${id}`);
  }

  create(request: CreateQuizRequest): Observable<QuizResponse> {
    return this.http.post<QuizResponse>('/api/quizzes', request);
  }

  update(id: number, request: UpdateQuizRequest): Observable<QuizResponse> {
    return this.http.patch<QuizResponse>(`/api/quizzes/${id}`, request);
  }

  activate(id: number): Observable<QuizResponse> {
    return this.http.patch<QuizResponse>(`/api/quizzes/${id}/activate`, null);
  }

  deactivate(id: number): Observable<QuizResponse> {
    return this.http.patch<QuizResponse>(`/api/quizzes/${id}/deactivate`, null);
  }

  delete(id: number): Observable<void> {
    return this.http.delete<void>(`/api/quizzes/${id}`);
  }
}
