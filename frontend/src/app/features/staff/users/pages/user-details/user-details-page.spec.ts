import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MatDialog } from '@angular/material/dialog';
import { ActivatedRoute, convertToParamMap, provideRouter } from '@angular/router';
import { Observable, of } from 'rxjs';

import { NotificationService } from '../../../../../shared/feedback/notification.service';
import { UserDetail, UpdateUserRequest } from '../../data-access/users.models';
import { UsersStore } from '../../data-access/users.store';
import { UserDetailsPage } from './user-details-page';

class FakeUsersStore {
  readonly detail = signal<UserDetail | null>(null);
  readonly detailLoading = signal(false);
  readonly detailError = signal<string | null>(null);

  loadedUser: UserDetail | null = null;

  readonly loadDetail = vi.fn(async (_id: number): Promise<void> => {
    this.detailLoading.set(true);
    await Promise.resolve();
    this.detail.set(this.loadedUser);
    this.detailLoading.set(false);
  });

  readonly update = vi.fn(async (_id: number, request: UpdateUserRequest): Promise<UserDetail> => {
    const currentUser = this.requireDetail();
    const updatedUser: UserDetail = {
      ...currentUser,
      ...(request.name === undefined ? {} : { name: request.name }),
      ...(request.email === undefined ? {} : { email: request.email }),
    };
    this.detail.set(updatedUser);

    return updatedUser;
  });

  readonly activate = vi.fn(async (_id: number): Promise<UserDetail> => {
    const user = { ...this.requireDetail(), isActive: true };
    this.detail.set(user);

    return user;
  });

  readonly deactivate = vi.fn(async (_id: number): Promise<UserDetail> => {
    const user = { ...this.requireDetail(), isActive: false };
    this.detail.set(user);

    return user;
  });

  readonly resetPassword = vi.fn(async (_id: number) => {
    const user = { ...this.requireDetail(), mustChangePassword: true };
    this.detail.set(user);

    return {
      user,
      temporaryPassword: 'Temporary2!',
    };
  });

  private requireDetail(): UserDetail {
    const user = this.detail();

    if (user === null) {
      throw new Error('Expected loaded user detail.');
    }

    return user;
  }
}

