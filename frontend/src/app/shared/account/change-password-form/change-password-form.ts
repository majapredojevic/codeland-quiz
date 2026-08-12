import { HttpErrorResponse } from '@angular/common/http';
import { Component, computed, inject, input, output, signal } from '@angular/core';

import { AuthStore } from '../../../core/auth/auth.store';

@Component({
  selector: 'clq-change-password-form',
  templateUrl: './change-password-form.html',
  styleUrl: './change-password-form.scss',
})
export class ChangePasswordForm {
  private readonly authStore = inject(AuthStore);

  readonly showCancel = input(false);
  readonly cancelRequested = output<void>();

  protected readonly currentPassword = signal('');
  protected readonly newPassword = signal('');
  protected readonly newPasswordConfirmation = signal('');
  protected readonly isCurrentPasswordVisible = signal(false);
  protected readonly isNewPasswordVisible = signal(false);
  protected readonly isConfirmationVisible = signal(false);
  protected readonly currentPasswordTouched = signal(false);
  protected readonly newPasswordTouched = signal(false);
  protected readonly confirmationTouched = signal(false);
  protected readonly submitted = signal(false);
  protected readonly isSubmitting = signal(false);
  protected readonly requestError = signal<string | null>(null);

  protected readonly isNewPasswordStrong = computed(() => {
    const password = this.newPassword();

    return (
      password.length >= 8 &&
      /[A-Z]/.test(password) &&
      /[a-z]/.test(password) &&
      /[0-9]/.test(password) &&
      /[^A-Za-z0-9\s]/.test(password)
    );
  });
  protected readonly isFormValid = computed(
    () =>
      this.currentPassword().length > 0 &&
      this.isNewPasswordStrong() &&
      this.newPassword() !== this.currentPassword() &&
      this.newPasswordConfirmation() === this.newPassword(),
  );
  protected readonly currentPasswordError = computed(() => {
    if (!this.currentPasswordTouched() && !this.submitted()) {
      return null;
    }

    return this.currentPassword().length === 0 ? 'Unesite trenutnu lozinku.' : null;
  });
  protected readonly newPasswordError = computed(() => {
    if (!this.newPasswordTouched() && !this.submitted()) {
      return null;
    }

    const password = this.newPassword();

    if (password.length === 0) {
      return 'Unesite novu lozinku.';
    }

    if (password.length < 8) {
      return 'Nova lozinka mora imati najmanje 8 znakova.';
    }

    if (!/[A-Z]/.test(password) || !/[a-z]/.test(password)) {
      return 'Nova lozinka mora sadržati veliko i malo ASCII slovo.';
    }

    if (!/[0-9]/.test(password)) {
      return 'Nova lozinka mora sadržati najmanje jednu cifru.';
    }

    if (!/[^A-Za-z0-9\s]/.test(password)) {
      return 'Nova lozinka mora sadržati najmanje jedan specijalni znak.';
    }

    if (password === this.currentPassword()) {
      return 'Nova lozinka mora se razlikovati od trenutne.';
    }

    return null;
  });
  protected readonly confirmationError = computed(() => {
    if (!this.confirmationTouched() && !this.submitted()) {
      return null;
    }

    if (this.newPasswordConfirmation().length === 0) {
      return 'Potvrdite novu lozinku.';
    }

    return this.newPasswordConfirmation() !== this.newPassword()
      ? 'Potvrda nove lozinke se ne podudara.'
      : null;
  });

  protected updateCurrentPassword(event: Event): void {
    this.currentPassword.set(this.readInputValue(event));
    this.requestError.set(null);
  }

  protected updateNewPassword(event: Event): void {
    this.newPassword.set(this.readInputValue(event));
    this.requestError.set(null);
  }

  protected updateConfirmation(event: Event): void {
    this.newPasswordConfirmation.set(this.readInputValue(event));
    this.requestError.set(null);
  }

  protected toggleCurrentPasswordVisibility(): void {
    this.isCurrentPasswordVisible.update((isVisible) => !isVisible);
  }

  protected toggleNewPasswordVisibility(): void {
    this.isNewPasswordVisible.update((isVisible) => !isVisible);
  }

  protected toggleConfirmationVisibility(): void {
    this.isConfirmationVisible.update((isVisible) => !isVisible);
  }

  protected markCurrentPasswordTouched(): void {
    this.currentPasswordTouched.set(true);
  }

  protected markNewPasswordTouched(): void {
    this.newPasswordTouched.set(true);
  }

  protected markConfirmationTouched(): void {
    this.confirmationTouched.set(true);
  }

  protected requestCancel(): void {
    this.cancelRequested.emit();
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
      await this.authStore.changePassword({
        currentPassword: this.currentPassword(),
        newPassword: this.newPassword(),
        newPasswordConfirmation: this.newPasswordConfirmation(),
      });
    } catch (error: unknown) {
      this.requestError.set(this.changePasswordErrorMessage(error));
    } finally {
      this.isSubmitting.set(false);
    }
  }

  private changePasswordErrorMessage(error: unknown): string {
    if (error instanceof HttpErrorResponse && error.status === 400) {
      return 'Promjena lozinke nije uspjela. Provjerite unesene podatke.';
    }

    return 'Trenutno nije moguće promijeniti lozinku. Pokušajte ponovo.';
  }

  private readInputValue(event: Event): string {
    return event.target instanceof HTMLInputElement ? event.target.value : '';
  }
}
