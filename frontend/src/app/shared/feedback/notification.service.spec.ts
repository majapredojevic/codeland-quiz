import { BreakpointObserver } from '@angular/cdk/layout';
import { TestBed } from '@angular/core/testing';
import { MatSnackBar } from '@angular/material/snack-bar';

import { NotificationService } from './notification.service';

describe('NotificationService', () => {
  const open = vi.fn();
  const isMatched = vi.fn().mockReturnValue(false);

  beforeEach(() => {
    open.mockReset();
    isMatched.mockReset().mockReturnValue(false);
    TestBed.configureTestingModule({
      providers: [
        NotificationService,
        { provide: MatSnackBar, useValue: { open } },
        { provide: BreakpointObserver, useValue: { isMatched } },
      ],
    });
  });

  it.each([
    ['success', 'Sačuvano.', 3_500, 'polite'],
    ['info', 'Informacija.', 3_500, 'polite'],
    ['error', 'Pokušajte ponovo.', 5_500, 'assertive'],
  ] as const)(
    'opens a readable %s notification with semantic configuration',
    (tone, message, duration, politeness) => {
      const service = TestBed.inject(NotificationService);

      service[tone](message);

      expect(open).toHaveBeenCalledWith(
        message,
        undefined,
        expect.objectContaining({
          duration,
          horizontalPosition: 'right',
          verticalPosition: 'bottom',
          panelClass: ['clq-snackbar', `clq-snackbar--${tone}`],
          politeness,
        }),
      );
    },
  );

  it('centers notifications on narrow viewports', () => {
    isMatched.mockReturnValue(true);

    TestBed.inject(NotificationService).success('Sačuvano.');

    expect(open).toHaveBeenCalledWith(
      'Sačuvano.',
      undefined,
      expect.objectContaining({ horizontalPosition: 'center' }),
    );
  });
});