describe('UserDetailsPage', () => {
  const teacher: UserDetail = {
    id: 42,
    name: 'Ana Anić',
    email: 'ana@example.com',
    role: 'TEACHER',
    isActive: true,
    mustChangePassword: false,
  };

  let fixture: ComponentFixture<UserDetailsPage>;
  let usersStore: FakeUsersStore;
  let openDialog: ReturnType<typeof vi.fn>;
  let notifications: {
    success: ReturnType<typeof vi.fn>;
    error: ReturnType<typeof vi.fn>;
    info: ReturnType<typeof vi.fn>;
  };

  beforeEach(async () => {
    usersStore = new FakeUsersStore();
    usersStore.loadedUser = teacher;
    openDialog = vi.fn();
    notifications = {
      success: vi.fn(),
      error: vi.fn(),
      info: vi.fn(),
    };

    await TestBed.configureTestingModule({
      imports: [UserDetailsPage],
      providers: [
        provideRouter([]),
        {
          provide: ActivatedRoute,
          useValue: {
            snapshot: {
              paramMap: convertToParamMap({ id: String(teacher.id) }),
            },
          },
        },
        { provide: UsersStore, useValue: usersStore },
        { provide: MatDialog, useValue: { open: openDialog } },
        { provide: NotificationService, useValue: notifications },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(UserDetailsPage);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  });

  it('loads a direct URL by ID and renders the backend-editable fields', async () => {
    expect(usersStore.loadDetail).toHaveBeenCalledOnce();
    expect(usersStore.loadDetail).toHaveBeenCalledWith(teacher.id);

    await vi.waitFor(() => {
      fixture.detectChanges();
      expect(input('#user-name').value).toBe(teacher.name);
    });

    expect(input('#user-email').value).toBe(teacher.email);
    expect(fixture.nativeElement.textContent).toContain('Uloga');
    expect(fixture.nativeElement.textContent).toContain('TEACHER');
  });

  it('renders one deterministic users back link without the old breadcrumb', () => {
    const backLinks = fixture.nativeElement.querySelectorAll(
      'a.back-link',
    ) as NodeListOf<HTMLAnchorElement>;
    const title = fixture.nativeElement.querySelector(
      'h1#user-details-title',
    ) as HTMLHeadingElement | null;

    expect(backLinks).toHaveLength(1);
    expect(backLinks[0].textContent?.trim()).toBe('Korisnici');
    expect(backLinks[0].getAttribute('href')).toBe('/app/users');
    expect(fixture.nativeElement.querySelector('.breadcrumb')).toBeNull();
    expect(title?.textContent?.trim()).toBe(teacher.name);
    expect(fixture.nativeElement.textContent).toContain(teacher.email);
    expect(fixture.nativeElement.textContent).toContain(teacher.role);
    expect(fixture.nativeElement.textContent).toContain('Aktivan');
  });

  it('saves name and email, renders the canonical response, and announces success', async () => {
    setInputValue('#user-name', '  Ana Nova  ');
    setInputValue('#user-email', '  ANA.NOVA@EXAMPLE.COM  ');
    fixture.detectChanges();

    button('Sačuvaj izmjene').click();
    await fixture.whenStable();
    fixture.detectChanges();

    expect(usersStore.update).toHaveBeenCalledWith(teacher.id, {
      name: 'Ana Nova',
      email: 'ana.nova@example.com',
    });
    expect(input('#user-name').value).toBe('Ana Nova');
    expect(input('#user-email').value).toBe('ana.nova@example.com');
    expect(notifications.success).toHaveBeenCalledWith('Izmjene su sačuvane.');
  });

  it('does not expose a delete-user action', () => {
    const pageText = fixture.nativeElement.textContent as string;

    expect(pageText).not.toContain('Obriši korisnika');
    expect(pageText).not.toContain('Obriši nalog');
    expect(pageText).not.toContain('Delete');
  });

  it('requires deactivation confirmation, honors cancel, then updates status on confirm', async () => {
    openDialog.mockReturnValueOnce(dialogResult(false));

    button('Deaktiviraj nalog').click();
    await fixture.whenStable();

    expect(usersStore.deactivate).not.toHaveBeenCalled();
    expect(openDialog).toHaveBeenCalledOnce();
    expect(openDialog.mock.calls[0][1].data).toMatchObject({
      title: 'Deaktivirati korisnika?',
      confirmLabel: 'Deaktiviraj',
      tone: 'destructive',
    });

    openDialog.mockReturnValueOnce(dialogResult(true));
    button('Deaktiviraj nalog').click();
    await fixture.whenStable();
    fixture.detectChanges();

    expect(usersStore.deactivate).toHaveBeenCalledOnce();
    expect(usersStore.deactivate).toHaveBeenCalledWith(teacher.id);
    expect(usersStore.detail()?.isActive).toBe(false);
    expect(notifications.success).toHaveBeenCalledWith('Korisnik je deaktiviran.');
    expect(button('Aktiviraj nalog')).toBeTruthy();
  });

  it('activates an inactive user directly and updates the displayed state', async () => {
    usersStore.detail.set({ ...teacher, isActive: false });
    fixture.detectChanges();

    button('Aktiviraj nalog').click();
    await fixture.whenStable();
    fixture.detectChanges();

    expect(openDialog).not.toHaveBeenCalled();
    expect(usersStore.activate).toHaveBeenCalledWith(teacher.id);
    expect(usersStore.detail()?.isActive).toBe(true);
    expect(notifications.success).toHaveBeenCalledWith('Korisnik je aktiviran.');
    expect(button('Deaktiviraj nalog')).toBeTruthy();
  });

  it('requires reset confirmation, honors cancel, and shows an ephemeral password result', async () => {
    openDialog.mockReturnValueOnce(dialogResult(false));

    button('Resetuj lozinku').click();
    await fixture.whenStable();

    expect(usersStore.resetPassword).not.toHaveBeenCalled();
    expect(openDialog.mock.calls[0][1].data).toMatchObject({
      title: 'Resetovati lozinku?',
      confirmLabel: 'Resetuj lozinku',
    });

    openDialog.mockReturnValueOnce(dialogResult(true)).mockReturnValueOnce(dialogResult(undefined));
    button('Resetuj lozinku').click();
    await fixture.whenStable();
    fixture.detectChanges();

    expect(usersStore.resetPassword).toHaveBeenCalledOnce();
    expect(usersStore.resetPassword).toHaveBeenCalledWith(teacher.id);
    expect(openDialog).toHaveBeenCalledTimes(3);
    expect(openDialog.mock.calls[2][1]).toMatchObject({
      data: {
        title: 'Lozinka je uspješno resetovana',
        label: 'Nova privremena lozinka',
        password: 'Temporary2!',
      },
      disableClose: true,
    });
    expect(usersStore.detail()?.mustChangePassword).toBe(true);
    expect('temporaryPassword' in (usersStore.detail() as unknown as Record<string, unknown>)).toBe(
      false,
    );
  });

  function input(selector: string): HTMLInputElement {
    const element = fixture.nativeElement.querySelector(selector) as HTMLInputElement | null;

    if (element === null) {
      throw new Error(`Expected input ${selector}.`);
    }

    return element;
  }

  function setInputValue(selector: string, value: string): void {
    const element = input(selector);
    element.value = value;
    element.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function button(label: string): HTMLButtonElement {
    const element = Array.from(
      fixture.nativeElement.querySelectorAll('button') as NodeListOf<HTMLButtonElement>,
    ).find((candidate) => candidate.textContent?.includes(label));

    if (element === undefined) {
      throw new Error(`Expected button containing "${label}".`);
    }

    return element;
  }

  function dialogResult<T>(result: T): { afterClosed: () => Observable<T> } {
    return {
      afterClosed: () => of(result),
    };
  }
});
