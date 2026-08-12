import { NgOptimizedImage } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { Component, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';

import { AuthStore } from '../../../core/auth/auth.store';

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

@Component({
  selector: 'clq-login-page',
  imports: [NgOptimizedImage, RouterLink],
  templateUrl: './login-page.html',
  styleUrl: './login-page.scss',
})
export class LoginPage {
  private readonly authStore = inject(AuthStore);

  protected readonly email = signal('');
  protected readonly password = signal('');
  protected readonly isPasswordVisible = signal(false);
  protected readonly emailTouched = signal(false);
  protected readonly passwordTouched = signal(false);
  protected readonly submitted = signal(false);
  protected readonly isSubmitting = signal(false);
  protected readonly requestError = signal<string | null>(null);
  protected readonly showPasswordChangedNotice = signal(
    this.authStore.notice() === 'password-changed',
  );

  protected readonly isEmailValid = computed(() => EMAIL_PATTERN.test(this.email().trim()));
  protected readonly isFormValid = computed(
    () => this.isEmailValid() && this.password().length > 0,
  );
  protected readonly emailError = computed(() => {
    if (!this.emailTouched() && !this.submitted()) {
      return null;
    }

    if (this.email().trim().length === 0) {
      return 'Unesite email adresu.';
    }

    if (!this.isEmailValid()) {
      return 'Unesite ispravnu email adresu.';
    }

    return null;
  });
  protected readonly passwordError = computed(() => {
    if (!this.passwordTouched() && !this.submitted()) {
      return null;
    }

    return this.password().length === 0 ? 'Unesite lozinku.' : null;
  });

  constructor() {
    if (this.showPasswordChangedNotice()) {
      this.authStore.clearNotice();
    }
  }

  protected updateEmail(event: Event): void {
    this.email.set(this.readInputValue(event));
    this.requestError.set(null);
  }

  protected updatePassword(event: Event): void {
    this.password.set(this.readInputValue(event));
    this.requestError.set(null);
  }

  protected togglePasswordVisibility(): void {
    this.isPasswordVisible.update((isVisible) => !isVisible);
  }

  protected markEmailTouched(): void {
    this.emailTouched.set(true);
  }

  protected markPasswordTouched(): void {
    this.passwordTouched.set(true);
  }

  protected async submit(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    this.submitted.set(true);
    this.requestError.set(null);

    if (!this.isFormValid() || this.isSubmitting()) {
      return;
    }

    this.isSubmitting.set(true);

    try {
      await this.authStore.login(this.email().trim(), this.password());
    } catch (error: unknown) {
      this.requestError.set(this.loginErrorMessage(error));
    } finally {
      this.isSubmitting.set(false);
    }
  }

  private loginErrorMessage(error: unknown): string {
    if (error instanceof HttpErrorResponse && (error.status === 400 || error.status === 401)) {
      return 'Email ili lozinka nisu ispravni.';
    }

    return 'Trenutno nije moguće izvršiti prijavu. Pokušajte ponovo.';
  }

  private readInputValue(event: Event): string {
    return event.target instanceof HTMLInputElement ? event.target.value : '';
  }
}
