import { Component, inject } from '@angular/core';
import {
  MAT_DIALOG_DATA,
  MatDialogActions,
  MatDialogClose,
  MatDialogContent,
  MatDialogTitle,
} from '@angular/material/dialog';

export type ConfirmDialogTone = 'default' | 'destructive';

export interface ConfirmDialogData {
  title: string;
  message: string;
  confirmLabel: string;
  tone: ConfirmDialogTone;
}

@Component({
  selector: 'clq-confirm-dialog',
  imports: [MatDialogActions, MatDialogClose, MatDialogContent, MatDialogTitle],
  templateUrl: './confirm-dialog.html',
  styleUrl: './confirm-dialog.scss',
})
export class ConfirmDialog {
  protected readonly data = inject<ConfirmDialogData>(MAT_DIALOG_DATA);
}
