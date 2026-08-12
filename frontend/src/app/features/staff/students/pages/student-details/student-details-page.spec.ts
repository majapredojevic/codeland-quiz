import { HttpErrorResponse } from '@angular/common/http';
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MatDialog } from '@angular/material/dialog';
import { ActivatedRoute, convertToParamMap, provideRouter } from '@angular/router';
import { Observable, of } from 'rxjs';

import { ConfirmDialog } from '../../../../../shared/feedback/confirm-dialog/confirm-dialog';
import { NotificationService } from '../../../../../shared/feedback/notification.service';
import type { Student, UpdateStudentRequest } from '../../data-access/students.models';
import { StudentsStore } from '../../data-access/students.store';
import { StudentDetailsPage } from './student-details-page';

class FakeStudentsStore {
  readonly detail = signal<Student | null>(null);
  readonly detailLoading = signal(false);
  readonly detailError = signal<string | null>(null);

  loadedStudent: Student | null = null;

  readonly loadDetail = vi.fn(async (_id: number): Promise<void> => {
    this.detailLoading.set(true);
    await Promise.resolve();
    this.detail.set(this.loadedStudent);
    this.detailLoading.set(false);
  });

  readonly clearDetail = vi.fn(() => {
    this.detail.set(null);
    this.detailError.set(null);
  });

  readonly update = vi.fn(async (_id: number, request: UpdateStudentRequest): Promise<Student> => {
    const currentStudent = this.requireDetail();
    const updatedStudent: Student = { ...currentStudent, ...request };
    this.detail.set(updatedStudent);

    return updatedStudent;
  });

  readonly activate = vi.fn(async (_id: number): Promise<Student> => {
    const student = { ...this.requireDetail(), isActive: true };
    this.detail.set(student);

    return student;
  });

  readonly deactivate = vi.fn(async (_id: number): Promise<Student> => {
    const student = { ...this.requireDetail(), isActive: false };
    this.detail.set(student);

    return student;
  });

  private requireDetail(): Student {
    const student = this.detail();

    if (student === null) {
      throw new Error('Expected loaded student detail.');
    }

    return student;
  }
}

