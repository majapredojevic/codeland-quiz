import { TestBed } from '@angular/core/testing';
import { MAT_DIALOG_DATA, MatDialogRef } from '@angular/material/dialog';

import { NotificationService } from '../../../../../shared/feedback/notification.service';
import { TemporaryPasswordDialog, TemporaryPasswordDialogData } from './temporary-password-dialog';

describe('TemporaryPasswordDialog', () => {
  const dialogData: TemporaryPasswordDialogData = {
    title: 'Korisnik je uspješno kreiran',
    password: 'OneTime7!Secret',
    label: 'Privremena lozinka',
    supportingMessage: 'Korisnik će morati promijeniti lozinku pri prvoj prijavi.',
  };

  let close: ReturnType<typeof vi.fn>;
  let dialogRef: { close: ReturnType<typeof vi.fn>; disableClose: boolean };
  let writeText: ReturnType<typeof vi.fn>;
  let success: ReturnType<typeof vi.fn>;
  let error: ReturnType<typeof vi.fn>;
  let originalClipboard: PropertyDescriptor | undefined;

  beforeEach(async () => {
    close = vi.fn();
    dialogRef = { close, disableClose: false };
    writeText = vi.fn().mockResolvedValue(undefined);
    success = vi.fn();
    error = vi.fn();
    originalClipboard = Object.getOwnPropertyDescriptor(navigator, 'clipboard');
    Object.defineProperty(navigator, 'clipboard', {
      configurable: true,
      value: { writeText },
    });

    await TestBed.configureTestingModule({
      imports: [TemporaryPasswordDialog],
      providers: [
        { provide: MAT_DIALOG_DATA, useValue: dialogData },
        { provide: MatDialogRef, useValue: dialogRef },
        { provide: NotificationService, useValue: { success, error, info: vi.fn() } },
      ],
    }).compileComponents();
  });

  afterEach(() => {
    if (originalClipboard === undefined) {
      Reflect.deleteProperty(navigator, 'clipboard');
    } else {
      Object.defineProperty(navigator, 'clipboard', originalClipboard);
    }

    vi.restoreAllMocks();
  });

  function createDialog() {
    const fixture = TestBed.createComponent(TemporaryPasswordDialog);
    fixture.detectChanges();

    return { fixture, element: fixture.nativeElement as HTMLElement };
  }

  it('renders the sensitive result only from ephemeral dialog data', () => {
    const localStorageWrite = vi.spyOn(Storage.prototype, 'setItem');
    const { element } = createDialog();

    expect(element.querySelector('h2[mat-dialog-title]')?.textContent).toContain(dialogData.title);
    expect(element.querySelector('.password-label')?.textContent).toContain(dialogData.label);
    expect(element.querySelector('.temporary-password')?.textContent).toBe(dialogData.password);
    expect(element.querySelector('.supporting-message')?.textContent).toContain(
      dialogData.supportingMessage,
    );
    expect(localStorageWrite).not.toHaveBeenCalled();
    expect(dialogRef.disableClose).toBe(true);
  });

  it('copies the temporary password and announces success without exposing it', async () => {
    const { fixture, element } = createDialog();

    element.querySelector<HTMLButtonElement>('.copy-button')?.click();
    await fixture.whenStable();

    expect(writeText).toHaveBeenCalledOnce();
    expect(writeText).toHaveBeenCalledWith(dialogData.password);
    expect(success).toHaveBeenCalledWith('Privremena lozinka je kopirana.');
    expect(success.mock.calls.flat().join(' ')).not.toContain(dialogData.password);
  });

  it('provides safe feedback when clipboard access fails', async () => {
    writeText.mockRejectedValue(new DOMException('Permission denied', 'NotAllowedError'));
    const { fixture, element } = createDialog();

    element.querySelector<HTMLButtonElement>('.copy-button')?.click();
    await fixture.whenStable();

    expect(error).toHaveBeenCalledWith(
      'Privremenu lozinku nije moguće kopirati. Označite je i kopirajte ručno.',
    );
    expect(error.mock.calls.flat().join(' ')).not.toContain(dialogData.password);
  });

  it('closes only through an explicitly named button', () => {
    const { element } = createDialog();
    const closeButton = element.querySelector<HTMLButtonElement>('.close-button');

    expect(closeButton?.textContent?.trim()).toBe('Zatvori');
    expect(closeButton?.type).toBe('button');
    closeButton?.click();

    expect(close).toHaveBeenCalledOnce();
    expect(close).toHaveBeenCalledWith(true);
  });
});
