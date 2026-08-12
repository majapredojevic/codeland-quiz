import { Component, inject } from '@angular/core';
import {
  MAT_DIALOG_DATA,
  MatDialogActions,
  MatDialogClose,
  MatDialogContent,
  MatDialogTitle,
} from '@angular/material/dialog';

export type UserConfirmDialogTone = 'default' | 'destructive';

export interface UserConfirmDialogData {
  title: string;
  message: string;
  confirmLabel: string;
  tone: UserConfirmDialogTone;
}

@Component({
  selector: 'clq-user-confirm-dialog',
  imports: [MatDialogActions, MatDialogClose, MatDialogContent, MatDialogTitle],
  templateUrl: './user-confirm-dialog.html',
  styleUrl: './user-confirm-dialog.scss',
})
export class UserConfirmDialog {
  protected readonly data = inject<UserConfirmDialogData>(MAT_DIALOG_DATA);
}
