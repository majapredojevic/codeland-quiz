import { HttpClient } from '@angular/common/http';
import { Service, inject } from '@angular/core';
import { Observable } from 'rxjs';

import {
  ChangePasswordRequest,
  CurrentUserResponse,
  LoginRequest,
  LoginResponse,
  RefreshResponse,
} from './auth.models';

@Service()
export class AuthApiService {
  private readonly http = inject(HttpClient);

  login(credentials: LoginRequest): Observable<LoginResponse> {
    return this.http.post<LoginResponse>('/api/auth/login', credentials);
  }

  getCurrentUser(): Observable<CurrentUserResponse> {
    return this.http.get<CurrentUserResponse>('/api/auth/me');
  }

  refresh(): Observable<RefreshResponse> {
    return this.http.post<RefreshResponse>('/api/auth/refresh', null);
  }

  changePassword(request: ChangePasswordRequest): Observable<void> {
    return this.http.post<void>('/api/auth/change-password', request);
  }

  logout(): Observable<void> {
    return this.http.post<void>('/api/auth/logout', null);
  }
}
