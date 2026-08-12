import { TestBed } from '@angular/core/testing';
import { MatDialog } from '@angular/material/dialog';
import { Router, provideRouter } from '@angular/router';
import { Subject, of } from 'rxjs';

import { NotificationService } from '../../../../../shared/feedback/notification.service';
import { TemporaryPasswordDialog } from '../../components/temporary-password-dialog/temporary-password-dialog';
import { UsersApiService } from '../../data-access/users-api.service';
import { CreateUserResponse } from '../../data-access/users.models';
import { UsersStore } from '../../data-access/users.store';
import { UserCreatePage } from './user-create-page';

describe('UserCreatePage', () => {
  const createResponse: CreateUserResponse = {
    user: {
      id: 17,
      name: 'Milica Milić',
      email: 'milica@example.com',
      role: 'TEACHER',
    },
    temporaryPassword: 'OneTime7!Secret',
  };

  let apiCreate: ReturnType<typeof vi.fn>;
  let dialogOpen: ReturnType<typeof vi.fn>;
  let dialogClosed: Subject<boolean>;
  let navigateByUrl: ReturnType<typeof vi.spyOn>;
  let usersStore: UsersStore;
  let notifySuccess: ReturnType<typeof vi.fn>;

  beforeEach(async () => {
    apiCreate = vi.fn().mockReturnValue(of(createResponse));
    dialogClosed = new Subject<boolean>();
    dialogOpen = vi.fn().mockReturnValue({
      afterClosed: () => dialogClosed.asObservable(),
    });
    notifySuccess = vi.fn();

    await TestBed.configureTestingModule({
      imports: [UserCreatePage],
      providers: [
        provideRouter([]),
        { provide: UsersApiService, useValue: { create: apiCreate } },
        { provide: MatDialog, useValue: { open: dialogOpen } },
        {
          provide: NotificationService,
          useValue: { success: notifySuccess, error: vi.fn(), info: vi.fn() },
        },
      ],
    }).compileComponents();

    usersStore = TestBed.inject(UsersStore);
    navigateByUrl = vi.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  function createPage() {
    const fixture = TestBed.createComponent(UserCreatePage);
    fixture.detectChanges();

    return { fixture, element: fixture.nativeElement as HTMLElement };
  }

  function inputFor(element: HTMLElement, labelText: string): HTMLInputElement {
    const label = Array.from(element.querySelectorAll('label')).find(
      (candidate) => candidate.textContent?.trim() === labelText,
    );
    const input = label?.htmlFor
      ? element.querySelector<HTMLInputElement>(`#${label.htmlFor}`)
      : null;

    if (!input) {
      throw new Error(`Input labelled "${labelText}" was not rendered.`);
    }

    return input;
  }

  function enter(input: HTMLInputElement, value: string): void {
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function submit(element: HTMLElement): void {
    element
      .querySelector('form')
      ?.dispatchEvent(new SubmitEvent('submit', { bubbles: true, cancelable: true }));
  }

  async function flushMicrotasks(count = 4): Promise<void> {
    for (let index = 0; index < count; index += 1) {
      await Promise.resolve();
    }
  }

  async function closeResultDialog(): Promise<void> {
    dialogClosed.next(true);
    dialogClosed.complete();
    await flushMicrotasks();
  }

  it('validates required name and email fields before creating a user', () => {
    const { fixture, element } = createPage();

    submit(element);
    fixture.detectChanges();

    expect(element.textContent).toContain('Unesite ime i prezime.');
    expect(element.textContent).toContain('Unesite email adresu.');
    expect(apiCreate).not.toHaveBeenCalled();

    enter(inputFor(element, 'Ime i prezime'), 'Milica Milić');
    enter(inputFor(element, 'Email'), 'nije-email');
    submit(element);
    fixture.detectChanges();

    expect(element.textContent).toContain('Unesite ispravnu email adresu.');
    expect(apiCreate).not.toHaveBeenCalled();
  });

  it('normalizes outer name whitespace and lowercases a valid email before submission', async () => {
    const { element } = createPage();

    enter(inputFor(element, 'Ime i prezime'), '  Milica Milić  ');
    enter(inputFor(element, 'Email'), '  MILICA@EXAMPLE.COM  ');
    submit(element);
    await flushMicrotasks();

    expect(apiCreate).toHaveBeenCalledOnce();
    expect(apiCreate).toHaveBeenCalledWith({
      name: 'Milica Milić',
      email: 'milica@example.com',
    });

    await closeResultDialog();
  });

  it('prevents duplicate creation while the first request is pending', async () => {
    const creation = new Subject<CreateUserResponse>();
    apiCreate.mockReturnValue(creation.asObservable());
    const { fixture, element } = createPage();

    enter(inputFor(element, 'Ime i prezime'), 'Milica Milić');
    enter(inputFor(element, 'Email'), 'milica@example.com');
    submit(element);
    submit(element);
    fixture.detectChanges();

    expect(apiCreate).toHaveBeenCalledOnce();
    expect(element.querySelector<HTMLButtonElement>('button[type="submit"]')?.disabled).toBe(true);

    creation.next(createResponse);
    creation.complete();
    await flushMicrotasks();
    await closeResultDialog();
  });

  it('shows the one-time password in a persistent dialog without retaining it in the store', async () => {
    const storageWrite = vi.spyOn(Storage.prototype, 'setItem');
    const { fixture, element } = createPage();

    enter(inputFor(element, 'Ime i prezime'), createResponse.user.name);
    enter(inputFor(element, 'Email'), createResponse.user.email);
    submit(element);
    await flushMicrotasks();

    expect(dialogOpen).toHaveBeenCalledWith(
      TemporaryPasswordDialog,
      expect.objectContaining({
        data: {
          title: 'Korisnik je uspješno kreiran',
          label: 'Privremena lozinka',
          password: createResponse.temporaryPassword,
          supportingMessage: 'Korisnik će morati promijeniti lozinku pri prvoj prijavi.',
        },
        disableClose: true,
        width: '32rem',
        maxWidth: 'calc(100vw - 2rem)',
        panelClass: 'clq-dialog-panel',
      }),
    );
    expect(usersStore.users()).toEqual([]);
    expect(usersStore.detail()).toBeNull();
    expect(storageWrite).not.toHaveBeenCalled();

    await closeResultDialog();
    fixture.detectChanges();

    expect(usersStore.users()).toEqual([]);
    expect(usersStore.detail()).toBeNull();
    expect(navigateByUrl).toHaveBeenCalledWith('/app/users');
    expect(notifySuccess).toHaveBeenCalledWith('Korisnik je uspješno kreiran.');
    expect(
      (
        fixture.componentInstance as unknown as {
          temporaryPasswordDialogRef: unknown;
        }
      ).temporaryPasswordDialogRef,
    ).toBeNull();
  });

  it('links cancel deterministically to the users list', () => {
    const { element } = createPage();
    const cancel = Array.from(element.querySelectorAll<HTMLAnchorElement>('a')).find(
      (link) => link.textContent?.trim() === 'Otkaži',
    );

    expect(cancel?.getAttribute('href')).toBe('/app/users');
  });
});
