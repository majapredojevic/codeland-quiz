import { HttpErrorResponse } from '@angular/common/http';
import { Component, computed, DestroyRef, inject, signal } from '@angular/core';
import { FormField, form, validate } from '@angular/forms/signals';
import { Router, RouterLink } from '@angular/router';

import { NotificationService } from '../../../../../shared/feedback/notification.service';
import { StudentsStore } from '../../data-access/students.store';

interface StudentFormModel {
  firstName: string;
  lastName: string;
  username: string;
}

const USERNAME_PATTERN = /^[a-z0-9](?:[a-z0-9._-]{1,78}[a-z0-9])$/;

@Component({
  selector: 'clq-student-create-page',
  imports: [FormField, RouterLink],
  templateUrl: './student-create-page.html',
  styleUrl: './student-create-page.scss',
})
export class StudentCreatePage {
  private readonly studentsStore = inject(StudentsStore);
  private readonly notifications = inject(NotificationService);
  private readonly router = inject(Router);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly formModel = signal<StudentFormModel>({
    firstName: '',
    lastName: '',
    username: '',
  });
  protected readonly studentForm = form(this.formModel, (student) => {
    validate(student.firstName, ({ value }) => this.nameValidationError(value(), 'ime'));
    validate(student.lastName, ({ value }) => this.nameValidationError(value(), 'prezime'));
    validate(student.username, ({ value }) => this.usernameValidationError(value()));
  });
  protected readonly submitted = signal(false);
  protected readonly isSubmitting = signal(false);
  protected readonly requestError = signal<string | null>(null);
  protected readonly usernameConflict = signal(false);

  protected readonly firstNameError = computed(() =>
    this.visibleFieldError('firstName', this.studentForm.firstName().touched()),
  );
  protected readonly lastNameError = computed(() =>
    this.visibleFieldError('lastName', this.studentForm.lastName().touched()),
  );
  protected readonly usernameError = computed(() => {
    if (!this.submitted() && !this.studentForm.username().touched() && !this.usernameConflict()) {
      return null;
    }

    if (this.usernameConflict()) {
      return 'Korisničko ime je već zauzeto.';
    }

    return this.studentForm.username().errors()[0]?.message ?? null;
  });
  protected readonly isFormValid = computed(
    () => this.studentForm().valid() && !this.usernameConflict(),
  );

  protected clearFieldRequestError(): void {
    this.requestError.set(null);
  }

  protected clearUsernameError(): void {
    this.usernameConflict.set(false);
    this.requestError.set(null);
  }

  protected async submit(event: SubmitEvent): Promise<void> {
    event.preventDefault();

    if (this.isSubmitting()) {
      return;
    }

    this.submitted.set(true);
    this.studentForm().markAsTouched();
    this.requestError.set(null);

    if (!this.isFormValid()) {
      return;
    }

    const value = this.formModel();
    this.isSubmitting.set(true);

    try {
      const student = await this.studentsStore.create({
        firstName: value.firstName.trim(),
        lastName: value.lastName.trim(),
        username: this.normalizeUsername(value.username),
      });

      if (this.destroyRef.destroyed) {
        return;
      }

      this.notifications.success('Učenik je uspješno dodat.');
      await this.router.navigateByUrl(`/app/students/${student.id}`);
    } catch (error: unknown) {
      if (error instanceof HttpErrorResponse && error.status === 409) {
        this.usernameConflict.set(true);
        this.studentForm.username().markAsTouched();
      } else {
        this.requestError.set('Nije moguće dodati učenika. Pokušajte ponovo.');
      }
    } finally {
      this.isSubmitting.set(false);
    }
  }

  private visibleFieldError(field: 'firstName' | 'lastName', touched: boolean): string | null {
    if (!this.submitted() && !touched) {
      return null;
    }

    return this.studentForm[field]().errors()[0]?.message ?? null;
  }

  private nameValidationError(value: string, label: 'ime' | 'prezime') {
    const normalized = value.trim();

    if (normalized === '') {
      return {
        kind: 'required',
        message: label === 'ime' ? 'Unesite ime.' : 'Unesite prezime.',
      };
    }

    if (Array.from(normalized).length > 100) {
      return {
        kind: 'maxLength',
        message:
          label === 'ime'
            ? 'Ime može sadržati najviše 100 znakova.'
            : 'Prezime može sadržati najviše 100 znakova.',
      };
    }

    return undefined;
  }

  private usernameValidationError(value: string) {
    const normalized = this.normalizeUsername(value);

    if (normalized.length < 3 || normalized.length > 80) {
      return {
        kind: 'length',
        message: 'Korisničko ime mora sadržati između 3 i 80 znakova.',
      };
    }

    if (!USERNAME_PATTERN.test(normalized)) {
      return {
        kind: 'pattern',
        message:
          'Koristite slova, brojeve, tačke, donje crte ili crtice; počnite i završite slovom ili brojem.',
      };
    }

    return undefined;
  }

  private normalizeUsername(value: string): string {
    return value.trim().toLowerCase();
  }
}
