import { HttpClient, HttpParams } from '@angular/common/http';
import { Service, inject } from '@angular/core';
import { Observable } from 'rxjs';

import {
  QuizStatistics,
  SessionHistoryQuery,
  SessionHistoryResponse,
  SessionReport,
  StudentSessionPerformanceResponse,
  StudentStatistics,
} from './results.models';

@Service()
export class ResultsApiService {
  private readonly http = inject(HttpClient);

  listSessions(query: SessionHistoryQuery): Observable<SessionHistoryResponse> {
    let params = new HttpParams()
      .set('pageIndex', query.pageIndex)
      .set('pageSize', query.pageSize)
      .set('status', query.status)
      .set('sort', query.sort);

    if (query.search?.trim()) params = params.set('search', query.search.trim());
    if (query.quizId !== undefined) params = params.set('quizId', query.quizId);

    return this.http.get<SessionHistoryResponse>('/api/sessions', { params });
  }

  getSessionReport(sessionId: number): Observable<SessionReport> {
    return this.http.get<SessionReport>(`/api/sessions/${sessionId}/results`);
  }

  getQuizStatistics(quizId: number): Observable<QuizStatistics> {
    return this.http.get<QuizStatistics>(`/api/quizzes/${quizId}/statistics`);
  }

  getStudentStatistics(studentId: number): Observable<StudentStatistics> {
    return this.http.get<StudentStatistics>(`/api/students/${studentId}/statistics`);
  }

  listStudentSessions(
    studentId: number,
    pageIndex: number,
    pageSize: number,
    quizId?: number,
  ): Observable<StudentSessionPerformanceResponse> {
    let params = new HttpParams().set('pageIndex', pageIndex).set('pageSize', pageSize);
    if (quizId !== undefined) params = params.set('quizId', quizId);

    return this.http.get<StudentSessionPerformanceResponse>(
      `/api/students/${studentId}/statistics/sessions`,
      { params },
    );
  }
}
