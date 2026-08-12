import { HttpErrorResponse } from '@angular/common/http';
import { Component, computed, DestroyRef, inject, signal } from '@angular/core';
import { MatDialog, MatDialogRef } from '@angular/material/dialog';
import { Router, RouterLink } from '@angular/router';
import { firstValueFrom } from 'rxjs';

import { NotificationService } from '../../../../../shared/feedback/notification.service';
import {
  TemporaryPasswordDialog,
  TemporaryPasswordDialogData,
} from '../../components/temporary-password-dialog/temporary-password-dialog';
import { UsersStore } from '../../data-access/users.store';

@Component({
  selector: 'clq-user-create-page',
  imports: [RouterLink],
  templateUrl: './user-create-page.html',
  styleUrl: './user-create-page.scss',
})
export class UserCreatePage {
  private readonly usersStore = inject(UsersStore);
  private readonly dialog = inject(MatDialog);
  private readonly router = inject(Router);
  private readonly destroyRef = inject(DestroyRef);
  private readonly notifications = inject(NotificationService);
  private temporaryPasswordDialogRef: MatDialogRef<TemporaryPasswordDialog, boolean> | null = null;

  protected readonly name = signal('');
  protected readonly email = signal('');
  protected readonly nameTouched = signal(false);
  protected readonly emailTouched = signal(false);
  protected readonly submitted = signal(false);
  protected readonly isSubmitting = signal(false);
  protected readonly requestError = signal<string | null>(null);
  protected readonly emailConflict = signal(false);

  protected readonly normalizedName = computed(() => this.name().trim());
  protected readonly normalizedEmail = computed(() => this.email().trim().toLowerCase());
  protected readonly nameError = computed(() => {
    if (!this.nameTouched() && !this.submitted()) {
      return null;
    }

    return this.normalizedName() === '' ? 'Unesite ime i prezime.' : null;
  });
  protected readonly emailError = computed(() => {
    if (!this.emailTouched() && !this.submitted() && !this.emailConflict()) {
      return null;
    }

    if (this.normalizedEmail() === '') {
      return 'Unesite email adresu.';
    }

    if (!this.isEmailValid(this.normalizedEmail())) {
      return 'Unesite ispravnu email adresu.';
    }

    return this.emailConflict() ? 'Korisnik sa ovom email adresom već postoji.' : null;
  });
  protected readonly isFormValid = computed(
    () =>
      this.normalizedName() !== '' &&
      this.isEmailValid(this.normalizedEmail()) &&
      !this.emailConflict(),
  );

  protected updateName(event: Event): void {
    this.name.set(this.readInputValue(event));
    this.requestError.set(null);
  }

  protected updateEmail(event: Event): void {
    this.email.set(this.readInputValue(event));
    this.emailConflict.set(false);
    this.requestError.set(null);
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
      const result = await this.usersStore.create({
        name: this.normalizedName(),
        email: this.normalizedEmail(),
      });

      if (this.destroyRef.destroyed) {
        return;
      }

      const data: TemporaryPasswordDialogData = {
        title: 'Korisnik je uspješno kreiran',
        label: 'Privremena lozinka',
        password: result.temporaryPassword,
        supportingMessage: 'Korisnik će morati promijeniti lozinku pri prvoj prijavi.',
      };

      this.temporaryPasswordDialogRef = this.dialog.open(TemporaryPasswordDialog, {
        data,
        disableClose: true,
        width: '32rem',
        maxWidth: 'calc(100vw - 2rem)',
        panelClass: 'clq-dialog-panel',
      });

      const acknowledged = await firstValueFrom(this.temporaryPasswordDialogRef.afterClosed());
      this.temporaryPasswordDialogRef = null;

      if (acknowledged && !this.destroyRef.destroyed) {
        this.notifications.success('Korisnik je uspješno kreiran.');
        await this.router.navigateByUrl('/app/users');
      }
    } catch (error: unknown) {
      if (error instanceof HttpErrorResponse && error.status === 409) {
        this.emailConflict.set(true);
        this.emailTouched.set(true);
      } else {
        this.requestError.set('Nije moguće dodati korisnika. Pokušajte ponovo.');
      }
    } finally {
      this.isSubmitting.set(false);
    }
  }

  private isEmailValid(email: string): boolean {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
  }

  private readInputValue(event: Event): string {
    return event.target instanceof HTMLInputElement ? event.target.value : '';
  }
}
