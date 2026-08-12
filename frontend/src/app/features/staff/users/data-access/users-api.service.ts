import { HttpClient, HttpParams } from '@angular/common/http';
import { Service, inject } from '@angular/core';
import { Observable } from 'rxjs';

import {
  CreateUserRequest,
  CreateUserResponse,
  TemporaryPasswordResponse,
  UpdateUserRequest,
  UserResponse,
  UsersListResponse,
} from './users.models';

const USERS_URL = '/api/admin/users';

@Service()
export class UsersApiService {
  private readonly http = inject(HttpClient);

  list(pageIndex: number, pageSize: number): Observable<UsersListResponse> {
    const params = new HttpParams().set('pageIndex', pageIndex).set('pageSize', pageSize);

    return this.http.get<UsersListResponse>(USERS_URL, { params });
  }

  get(id: number): Observable<UserResponse> {
    return this.http.get<UserResponse>(`${USERS_URL}/${id}`);
  }

  create(request: CreateUserRequest): Observable<CreateUserResponse> {
    return this.http.post<CreateUserResponse>(USERS_URL, request);
  }

  update(id: number, request: UpdateUserRequest): Observable<UserResponse> {
    return this.http.patch<UserResponse>(`${USERS_URL}/${id}`, request);
  }

  activate(id: number): Observable<UserResponse> {
    return this.http.patch<UserResponse>(`${USERS_URL}/${id}/activate`, null);
  }

  deactivate(id: number): Observable<UserResponse> {
    return this.http.patch<UserResponse>(`${USERS_URL}/${id}/deactivate`, null);
  }

  resetPassword(id: number): Observable<TemporaryPasswordResponse> {
    return this.http.post<TemporaryPasswordResponse>(`${USERS_URL}/${id}/reset-password`, null);
  }
}
