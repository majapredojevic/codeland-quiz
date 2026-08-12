import { HttpErrorResponse } from '@angular/common/http';
import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';

import { NotificationService } from '../../../../../shared/feedback/notification.service';
import { StudentDetail } from '../../data-access/students.models';
import { StudentsStore } from '../../data-access/students.store';
import { StudentCreatePage } from './student-create-page';

describe('StudentCreatePage', () => {
  const student: StudentDetail = {
    id: 17,
    firstName: 'Milica',
    lastName: 'Milić',
    username: 'milica.milic',
    isActive: true,
    createdAt: '2026-08-12T10:00:00+00:00',
    updatedAt: '2026-08-12T10:00:00+00:00',
  };

  let createStudent: ReturnType<typeof vi.fn>;
  let notifySuccess: ReturnType<typeof vi.fn>;
  let navigateByUrl: ReturnType<typeof vi.spyOn>;

  beforeEach(async () => {
    createStudent = vi.fn().mockResolvedValue(student);
    notifySuccess = vi.fn();

    await TestBed.configureTestingModule({
      imports: [StudentCreatePage],
      providers: [
        provideRouter([]),
        { provide: StudentsStore, useValue: { create: createStudent } },
        {
          provide: NotificationService,
          useValue: { success: notifySuccess, error: vi.fn(), info: vi.fn() },
        },
      ],
    }).compileComponents();

    navigateByUrl = vi.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  function createPage() {
    const fixture = TestBed.createComponent(StudentCreatePage);
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

  async function flushMicrotasks(count = 5): Promise<void> {
    for (let index = 0; index < count; index += 1) {
      await Promise.resolve();
    }
  }

  it('renders the required contract fields and deterministic students links', () => {
    const { element } = createPage();
    const links = Array.from(element.querySelectorAll<HTMLAnchorElement>('a'));

    expect(element.querySelector('h1')?.textContent?.trim()).toBe('Novi učenik');
    expect(element.textContent).toContain('Dodajte novog učenika u CodeLand Quiz.');
    expect(inputFor(element, 'Ime')).toBeTruthy();
    expect(inputFor(element, 'Prezime')).toBeTruthy();
    expect(inputFor(element, 'Korisničko ime')).toBeTruthy();
    expect(links.filter((link) => link.getAttribute('href') === '/app/students')).toHaveLength(2);
    expect(links.some((link) => link.textContent?.trim() === 'Učenici')).toBe(true);
    expect(links.some((link) => link.textContent?.trim() === 'Otkaži')).toBe(true);
  });

  it('shows localized validation and does not submit invalid values', () => {
    const { fixture, element } = createPage();

    submit(element);
    fixture.detectChanges();

    expect(element.textContent).toContain('Unesite ime.');
    expect(element.textContent).toContain('Unesite prezime.');
    expect(element.textContent).toContain('Korisničko ime mora sadržati između 3 i 80 znakova.');
    expect(createStudent).not.toHaveBeenCalled();

    enter(inputFor(element, 'Ime'), 'Ana');
    enter(inputFor(element, 'Prezime'), 'Anić');
    enter(inputFor(element, 'Korisničko ime'), 'ne važi');
    submit(element);
    fixture.detectChanges();

    expect(element.textContent).toContain('Koristite slova, brojeve');
    expect(createStudent).not.toHaveBeenCalled();
  });

  it('normalizes contract fields, notifies, and navigates to the created detail', async () => {
    const { element } = createPage();

    enter(inputFor(element, 'Ime'), '  Milica  ');
    enter(inputFor(element, 'Prezime'), '  Milić  ');
    enter(inputFor(element, 'Korisničko ime'), '  MILICA.MILIC  ');
    submit(element);
    await flushMicrotasks();

    expect(createStudent).toHaveBeenCalledOnce();
    expect(createStudent).toHaveBeenCalledWith({
      firstName: 'Milica',
      lastName: 'Milić',
      username: 'milica.milic',
    });
    expect(notifySuccess).toHaveBeenCalledWith('Učenik je uspješno dodat.');
    expect(navigateByUrl).toHaveBeenCalledWith('/app/students/17');
  });

  it('prevents duplicate creation while the first request is pending', async () => {
    let resolveCreation!: (value: StudentDetail) => void;
    createStudent.mockReturnValue(
      new Promise<StudentDetail>((resolve) => {
        resolveCreation = resolve;
      }),
    );
    const { fixture, element } = createPage();

    enter(inputFor(element, 'Ime'), 'Milica');
    enter(inputFor(element, 'Prezime'), 'Milić');
    enter(inputFor(element, 'Korisničko ime'), 'milica.milic');
    submit(element);
    submit(element);
    fixture.detectChanges();

    expect(createStudent).toHaveBeenCalledOnce();
    expect(element.querySelector<HTMLButtonElement>('button[type="submit"]')?.disabled).toBe(true);

    resolveCreation(student);
    await flushMicrotasks();
  });

  it('maps username conflicts to the field and other failures to a safe page error', async () => {
    createStudent.mockRejectedValueOnce(
      new HttpErrorResponse({ status: 409, statusText: 'Conflict' }),
    );
    const { fixture, element } = createPage();

    enter(inputFor(element, 'Ime'), 'Milica');
    enter(inputFor(element, 'Prezime'), 'Milić');
    enter(inputFor(element, 'Korisničko ime'), 'milica.milic');
    submit(element);
    await flushMicrotasks();
    fixture.detectChanges();

    expect(element.textContent).toContain('Korisničko ime je već zauzeto.');

    createStudent.mockRejectedValueOnce(new Error('database unavailable'));
    enter(inputFor(element, 'Korisničko ime'), 'milica.druga');
    submit(element);
    await flushMicrotasks();
    fixture.detectChanges();

    expect(element.querySelector('[role="alert"]')?.textContent).toContain(
      'Nije moguće dodati učenika. Pokušajte ponovo.',
    );
    expect(element.textContent).not.toContain('database unavailable');
  });
});
