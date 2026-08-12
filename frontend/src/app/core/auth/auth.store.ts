import { Service, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import {
  Observable,
  catchError,
  finalize,
  firstValueFrom,
  map,
  shareReplay,
  throwError,
  timeout,
} from 'rxjs';

import { AuthApiService } from './auth-api.service';
import { AuthNotice, AuthStatus, ChangePasswordRequest, StaffUser } from './auth.models';

const SESSION_RESTORE_TIMEOUT_MS = 10_000;

@Service()
export class AuthStore {
  private readonly authApi = inject(AuthApiService);
  private readonly router = inject(Router);

  private readonly userState = signal<StaffUser | null>(null);
  private readonly statusState = signal<AuthStatus>('checking');
  private readonly noticeState = signal<AuthNotice | null>(null);
  private refreshRequest: Observable<void> | null = null;

  readonly user = this.userState.asReadonly();
  readonly status = this.statusState.asReadonly();
  readonly notice = this.noticeState.asReadonly();
  readonly isAuthenticated = computed(() => this.status() === 'authenticated');
  readonly isAdmin = computed(() => this.user()?.role === 'ADMIN');
  readonly isTeacher = computed(() => this.user()?.role === 'TEACHER');
  readonly mustChangePassword = computed(() => this.user()?.mustChangePassword === true);

  async restoreSession(): Promise<void> {
    this.statusState.set('checking');

    try {
      const response = await firstValueFrom(
        this.authApi.getCurrentUser().pipe(timeout(SESSION_RESTORE_TIMEOUT_MS)),
      );
      this.setAuthenticatedUser(response.user);
    } catch {
      this.clearAuthentication();
    }
  }

  async login(email: string, password: string): Promise<StaffUser> {
    this.clearNotice();
    this.clearAuthentication();

    try {
      await firstValueFrom(
        this.authApi.login({
          email: email.trim(),
          password,
        }),
      );

      const response = await firstValueFrom(this.authApi.getCurrentUser());
      const user = response.user;
      this.setAuthenticatedUser(user);

      await this.router.navigateByUrl(
        user.mustChangePassword ? '/change-password' : '/app/dashboard',
      );

      return user;
    } catch (error: unknown) {
      this.clearAuthentication();
      throw error;
    }
  }

  refreshSession(): Observable<void> {
    if (this.refreshRequest !== null) {
      return this.refreshRequest;
    }

    const request = this.authApi.refresh().pipe(
      map(() => undefined),
      catchError((error: unknown) => {
        this.clearAuthentication();
        return throwError(() => error);
      }),
      finalize(() => {
        if (this.refreshRequest === request) {
          this.refreshRequest = null;
        }
      }),
      shareReplay({ bufferSize: 1, refCount: false }),
    );

    this.refreshRequest = request;
    return request;
  }

  async changePassword(request: ChangePasswordRequest): Promise<void> {
    await firstValueFrom(this.authApi.changePassword(request));
    this.clearAuthentication();
    this.noticeState.set('password-changed');
    await this.router.navigateByUrl('/login');
  }

  async logout(): Promise<void> {
    await firstValueFrom(this.authApi.logout());
    this.clearAuthentication();
    this.clearNotice();
    await this.router.navigateByUrl('/login');
  }

  clearAuthentication(): void {
    this.userState.set(null);
    this.statusState.set('unauthenticated');
  }

  clearNotice(): void {
    this.noticeState.set(null);
  }

  private setAuthenticatedUser(user: StaffUser): void {
    this.userState.set(user);
    this.statusState.set('authenticated');
  }
}
