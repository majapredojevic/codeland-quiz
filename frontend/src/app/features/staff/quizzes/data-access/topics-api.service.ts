import { HttpClient, HttpParams } from '@angular/common/http';
import { Service, inject } from '@angular/core';
import { Observable } from 'rxjs';

import {
  CreateTopicRequest,
  TopicResponse,
  TopicsListResponse,
  UpdateTopicRequest,
} from './quizzes.models';

@Service()
export class TopicsApiService {
  private readonly http = inject(HttpClient);

  list(
    pageIndex: number,
    pageSize: number,
    sort: 'nameAsc' = 'nameAsc',
  ): Observable<TopicsListResponse> {
    const params = new HttpParams()
      .set('pageIndex', pageIndex)
      .set('pageSize', pageSize)
      .set('sort', sort);

    return this.http.get<TopicsListResponse>('/api/topics', { params });
  }

  get(id: number): Observable<TopicResponse> {
    return this.http.get<TopicResponse>(`/api/topics/${id}`);
  }

  create(request: CreateTopicRequest): Observable<TopicResponse> {
    return this.http.post<TopicResponse>('/api/topics', request);
  }

  update(id: number, request: UpdateTopicRequest): Observable<TopicResponse> {
    return this.http.patch<TopicResponse>(`/api/topics/${id}`, request);
  }

  delete(id: number): Observable<void> {
    return this.http.delete<void>(`/api/topics/${id}`);
  }
}
