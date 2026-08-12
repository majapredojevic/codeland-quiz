import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';

import { StudentDetail, StudentsPagination } from '../../data-access/students.models';
import { StudentsStore } from '../../data-access/students.store';
import { StudentsListPage } from './students-list-page';

@Component({ template: '' })
class EmptyPage {}

describe('StudentsListPage', () => {
  const activeStudent: StudentDetail = {
    id: 7,
    firstName: 'Ana',
    lastName: 'Anić',
    username: 'ana.anic',
    isActive: true,
    createdAt: '2026-08-01T10:00:00+00:00',
    updatedAt: '2026-08-01T10:00:00+00:00',
  };
  const inactiveStudent: StudentDetail = {
    ...activeStudent,
    id: 8,
    firstName: 'Maja',
    lastName: 'Majić',
    username: 'maja.majic',
    isActive: false,
  };

  const students = signal<StudentDetail[]>([]);
  const pagination = signal<StudentsPagination>({
    pageIndex: 0,
    pageSize: 10,
    totalItems: 0,
    totalPages: 0,
  });
  const loading = signal(false);
  const error = signal<string | null>(null);
  const search = signal('');
  const pageIndex = signal(0);
  const pageSize = signal(10);

  let loadPage: ReturnType<typeof vi.fn>;
  let setSearch: ReturnType<typeof vi.fn>;

  beforeEach(async () => {
    students.set([activeStudent, inactiveStudent]);
    pagination.set({ pageIndex: 0, pageSize: 10, totalItems: 2, totalPages: 1 });
    loading.set(false);
    error.set(null);
    search.set('');
    pageIndex.set(0);
    pageSize.set(10);

    loadPage = vi.fn(
      async (requestedPageIndex: number = pageIndex(), requestedPageSize: number = pageSize()) => {
        pageIndex.set(requestedPageIndex);
        pageSize.set(requestedPageSize);
        pagination.update((current) => ({
          ...current,
          pageIndex: requestedPageIndex,
          pageSize: requestedPageSize,
        }));
      },
    );
    setSearch = vi.fn(async (value: string) => {
      search.set(value.trim());
      pageIndex.set(0);
      pagination.update((current) => ({ ...current, pageIndex: 0 }));
    });

    await TestBed.configureTestingModule({
      imports: [StudentsListPage],
      providers: [
        provideRouter([
          { path: 'app/students/new', component: EmptyPage },
          { path: 'app/students/:id', component: EmptyPage },
        ]),
        {
          provide: StudentsStore,
          useValue: {
            students: students.asReadonly(),
            pagination: pagination.asReadonly(),
            loading: loading.asReadonly(),
            error: error.asReadonly(),
            search: search.asReadonly(),
            pageIndex: pageIndex.asReadonly(),
            pageSize: pageSize.asReadonly(),
            loadPage,
            setSearch,
          },
        },
      ],
    }).compileComponents();
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
  });

  function createPage() {
    const fixture = TestBed.createComponent(StudentsListPage);
    fixture.detectChanges();

    return { fixture, element: fixture.nativeElement as HTMLElement };
  }

  function searchInput(element: HTMLElement): HTMLInputElement {
    const input = element.querySelector<HTMLInputElement>('#students-search');

    if (!input) {
      throw new Error('Students search input was not rendered');
    }

    return input;
  }

  function enterSearch(input: HTMLInputElement, value: string): void {
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function pageSizeSelect(element: HTMLElement): HTMLSelectElement {
    const select = element.querySelector<HTMLSelectElement>('#students-page-size');

    if (!select) {
      throw new Error('Students page-size selector was not rendered');
    }

    return select;
  }

  it('renders the requested page content, controls, columns, and student results', () => {
    const { element } = createPage();
    const addLink = Array.from(element.querySelectorAll<HTMLAnchorElement>('a')).find((link) =>
      link.textContent?.includes('Dodaj učenika'),
    );
    const headings = Array.from(element.querySelectorAll('th')).map((heading) =>
      heading.textContent?.trim(),
    );
    const rows = Array.from(element.querySelectorAll<HTMLTableRowElement>('tbody tr'));

    expect(element.querySelector('h1')?.textContent?.trim()).toBe('Učenici');
    expect(element.querySelector('.page-header p')?.textContent?.trim()).toBe(
      'Upravljajte registrovanim učenicima.',
    );
    expect(searchInput(element).placeholder).toBe('Pretraži po imenu ili korisničkom imenu...');
    expect(searchInput(element).maxLength).toBe(100);
    expect(addLink?.getAttribute('href')).toBe('/app/students/new');
    expect(headings).toEqual(['Ime i prezime', 'Korisničko ime', 'Status']);
    expect(rows).toHaveLength(2);
    expect(rows[0].textContent).toContain('Ana Anić');
    expect(rows[0].textContent).toContain(activeStudent.username);
    expect(rows[0].textContent).toContain('Aktivan');
    expect(rows[1].textContent).toContain('Maja Majić');
    expect(rows[1].textContent).toContain('Neaktivan');
    expect(loadPage).toHaveBeenCalledOnce();
    expect(loadPage).toHaveBeenCalledWith();
  });

  it('uses a native link that makes each row accessible and navigable', async () => {
    const { fixture, element } = createPage();
    const row = element.querySelector<HTMLTableRowElement>('tbody tr');
    const link = row?.querySelector<HTMLAnchorElement>('a.student-link');

    expect(row?.hasAttribute('tabindex')).toBe(false);
    expect(link).toBeInstanceOf(HTMLAnchorElement);
    expect(link?.getAttribute('href')).toBe('/app/students/7');
    expect(link?.getAttribute('aria-label')).toBe('Otvori detalje učenika Ana Anić');

    link?.click();
    await fixture.whenStable();

    expect(TestBed.inject(Router).url).toBe('/app/students/7');
  });

  it('debounces server-side search and delegates the first-page reset to the store', () => {
    vi.useFakeTimers();
    pageIndex.set(2);
    pagination.set({ pageIndex: 2, pageSize: 10, totalItems: 23, totalPages: 3 });
    const { fixture, element } = createPage();
    const input = searchInput(element);

    enterSearch(input, 'Ana');
    vi.advanceTimersByTime(200);
    enterSearch(input, '  Ana Anić  ');
    vi.advanceTimersByTime(299);

    expect(setSearch).not.toHaveBeenCalled();

    vi.advanceTimersByTime(1);
    fixture.detectChanges();

    expect(setSearch).toHaveBeenCalledOnce();
    expect(setSearch).toHaveBeenCalledWith('  Ana Anić  ');
    expect(search()).toBe('Ana Anić');
    expect(pageIndex()).toBe(0);
  });

  it('clears a pending search without issuing its stale debounced value', () => {
    vi.useFakeTimers();
    const { fixture, element } = createPage();
    const input = searchInput(element);

    enterSearch(input, 'Ana');
    fixture.detectChanges();
    element.querySelector<HTMLButtonElement>('.clear-search')?.click();
    fixture.detectChanges();
    vi.advanceTimersByTime(300);

    expect(input.value).toBe('');
    expect(setSearch).toHaveBeenCalledOnce();
    expect(setSearch).toHaveBeenCalledWith('');
  });

  it('cancels a pending search when the page is destroyed', () => {
    vi.useFakeTimers();
    const { fixture, element } = createPage();

    enterSearch(searchInput(element), 'Ana');
    fixture.destroy();
    vi.advanceTimersByTime(300);

    expect(setSearch).not.toHaveBeenCalled();
  });

  it('requests adjacent pages with the active size while preserving search', () => {
    search.set('Ana');
    pageIndex.set(1);
    pageSize.set(5);
    pagination.set({ pageIndex: 1, pageSize: 5, totalItems: 13, totalPages: 3 });
    const { fixture, element } = createPage();
    const previous = element.querySelector<HTMLButtonElement>(
      'button[aria-label="Prethodna stranica"]',
    );
    const next = element.querySelector<HTMLButtonElement>('button[aria-label="Sljedeća stranica"]');

    expect(element.querySelector('.pagination-summary > p')?.textContent).toContain('6–10 od 13');
    expect(element.querySelector('.current-page')?.textContent?.replace(/\s/g, '')).toBe('2/3');
    loadPage.mockClear();

    previous?.click();
    expect(loadPage).toHaveBeenLastCalledWith(0, 5);

    pageIndex.set(1);
    pagination.update((current) => ({ ...current, pageIndex: 1 }));
    fixture.detectChanges();
    next?.click();

    expect(loadPage).toHaveBeenLastCalledWith(2, 5);
    expect(search()).toBe('Ana');
    expect(setSearch).not.toHaveBeenCalled();
  });

  it('offers only backend-safe page sizes and resets to page zero when the size changes', () => {
    search.set('Ana');
    pageIndex.set(2);
    pagination.set({ pageIndex: 2, pageSize: 10, totalItems: 23, totalPages: 3 });
    const { element } = createPage();
    const select = pageSizeSelect(element);

    expect(select.value).toBe('10');
    expect(Array.from(select.options).map(({ value }) => value)).toEqual(['5', '10', '20']);
    loadPage.mockClear();

    select.value = '20';
    select.dispatchEvent(new Event('change', { bubbles: true }));

    expect(loadPage).toHaveBeenCalledOnce();
    expect(loadPage).toHaveBeenCalledWith(0, 20);
    expect(search()).toBe('Ana');
  });

  it('renders loading, unfiltered empty, and search-specific empty states', () => {
    students.set([]);
    loading.set(true);
    let page = createPage();

    expect(page.element.querySelector('[role="status"]')?.textContent).toContain(
      'Učitavanje učenika…',
    );
    expect(page.element.querySelector('table')).toBeNull();
    page.fixture.destroy();

    loading.set(false);
    pagination.set({ pageIndex: 0, pageSize: 10, totalItems: 0, totalPages: 0 });
    page = createPage();

    expect(page.element.textContent).toContain('Nema učenika za prikaz.');
    expect(page.element.querySelector('table')).toBeNull();
    expect(pageSizeSelect(page.element).value).toBe('10');
    expect(page.element.querySelector('.current-page')?.textContent?.replace(/\s/g, '')).toBe(
      '0/0',
    );
    page.fixture.destroy();

    search.set('Ana');
    page = createPage();

    expect(searchInput(page.element).value).toBe('Ana');
    expect(page.element.textContent).toContain('Nema učenika koji odgovaraju pretrazi.');
    expect(page.element.textContent).not.toContain('Nema učenika za prikaz.');
  });

  it('shows a safe load error with a working retry action', () => {
    students.set([]);
    error.set('Nije moguće učitati učenike. Pokušajte ponovo.');
    const { element } = createPage();
    const alert = element.querySelector<HTMLElement>('[role="alert"]');
    const retry = Array.from(element.querySelectorAll<HTMLButtonElement>('button')).find(
      (candidate) => candidate.textContent?.includes('Pokušaj ponovo'),
    );

    expect(alert?.textContent).toContain('Nije moguće učitati učenike.');
    expect(alert?.textContent).not.toContain('Internal server error');
    loadPage.mockClear();
    retry?.click();

    expect(loadPage).toHaveBeenCalledOnce();
    expect(loadPage).toHaveBeenCalledWith();
  });
});
