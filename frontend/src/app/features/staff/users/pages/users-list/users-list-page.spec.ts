import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';

import { UserDetail, UsersPagination } from '../../data-access/users.models';
import { UsersStore } from '../../data-access/users.store';
import { UsersListPage } from './users-list-page';

@Component({ template: '' })
class EmptyPage {}

describe('UsersListPage', () => {
  const activeTeacher: UserDetail = {
    id: 7,
    name: 'Ana Anić',
    email: 'ana@example.com',
    role: 'TEACHER',
    isActive: true,
    mustChangePassword: false,
  };
  const inactiveTeacher: UserDetail = {
    ...activeTeacher,
    id: 8,
    name: 'Maja Majić',
    email: 'maja@example.com',
    isActive: false,
  };

  const users = signal<UserDetail[]>([]);
  const pagination = signal<UsersPagination>({
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
    users.set([activeTeacher, inactiveTeacher]);
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
      imports: [UsersListPage],
      providers: [
        provideRouter([
          { path: 'app/users/new', component: EmptyPage },
          { path: 'app/users/:id', component: EmptyPage },
        ]),
        {
          provide: UsersStore,
          useValue: {
            users: users.asReadonly(),
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
  });

  function createPage() {
    const fixture = TestBed.createComponent(UsersListPage);
    fixture.detectChanges();

    return { fixture, element: fixture.nativeElement as HTMLElement };
  }

  function searchInput(element: HTMLElement): HTMLInputElement {
    const input = element.querySelector<HTMLInputElement>('#users-search');

    if (!input) {
      throw new Error('Users search input was not rendered');
    }

    return input;
  }

  function enterSearch(input: HTMLInputElement, value: string): void {
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function pageSizeSelect(element: HTMLElement): HTMLSelectElement {
    const select = element.querySelector<HTMLSelectElement>('#users-page-size');

    if (!select) {
      throw new Error('Page-size selector was not rendered');
    }

    return select;
  }

  it('renders the page, search, add action, and user results', () => {
    const { element } = createPage();
    const input = searchInput(element);
    const addLink = Array.from(element.querySelectorAll<HTMLAnchorElement>('a')).find((link) =>
      link.textContent?.includes('Dodaj korisnika'),
    );
    const rows = Array.from(element.querySelectorAll<HTMLTableRowElement>('tbody tr'));

    expect(element.querySelector('h1')?.textContent?.trim()).toBe('Korisnici');
    expect(input.type).toBe('search');
    expect(input.placeholder).toBe('Pretraži po imenu ili emailu...');
    expect(addLink?.getAttribute('href')).toBe('/app/users/new');
    expect(rows).toHaveLength(2);
    expect(rows[0].textContent).toContain(activeTeacher.name);
    expect(rows[0].textContent).toContain(activeTeacher.email);
    expect(rows[0].textContent).toContain('TEACHER');
    expect(rows[0].textContent).toContain('Aktivan');
    expect(rows[1].textContent).toContain('Neaktivan');
    expect(pageSizeSelect(element).value).toBe('10');
    expect(Array.from(pageSizeSelect(element).options).map(({ value }) => value)).toEqual([
      '5',
      '10',
      '20',
    ]);
    expect(loadPage).toHaveBeenCalledOnce();
    expect(loadPage).toHaveBeenCalledWith();
  });

  it('navigates an accessible row to its user details with pointer and keyboard input', () => {
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    const { element } = createPage();
    const row = element.querySelector<HTMLTableRowElement>('tbody tr');

    expect(row?.tabIndex).toBe(0);
    expect(row?.getAttribute('aria-label')).toContain(activeTeacher.name);

    row?.click();
    expect(navigate).toHaveBeenLastCalledWith(['/app/users', activeTeacher.id]);

    const enter = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });
    row?.dispatchEvent(enter);

    expect(enter.defaultPrevented).toBe(true);
    expect(navigate).toHaveBeenCalledTimes(2);
    expect(navigate).toHaveBeenLastCalledWith(['/app/users', activeTeacher.id]);
  });

  it('requests adjacent pages and updates the pagination state', () => {
    pageIndex.set(1);
    pagination.set({ pageIndex: 1, pageSize: 10, totalItems: 23, totalPages: 3 });
    const { fixture, element } = createPage();
    const previous = element.querySelector<HTMLButtonElement>(
      'button[aria-label="Prethodna stranica"]',
    );
    const next = element.querySelector<HTMLButtonElement>('button[aria-label^="Sljede"]');

    expect(element.querySelector('.pagination-summary > p')?.textContent).toContain('11–20 od 23');
    loadPage.mockClear();

    previous?.click();
    expect(loadPage).toHaveBeenLastCalledWith(0, 10);
    expect(pageIndex()).toBe(0);

    pageIndex.set(1);
    pagination.update((current) => ({ ...current, pageIndex: 1 }));
    fixture.detectChanges();
    next?.click();

    expect(loadPage).toHaveBeenLastCalledWith(2, 10);
    expect(pageIndex()).toBe(2);
  });

  it('debounces search and delegates the first-page reset to the store', () => {
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

  it('clears the pending search without issuing the stale debounced value', () => {
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

  it('changes page size from the available backend-safe values and resets to page zero', () => {
    search.set('Ana');
    pageIndex.set(2);
    pageSize.set(10);
    pagination.set({ pageIndex: 2, pageSize: 10, totalItems: 23, totalPages: 3 });
    const { element } = createPage();
    const select = pageSizeSelect(element);

    loadPage.mockClear();
    select.value = '20';
    select.dispatchEvent(new Event('change', { bubbles: true }));

    expect(loadPage).toHaveBeenCalledOnce();
    expect(loadPage).toHaveBeenCalledWith(0, 20);
    expect(pageIndex()).toBe(0);
    expect(pageSize()).toBe(20);
    expect(search()).toBe('Ana');
  });

  it('shows a one-based current page and keeps the page size on adjacent-page requests', () => {
    pageIndex.set(1);
    pageSize.set(5);
    pagination.set({ pageIndex: 1, pageSize: 5, totalItems: 18, totalPages: 4 });
    const { element } = createPage();

    expect(element.querySelector('.current-page')?.textContent?.replace(/\s/g, '')).toBe('2/4');
    expect(element.querySelector('.pagination-summary > p')?.textContent).toContain('6–10 od 18');
    loadPage.mockClear();
    element.querySelector<HTMLButtonElement>('button[aria-label="Sljedeća stranica"]')?.click();

    expect(loadPage).toHaveBeenCalledWith(2, 5);
  });

  it('renders the unfiltered empty state', () => {
    users.set([]);
    pagination.set({ pageIndex: 0, pageSize: 10, totalItems: 0, totalPages: 0 });
    const { element } = createPage();

    expect(element.textContent).toContain('Nema korisnika za prikaz.');
    expect(element.querySelector('table')).toBeNull();
    expect(pageSizeSelect(element).value).toBe('10');
    expect(element.querySelector('.current-page')?.textContent?.replace(/\s/g, '')).toBe('0/0');
    expect(element.textContent).not.toContain('1 / 0');
  });

  it('renders the search-specific empty state', () => {
    users.set([]);
    pagination.set({ pageIndex: 0, pageSize: 10, totalItems: 0, totalPages: 0 });
    search.set('Ana');
    const { element } = createPage();

    expect(searchInput(element).value).toBe('Ana');
    expect(element.textContent).toContain('Nema korisnika koji odgovaraju pretrazi.');
    expect(element.textContent).not.toContain('Nema korisnika za prikaz.');
  });

  it('shows a safe page error with a working retry action', () => {
    users.set([]);
    error.set('Nije moguće učitati korisnike. Pokušajte ponovo.');
    const { element } = createPage();
    const alert = element.querySelector<HTMLElement>('[role="alert"]');
    const retry = Array.from(element.querySelectorAll<HTMLButtonElement>('button')).find(
      (candidate) => candidate.textContent?.includes('Pokušaj ponovo'),
    );

    expect(alert?.textContent).toContain('Nije moguće učitati korisnike.');
    expect(alert?.textContent).not.toContain('Internal server error');
    loadPage.mockClear();
    retry?.click();

    expect(loadPage).toHaveBeenCalledOnce();
  });
});
