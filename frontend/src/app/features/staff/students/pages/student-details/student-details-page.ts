import { HttpErrorResponse } from '@angular/common/http';
import {
  Component,
  computed,
  effect,
  inject,
  OnDestroy,
  OnInit,
  signal,
  untracked,
} from '@angular/core';
import { FormField, form, validate } from '@angular/forms/signals';
import { MatDialog } from '@angular/material/dialog';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { firstValueFrom } from 'rxjs';

import {
  ConfirmDialog,
  ConfirmDialogData,
} from '../../../../../shared/feedback/confirm-dialog/confirm-dialog';
import { NotificationService } from '../../../../../shared/feedback/notification.service';
import { ActiveStatusBadge } from '../../../../../shared/ui/active-status-badge/active-status-badge';
import type { Student, UpdateStudentRequest } from '../../data-access/students.models';
import { StudentsStore } from '../../data-access/students.store';

const NAME_MAX_LENGTH = 100;
const USERNAME_MAX_LENGTH = 80;
const USERNAME_PATTERN = /^[a-z0-9](?:[a-z0-9._-]{1,78}[a-z0-9])$/;

interface StudentFormModel {
  firstName: string;
  lastName: string;
  username: string;
}

@Component({
  selector: 'clq-student-details-page',
  imports: [ActiveStatusBadge, FormField, RouterLink],
  templateUrl: './student-details-page.html',
  styleUrl: './student-details-page.scss',
})
export class StudentDetailsPage implements OnInit, OnDestroy {
  protected readonly studentsStore = inject(StudentsStore);
  private readonly route = inject(ActivatedRoute);
  private readonly dialog = inject(MatDialog);
  private readonly notifications = inject(NotificationService);

  protected readonly invalidId = signal(false);
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
  protected readonly isSaving = signal(false);
  protected readonly isChangingStatus = signal(false);
  protected readonly usernameConflict = signal(false);
  protected readonly saveError = signal<string | null>(null);

  private studentId: number | null = null;
  private lastSynchronizedStudent: Student | null = null;

  constructor() {
    effect(() => {
      const student = this.studentsStore.detail();

      if (student !== null && student !== this.lastSynchronizedStudent) {
        this.lastSynchronizedStudent = student;
        untracked(() => this.restoreForm());
      }
    });
  }

  protected readonly normalizedFirstName = computed(() => this.formModel().firstName.trim());
  protected readonly normalizedLastName = computed(() => this.formModel().lastName.trim());
  protected readonly normalizedUsername = computed(() =>
    this.formModel().username.trim().toLowerCase(),
  );
  protected readonly firstNameError = computed(() =>
    this.visibleFieldError('firstName', this.studentForm.firstName().touched()),
  );
  protected readonly lastNameError = computed(() =>
    this.visibleFieldError('lastName', this.studentForm.lastName().touched()),
  );
  protected readonly usernameError = computed(() => {
    if (!this.studentForm.username().touched() && !this.submitted() && !this.usernameConflict()) {
      return null;
    }

    if (this.usernameConflict()) {
      return 'Učenik sa ovim korisničkim imenom već postoji.';
    }

    return this.studentForm.username().errors()[0]?.message ?? null;
  });
  protected readonly isFormValid = computed(
    () => this.studentForm().valid() && !this.usernameConflict(),
  );
  protected readonly isDirty = computed(() => {
    const student = this.studentsStore.detail();

    return (
      student !== null &&
      (this.normalizedFirstName() !== student.firstName ||
        this.normalizedLastName() !== student.lastName ||
        this.normalizedUsername() !== student.username)
    );
  });

  async ngOnInit(): Promise<void> {
    const routeId = this.route.snapshot.paramMap.get('id');
    const id = routeId !== null && /^[1-9]\d*$/.test(routeId) ? Number(routeId) : Number.NaN;

    if (!Number.isSafeInteger(id)) {
      this.invalidId.set(true);
      return;
    }

    this.studentId = id;
    await this.loadStudent();
  }

  ngOnDestroy(): void {
    this.studentsStore.clearDetail();
  }

  protected clearFieldRequestError(): void {
    this.saveError.set(null);
  }

  protected clearUsernameError(): void {
    this.usernameConflict.set(false);
    this.saveError.set(null);
  }

  protected restoreForm(): void {
    const student = this.studentsStore.detail();

    if (student === null) {
      return;
    }

    this.studentForm().reset({
      firstName: student.firstName,
      lastName: student.lastName,
      username: student.username,
    });
    this.submitted.set(false);
    this.usernameConflict.set(false);
    this.saveError.set(null);
  }

  protected async save(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    this.submitted.set(true);
    this.studentForm().markAsTouched();
    this.saveError.set(null);

    if (!this.isFormValid() || !this.isDirty() || this.isSaving() || this.studentId === null) {
      return;
    }

    const request: UpdateStudentRequest = {
      firstName: this.normalizedFirstName(),
      lastName: this.normalizedLastName(),
      username: this.normalizedUsername(),
    };
    this.isSaving.set(true);

    try {
      await this.studentsStore.update(this.studentId, request);
      this.restoreForm();
      this.notifications.success('Izmjene su sačuvane.');
    } catch (error: unknown) {
      if (error instanceof HttpErrorResponse && error.status === 409) {
        this.usernameConflict.set(true);
        this.studentForm.username().markAsTouched();
      } else {
        this.saveError.set('Nije moguće sačuvati izmjene. Pokušajte ponovo.');
      }
    } finally {
      this.isSaving.set(false);
    }
  }

  protected async activate(): Promise<void> {
    if (this.studentId === null || this.isChangingStatus()) {
      return;
    }

    this.isChangingStatus.set(true);

    try {
      await this.studentsStore.activate(this.studentId);
      this.notifications.success('Učenik je aktiviran.');
    } catch {
      this.notifications.error('Nije moguće aktivirati učenika.');
    } finally {
      this.isChangingStatus.set(false);
    }
  }

  protected async confirmDeactivation(): Promise<void> {
    const student = this.studentsStore.detail();

    if (student === null || this.studentId === null || this.isChangingStatus()) {
      return;
    }

    const data: ConfirmDialogData = {
      title: 'Deaktivirati učenika?',
      message: `"${student.firstName} ${student.lastName}" više neće moći da učestvuje kao registrovani učenik.\nPostojeći podaci i rezultati biće sačuvani.`,
      confirmLabel: 'Deaktiviraj',
      tone: 'destructive',
    };
    const confirmed = await firstValueFrom(
      this.dialog
        .open(ConfirmDialog, {
          data,
          width: '30rem',
          maxWidth: 'calc(100vw - 2rem)',
          panelClass: 'clq-dialog-panel',
        })
        .afterClosed(),
    );

    if (!confirmed || this.isChangingStatus()) {
      return;
    }

    this.isChangingStatus.set(true);

    try {
      await this.studentsStore.deactivate(this.studentId);
      this.notifications.success('Učenik je deaktiviran.');
    } catch {
      this.notifications.error('Nije moguće deaktivirati učenika.');
    } finally {
      this.isChangingStatus.set(false);
    }
  }

  protected async retry(): Promise<void> {
    await this.loadStudent();
  }

  private async loadStudent(): Promise<void> {
    if (this.studentId === null) {
      return;
    }

    await this.studentsStore.loadDetail(this.studentId);

    if (this.studentsStore.detail() !== null) {
      this.restoreForm();
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

    if (Array.from(normalized).length > NAME_MAX_LENGTH) {
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
    const normalized = value.trim().toLowerCase();

    if (normalized.length < 3 || normalized.length > USERNAME_MAX_LENGTH) {
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
}
