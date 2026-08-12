import { TestBed } from '@angular/core/testing';
import { MAT_DIALOG_DATA, MatDialogRef } from '@angular/material/dialog';

import { ConfirmDialog, ConfirmDialogData } from './confirm-dialog';

describe('ConfirmDialog', () => {
  let dialogData: ConfirmDialogData;
  let close: ReturnType<typeof vi.fn>;

  beforeEach(async () => {
    dialogData = {
      title: 'Deaktivirati korisnika?',
      message:
        '„Milica Milić“ više neće moći da se prijavi u aplikaciju.\nPostojeći podaci i istorija biće sačuvani.',
      confirmLabel: 'Deaktiviraj',
      tone: 'destructive',
    };
    close = vi.fn();

    await TestBed.configureTestingModule({
      imports: [ConfirmDialog],
      providers: [
        { provide: MAT_DIALOG_DATA, useFactory: () => dialogData },
        { provide: MatDialogRef, useValue: { close } },
      ],
    }).compileComponents();
  });

  function createDialog(): HTMLElement {
    const fixture = TestBed.createComponent(ConfirmDialog);
    fixture.detectChanges();

    return fixture.nativeElement as HTMLElement;
  }

  it('renders a meaningful title, message, and explicitly named actions', () => {
    const element = createDialog();
    const buttons = Array.from(element.querySelectorAll<HTMLButtonElement>('button'));

    expect(element.querySelector('h2[mat-dialog-title]')?.textContent).toContain(dialogData.title);
    expect(element.querySelector('mat-dialog-content')?.textContent).toContain('Milica Milić');
    expect(buttons.map((button) => button.textContent?.trim())).toEqual(['Otkaži', 'Deaktiviraj']);
    expect(buttons.every((button) => button.type === 'button')).toBe(true);
  });

  it('applies the restrained destructive treatment only when requested', () => {
    const destructive = createDialog().querySelector<HTMLButtonElement>('.confirm-button');

    expect(destructive?.classList).toContain('confirm-button--destructive');

    dialogData.tone = 'default';
    const defaultAction = createDialog().querySelector<HTMLButtonElement>('.confirm-button');

    expect(defaultAction?.classList).not.toContain('confirm-button--destructive');
  });

  it('returns false on cancel and true on confirmation', () => {
    const element = createDialog();
    const buttons = element.querySelectorAll<HTMLButtonElement>('button');

    buttons[0]?.click();
    buttons[1]?.click();

    expect(close).toHaveBeenNthCalledWith(1, false);
    expect(close).toHaveBeenNthCalledWith(2, true);
  });
});
