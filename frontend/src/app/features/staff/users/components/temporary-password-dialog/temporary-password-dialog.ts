import { Component, inject, signal } from '@angular/core';
import {
  MAT_DIALOG_DATA,
  MatDialogActions,
  MatDialogClose,
  MatDialogContent,
  MatDialogRef,
  MatDialogTitle,
} from '@angular/material/dialog';

import { NotificationService } from '../../../../../shared/feedback/notification.service';

export interface TemporaryPasswordDialogData {
  title: string;
  password: string;
  label: string;
  supportingMessage: string;
}

@Component({
  selector: 'clq-temporary-password-dialog',
  imports: [MatDialogActions, MatDialogClose, MatDialogContent, MatDialogTitle],
  templateUrl: './temporary-password-dialog.html',
  styleUrl: './temporary-password-dialog.scss',
})
export class TemporaryPasswordDialog {
  private readonly notifications = inject(NotificationService);
  private readonly dialogRef = inject(MatDialogRef<TemporaryPasswordDialog, boolean>);

  protected readonly data = inject<TemporaryPasswordDialogData>(MAT_DIALOG_DATA);
  protected readonly isCopying = signal(false);

  constructor() {
    this.dialogRef.disableClose = true;
  }

  protected async copyPassword(): Promise<void> {
    if (this.isCopying()) {
      return;
    }

    this.isCopying.set(true);

    try {
      const clipboard = globalThis.navigator?.clipboard;

      if (clipboard === undefined) {
        throw new Error('Clipboard API is unavailable.');
      }

      await clipboard.writeText(this.data.password);
      this.notifications.success('Privremena lozinka je kopirana.');
    } catch {
      this.notifications.error(
        'Privremenu lozinku nije moguće kopirati. Označite je i kopirajte ručno.',
      );
    } finally {
      this.isCopying.set(false);
    }
  }
}
