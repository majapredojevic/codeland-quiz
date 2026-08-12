import { HttpErrorResponse } from '@angular/common/http';
import {
  Component,
  computed,
  DestroyRef,
  effect,
  inject,
  OnInit,
  signal,
  untracked,
} from '@angular/core';
import { MatDialog, MatDialogRef } from '@angular/material/dialog';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { firstValueFrom } from 'rxjs';

import { NotificationService } from '../../../../../shared/feedback/notification.service';
import {
  ConfirmDialog,
  ConfirmDialogData,
} from '../../../../../shared/feedback/confirm-dialog/confirm-dialog';
import { ActiveStatusBadge } from '../../../../../shared/ui/active-status-badge/active-status-badge';
import {
  TemporaryPasswordDialog,
  TemporaryPasswordDialogData,
} from '../../components/temporary-password-dialog/temporary-password-dialog';
import { UsersStore } from '../../data-access/users.store';
import type { UserDetail } from '../../data-access/users.models';

@Component({
  selector: 'clq-user-details-page',
  imports: [RouterLink, ActiveStatusBadge],
  templateUrl: './user-details-page.html',
  styleUrl: './user-details-page.scss',
})
export class UserDetailsPage implements OnInit {
  protected readonly usersStore = inject(UsersStore);
  private readonly route = inject(ActivatedRoute);
  private readonly dialog = inject(MatDialog);
  private readonly notifications = inject(NotificationService);
  private readonly destroyRef = inject(DestroyRef);
  private temporaryPasswordDialogRef: MatDialogRef<TemporaryPasswordDialog, boolean> | null = null;
  private lastSynchronizedUser: UserDetail | null = null;

  protected readonly invalidId = signal(false);
  protected readonly name = signal('');
  protected readonly email = signal('');
  protected readonly nameTouched = signal(false);
  protected readonly emailTouched = signal(false);
  protected readonly submitted = signal(false);
  protected readonly isSaving = signal(false);
  protected readonly isChangingStatus = signal(false);
  protected readonly isResettingPassword = signal(false);
  protected readonly emailConflict = signal(false);
  protected readonly saveError = signal<string | null>(null);

  private userId: number | null = null;

  constructor() {
    effect(() => {
      const user = this.usersStore.detail();

      if (user !== null && user !== this.lastSynchronizedUser) {
        this.lastSynchronizedUser = user;
        untracked(() => this.restoreForm());
      }
    });
  }

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
  protected readonly isDirty = computed(() => {
    const user = this.usersStore.detail();

    return (
      user !== null &&
      (this.normalizedName() !== user.name || this.normalizedEmail() !== user.email)
    );
  });

  async ngOnInit(): Promise<void> {
    const routeId = this.route.snapshot.paramMap.get('id');
    const id = routeId === null ? Number.NaN : Number(routeId);

    if (!Number.isInteger(id) || id < 1) {
      this.invalidId.set(true);
      return;
    }

    this.userId = id;
    await this.loadUser();
  }

  protected updateName(event: Event): void {
    this.name.set(this.readInputValue(event));
    this.saveError.set(null);
  }

  protected updateEmail(event: Event): void {
    this.email.set(this.readInputValue(event));
    this.emailConflict.set(false);
    this.saveError.set(null);
  }

  protected restoreForm(): void {
    const user = this.usersStore.detail();

    if (user === null) {
      return;
    }

    this.name.set(user.name);
    this.email.set(user.email);
    this.nameTouched.set(false);
    this.emailTouched.set(false);
    this.submitted.set(false);
    this.emailConflict.set(false);
    this.saveError.set(null);
  }

  protected async save(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    this.submitted.set(true);
    this.saveError.set(null);

    if (!this.isFormValid() || !this.isDirty() || this.isSaving() || this.userId === null) {
      return;
    }

    this.isSaving.set(true);

    try {
      await this.usersStore.update(this.userId, {
        name: this.normalizedName(),
        email: this.normalizedEmail(),
      });
      this.restoreForm();
      this.notifications.success('Izmjene su sačuvane.');
    } catch (error: unknown) {
      if (error instanceof HttpErrorResponse && error.status === 409) {
        this.emailConflict.set(true);
        this.emailTouched.set(true);
      } else {
        this.saveError.set('Nije moguće sačuvati izmjene. Pokušajte ponovo.');
      }
    } finally {
      this.isSaving.set(false);
    }
  }

  protected async activate(): Promise<void> {
    if (this.userId === null || this.isChangingStatus()) {
      return;
    }

    this.isChangingStatus.set(true);

    try {
      await this.usersStore.activate(this.userId);
      this.notifications.success('Korisnik je aktiviran.');
    } catch {
      this.notifications.error('Nije moguće aktivirati korisnika.');
    } finally {
      this.isChangingStatus.set(false);
    }
  }

  protected async confirmDeactivation(): Promise<void> {
    const user = this.usersStore.detail();

    if (user === null || this.userId === null || this.isChangingStatus()) {
      return;
    }

    const data: ConfirmDialogData = {
      title: 'Deaktivirati korisnika?',
      message: `„${user.name}” više neće moći da se prijavi u aplikaciju. Postojeći podaci i istorija biće sačuvani.`,
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

    if (!confirmed) {
      return;
    }

    this.isChangingStatus.set(true);

    try {
      await this.usersStore.deactivate(this.userId);
      this.notifications.success('Korisnik je deaktiviran.');
    } catch {
      this.notifications.error('Nije moguće deaktivirati korisnika.');
    } finally {
      this.isChangingStatus.set(false);
    }
  }

  protected async confirmPasswordReset(): Promise<void> {
    if (this.userId === null || this.isResettingPassword()) {
      return;
    }

    const data: ConfirmDialogData = {
      title: 'Resetovati lozinku?',
      message:
        'Biće generisana nova privremena lozinka. Korisnik će je morati promijeniti pri sljedećoj prijavi.',
      confirmLabel: 'Resetuj lozinku',
      tone: 'default',
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

    if (!confirmed) {
      return;
    }

    this.isResettingPassword.set(true);

    try {
      const result = await this.usersStore.resetPassword(this.userId);

      if (this.destroyRef.destroyed) {
        return;
      }

      const dialogData: TemporaryPasswordDialogData = {
        title: 'Lozinka je uspješno resetovana',
        label: 'Nova privremena lozinka',
        password: result.temporaryPassword,
        supportingMessage: 'Sačuvajte je sada. Lozinka neće biti ponovo prikazana.',
      };

      this.temporaryPasswordDialogRef = this.dialog.open(TemporaryPasswordDialog, {
        data: dialogData,
        disableClose: true,
        width: '32rem',
        maxWidth: 'calc(100vw - 2rem)',
        panelClass: 'clq-dialog-panel',
      });
      void firstValueFrom(this.temporaryPasswordDialogRef.afterClosed()).finally(() => {
        this.temporaryPasswordDialogRef = null;
      });
    } catch {
      this.notifications.error('Nije moguće resetovati lozinku.');
    } finally {
      this.isResettingPassword.set(false);
    }
  }

  protected async retry(): Promise<void> {
    await this.loadUser();
  }

  private async loadUser(): Promise<void> {
    if (this.userId === null) {
      return;
    }

    await this.usersStore.loadDetail(this.userId);

    if (this.usersStore.detail() !== null) {
      this.restoreForm();
    }
  }

  private isEmailValid(email: string): boolean {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
  }

  private readInputValue(event: Event): string {
    return event.target instanceof HTMLInputElement ? event.target.value : '';
  }
}
