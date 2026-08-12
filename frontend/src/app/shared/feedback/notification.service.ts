import { BreakpointObserver } from '@angular/cdk/layout';
import { Service, inject } from '@angular/core';
import { MatSnackBar, MatSnackBarConfig } from '@angular/material/snack-bar';

type NotificationTone = 'success' | 'error' | 'info';

const NARROW_VIEWPORT = '(max-width: 47.5rem)';

@Service()
export class NotificationService {
  private readonly snackBar = inject(MatSnackBar);
  private readonly breakpoints = inject(BreakpointObserver);

  success(message: string): void {
    this.open(message, 'success', 3_500);
  }

  error(message: string): void {
    this.open(message, 'error', 5_500);
  }

  info(message: string): void {
    this.open(message, 'info', 3_500);
  }

  private open(message: string, tone: NotificationTone, duration: number): void {
    const config: MatSnackBarConfig = {
      duration,
      horizontalPosition: this.breakpoints.isMatched(NARROW_VIEWPORT) ? 'center' : 'right',
      verticalPosition: 'bottom',
      panelClass: ['clq-snackbar', `clq-snackbar--${tone}`],
      politeness: tone === 'error' ? 'assertive' : 'polite',
    };

    this.snackBar.open(message, undefined, config);
  }
}