describe('StudentDetailsPage', () => {
  const student: Student = {
    id: 42,
    firstName: 'Ana',
    lastName: 'Anić',
    username: 'ana.anic',
    isActive: true,
    createdAt: '2026-08-01 08:30:00',
    updatedAt: '2026-08-02 09:45:00',
  };

  let fixture: ComponentFixture<StudentDetailsPage>;
  let studentsStore: FakeStudentsStore;
  let openDialog: ReturnType<typeof vi.fn>;
  let notifications: {
    success: ReturnType<typeof vi.fn>;
    error: ReturnType<typeof vi.fn>;
    info: ReturnType<typeof vi.fn>;
  };

  beforeEach(async () => {
    studentsStore = new FakeStudentsStore();
    studentsStore.loadedStudent = student;
    openDialog = vi.fn();
    notifications = {
      success: vi.fn(),
      error: vi.fn(),
      info: vi.fn(),
    };

    await TestBed.configureTestingModule({
      imports: [StudentDetailsPage],
      providers: [
        provideRouter([]),
        {
          provide: ActivatedRoute,
          useValue: {
            snapshot: {
              paramMap: convertToParamMap({ id: String(student.id) }),
            },
          },
        },
        { provide: StudentsStore, useValue: studentsStore },
        { provide: MatDialog, useValue: { open: openDialog } },
        { provide: NotificationService, useValue: notifications },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(StudentDetailsPage);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  });

  afterEach(() => {
    fixture.destroy();
    vi.restoreAllMocks();
  });

  it('loads a direct URL by ID and renders student context, fields, and status', async () => {
    expect(studentsStore.loadDetail).toHaveBeenCalledOnce();
    expect(studentsStore.loadDetail).toHaveBeenCalledWith(student.id);

    await vi.waitFor(() => {
      fixture.detectChanges();
      expect(input('#student-first-name').value).toBe(student.firstName);
    });

    expect(input('#student-last-name').value).toBe(student.lastName);
    expect(input('#student-username').value).toBe(student.username);
    expect(pageText()).toContain('Ana Anić');
    expect(pageText()).toContain('@ana.anic');
    expect(pageText()).toContain('Aktivan');
    expect(pageText()).toContain('Podaci učenika');
    expect(pageText()).toContain('Izmijenite osnovne podatke učenika.');
  });

  it('renders one deterministic Učenici back link', () => {
    const backLinks = fixture.nativeElement.querySelectorAll(
      'a.back-link',
    ) as NodeListOf<HTMLAnchorElement>;

    expect(backLinks).toHaveLength(1);
    expect(backLinks[0].textContent?.trim()).toBe('Učenici');
    expect(backLinks[0].getAttribute('href')).toBe('/app/students');
  });

  it('saves normalized values, renders the canonical response, and announces success', async () => {
    const canonicalStudent: Student = {
      ...student,
      firstName: 'Ana-Marija',
      lastName: 'Jović',
      username: 'ana.server',
      updatedAt: '2026-08-12 11:00:00',
    };
    studentsStore.update.mockImplementationOnce(async () => {
      studentsStore.detail.set(canonicalStudent);
      return canonicalStudent;
    });

    setInputValue('#student-first-name', '  Ana Marija  ');
    setInputValue('#student-last-name', '  JOVIĆ  ');
    setInputValue('#student-username', '  ANA.NOVA  ');
    fixture.detectChanges();

    button('Sačuvaj izmjene').click();
    await fixture.whenStable();
    fixture.detectChanges();

    expect(studentsStore.update).toHaveBeenCalledWith(student.id, {
      firstName: 'Ana Marija',
      lastName: 'JOVIĆ',
      username: 'ana.nova',
    });
    expect(input('#student-first-name').value).toBe(canonicalStudent.firstName);
    expect(input('#student-last-name').value).toBe(canonicalStudent.lastName);
    expect(input('#student-username').value).toBe(canonicalStudent.username);
    expect(notifications.success).toHaveBeenCalledWith('Izmjene su sačuvane.');
  });

  it('restores all canonical fields when editing is cancelled', () => {
    setInputValue('#student-first-name', 'Promijenjeno');
    setInputValue('#student-last-name', 'Prezime');
    setInputValue('#student-username', 'promijenjeno.ime');
    fixture.detectChanges();

    button('Otkaži').click();
    fixture.detectChanges();

    expect(input('#student-first-name').value).toBe(student.firstName);
    expect(input('#student-last-name').value).toBe(student.lastName);
    expect(input('#student-username').value).toBe(student.username);
    expect(studentsStore.update).not.toHaveBeenCalled();
  });

  it('does not expose delete or results actions', () => {
    const actionLabels = Array.from(
      fixture.nativeElement.querySelectorAll('button, a') as NodeListOf<HTMLElement>,
    ).map((element) => element.textContent?.trim());

    expect(actionLabels).not.toContain('Obriši učenika');
    expect(actionLabels).not.toContain('Obriši nalog');
    expect(actionLabels).not.toContain('Rezultati');
  });

  it('requires deactivation confirmation, honors cancel, then updates status on confirm', async () => {
    openDialog.mockReturnValueOnce(dialogResult(false));

    button('Deaktiviraj nalog').click();
    await fixture.whenStable();

    expect(studentsStore.deactivate).not.toHaveBeenCalled();
    expect(openDialog).toHaveBeenCalledWith(
      ConfirmDialog,
      expect.objectContaining({
        data: {
          title: 'Deaktivirati učenika?',
          message:
            '"Ana Anić" više neće moći da učestvuje kao registrovani učenik.\nPostojeći podaci i rezultati biće sačuvani.',
          confirmLabel: 'Deaktiviraj',
          tone: 'destructive',
        },
      }),
    );

    openDialog.mockReturnValueOnce(dialogResult(true));
    button('Deaktiviraj nalog').click();
    await fixture.whenStable();
    fixture.detectChanges();

    expect(studentsStore.deactivate).toHaveBeenCalledOnce();
    expect(studentsStore.deactivate).toHaveBeenCalledWith(student.id);
    expect(studentsStore.detail()?.isActive).toBe(false);
    expect(notifications.success).toHaveBeenCalledWith('Učenik je deaktiviran.');
    expect(button('Aktiviraj nalog')).toBeTruthy();
  });

  it('activates an inactive student without confirmation', async () => {
    studentsStore.detail.set({ ...student, isActive: false });
    fixture.detectChanges();

    expect(pageText()).toContain('Učenik trenutno ne može učestvovati kao registrovani učesnik.');
    button('Aktiviraj nalog').click();
    await fixture.whenStable();
    fixture.detectChanges();

    expect(openDialog).not.toHaveBeenCalled();
    expect(studentsStore.activate).toHaveBeenCalledWith(student.id);
    expect(studentsStore.detail()?.isActive).toBe(true);
    expect(notifications.success).toHaveBeenCalledWith('Učenik je aktiviran.');
  });

  it('maps a username conflict to the field without exposing a backend error', async () => {
    studentsStore.update.mockRejectedValueOnce(
      new HttpErrorResponse({
        status: 409,
        error: { error: 'StudentUsernameAlreadyExistsException at /var/www/backend' },
      }),
    );
    setInputValue('#student-username', 'zauzeto.ime');
    fixture.detectChanges();

    button('Sačuvaj izmjene').click();
    await fixture.whenStable();
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('#student-username-error')?.textContent).toContain(
      'Učenik sa ovim korisničkim imenom već postoji.',
    );
    expect(pageText()).not.toContain('StudentUsernameAlreadyExistsException');
    expect(notifications.success).not.toHaveBeenCalled();
  });

  it('shows safe save and lifecycle errors', async () => {
    studentsStore.update.mockRejectedValueOnce(new Error('database credentials'));
    setInputValue('#student-first-name', 'Nova');
    fixture.detectChanges();

    button('Sačuvaj izmjene').click();
    await fixture.whenStable();
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('[role="alert"]')?.textContent).toContain(
      'Nije moguće sačuvati izmjene. Pokušajte ponovo.',
    );
    expect(pageText()).not.toContain('database credentials');

    studentsStore.detail.set({ ...student, isActive: false });
    studentsStore.activate.mockRejectedValueOnce(new Error('internal stack'));
    fixture.detectChanges();
    button('Aktiviraj nalog').click();
    await fixture.whenStable();

    expect(notifications.error).toHaveBeenCalledWith('Nije moguće aktivirati učenika.');
    expect(notifications.error.mock.calls.flat().join(' ')).not.toContain('internal stack');
  });

  it('prevents duplicate save and activation requests while each mutation is pending', async () => {
    let resolveSave!: (value: Student) => void;
    const pendingSave = new Promise<Student>((resolve) => (resolveSave = resolve));
    studentsStore.update.mockReturnValueOnce(pendingSave);
    setInputValue('#student-first-name', 'Nova');
    fixture.detectChanges();

    button('Sačuvaj izmjene').click();
    button('Sačuvaj izmjene').click();

    expect(studentsStore.update).toHaveBeenCalledOnce();
    resolveSave(student);
    await fixture.whenStable();

    studentsStore.detail.set({ ...student, isActive: false });
    let resolveActivation!: (value: Student) => void;
    const pendingActivation = new Promise<Student>((resolve) => (resolveActivation = resolve));
    studentsStore.activate.mockReturnValueOnce(pendingActivation);
    fixture.detectChanges();

    button('Aktiviraj nalog').click();
    button('Aktiviraj nalog').click();

    expect(studentsStore.activate).toHaveBeenCalledOnce();
    resolveActivation({ ...student, isActive: true });
    await fixture.whenStable();
  });

  function pageText(): string {
    return fixture.nativeElement.textContent as string;
  }

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
