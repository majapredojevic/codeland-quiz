import { HttpClient, HttpParams } from '@angular/common/http';
import { Service, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { QuizListQuery, QuizzesListResponse } from './quizzes.models';

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
}
