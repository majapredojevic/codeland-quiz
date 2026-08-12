import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';

import { StudentDetail, StudentsListResponse } from './students.models';
import { StudentsStore } from './students.store';

describe('StudentsStore', () => {
  const student: StudentDetail = {
    id: 7,
    firstName: 'Ana',
    lastName: 'Anić',
    username: 'ana.anic',
    isActive: true,
    createdAt: '2026-08-12T10:00:00+00:00',
    updatedAt: '2026-08-12T10:00:00+00:00',
  };

  let store: StudentsStore;
  let httpTesting: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });

    store = TestBed.inject(StudentsStore);
    httpTesting = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpTesting.verify();
  });

  it('starts with the backend default pagination state', () => {
    expect(store.pageIndex()).toBe(0);
    expect(store.pageSize()).toBe(10);
    expect(store.pagination()).toEqual({
      pageIndex: 0,
      pageSize: 10,
      totalItems: 0,
      totalPages: 0,
    });
  });

  it('loads and exposes the canonical server page', async () => {
    const response: StudentsListResponse = {
      students: [student],
      pagination: { pageIndex: 1, pageSize: 10, totalItems: 12, totalPages: 2 },
    };
    const loading = store.loadPage(1, 10);
    const request = httpTesting.expectOne('/api/students?pageIndex=1&pageSize=10');

    expect(store.loading()).toBe(true);
    request.flush(response);
    await loading;

    expect(store.students()).toEqual([student]);
    expect(store.pagination()).toEqual(response.pagination);
    expect(store.pageIndex()).toBe(1);
    expect(store.pageSize()).toBe(10);
    expect(store.loading()).toBe(false);
    expect(store.error()).toBeNull();
  });

  it('propagates search to the backend and never filters the returned page client-side', async () => {
    const serverMatch = { ...student, id: 8, firstName: 'Server', username: 'canonical.row' };
    const searching = store.setSearch('  Ana  ');
    const request = httpTesting.expectOne('/api/students?pageIndex=0&pageSize=10&search=Ana');

    request.flush({
      students: [student, serverMatch],
      pagination: { pageIndex: 0, pageSize: 10, totalItems: 2, totalPages: 1 },
    });
    await searching;

    expect(store.search()).toBe('Ana');
    expect(store.pageIndex()).toBe(0);
    expect(store.students()).toEqual([student, serverMatch]);
    expect(store.pagination().totalItems).toBe(2);

    await store.setSearch(' Ana ');
    httpTesting.expectNone((candidate) => candidate.url === '/api/students');
  });

  it('preserves search while propagating page index and page size', async () => {
    const searching = store.setSearch('anić');
    httpTesting.expectOne('/api/students?pageIndex=0&pageSize=10&search=ani%C4%87').flush({
      students: [student],
      pagination: { pageIndex: 0, pageSize: 10, totalItems: 21, totalPages: 3 },
    });
    await searching;

    const paging = store.loadPage(2, 20);
    const request = httpTesting.expectOne('/api/students?pageIndex=2&pageSize=20&search=ani%C4%87');

    expect(request.request.params.get('pageIndex')).toBe('2');
    expect(request.request.params.get('pageSize')).toBe('20');
    expect(request.request.params.get('search')).toBe('anić');
    request.flush({
      students: [],
      pagination: { pageIndex: 2, pageSize: 20, totalItems: 21, totalPages: 2 },
    });
    await paging;

    expect(store.search()).toBe('anić');
    expect(store.pageIndex()).toBe(2);
    expect(store.pageSize()).toBe(20);
  });

  it('omits search after clearing it while preserving page size', async () => {
    const searching = store.setSearch('Ana');
    httpTesting.expectOne('/api/students?pageIndex=0&pageSize=10&search=Ana').flush({
      students: [student],
      pagination: { pageIndex: 0, pageSize: 10, totalItems: 1, totalPages: 1 },
    });
    await searching;

    const resizing = store.loadPage(0, 20);
    httpTesting.expectOne('/api/students?pageIndex=0&pageSize=20&search=Ana').flush({
      students: [student],
      pagination: { pageIndex: 0, pageSize: 20, totalItems: 1, totalPages: 1 },
    });
    await resizing;

    const clearing = store.setSearch('   ');
    const request = httpTesting.expectOne('/api/students?pageIndex=0&pageSize=20');

    expect(request.request.params.has('search')).toBe(false);
    request.flush({
      students: [student],
      pagination: { pageIndex: 0, pageSize: 20, totalItems: 1, totalPages: 1 },
    });
    await clearing;

    expect(store.search()).toBe('');
    expect(store.pageSize()).toBe(20);
  });

  it('ignores a stale list response after a newer request wins', async () => {
    const firstLoad = store.loadPage(0, 10);
    const firstRequest = httpTesting.expectOne('/api/students?pageIndex=0&pageSize=10');
    const secondLoad = store.loadPage(1, 10);
    const secondRequest = httpTesting.expectOne('/api/students?pageIndex=1&pageSize=10');
    const newerStudent = { ...student, id: 8, firstName: 'Noviji' };

    secondRequest.flush({
      students: [newerStudent],
      pagination: { pageIndex: 1, pageSize: 10, totalItems: 11, totalPages: 2 },
    });
    await secondLoad;

    firstRequest.flush({
      students: [student],
      pagination: { pageIndex: 0, pageSize: 10, totalItems: 11, totalPages: 2 },
    });
    await firstLoad;

    expect(store.students()).toEqual([newerStudent]);
    expect(store.pageIndex()).toBe(1);
    expect(store.loading()).toBe(false);
  });

  it('loads detail independently and clearDetail invalidates an older response', async () => {
    const firstLoad = store.loadDetail(student.id);
    const firstRequest = httpTesting.expectOne('/api/students/7');

    expect(store.detailLoading()).toBe(true);
    store.clearDetail();
    firstRequest.flush({ student });
    await firstLoad;

    expect(store.detail()).toBeNull();
    expect(store.detailLoading()).toBe(false);
    expect(store.detailError()).toBeNull();

    const secondLoad = store.loadDetail(student.id);
    httpTesting.expectOne('/api/students/7').flush({ student });
    await secondLoad;

    expect(store.detail()).toEqual(student);
    expect(store.detailLoading()).toBe(false);
  });

  it('returns the canonical created student without speculatively changing a page', async () => {
    const creation = store.create({
      firstName: student.firstName,
      lastName: student.lastName,
      username: student.username,
    });
    const request = httpTesting.expectOne('/api/students');

    request.flush({ student }, { status: 201, statusText: 'Created' });

    await expect(creation).resolves.toEqual(student);
    expect(store.students()).toEqual([]);
    expect(store.detail()).toBeNull();
  });

  it('commits update, deactivation and activation responses to list and detail', async () => {
    const listLoading = store.loadPage();
    httpTesting.expectOne('/api/students?pageIndex=0&pageSize=10').flush({
      students: [student],
      pagination: { pageIndex: 0, pageSize: 10, totalItems: 1, totalPages: 1 },
    });
    await listLoading;

    const detailLoading = store.loadDetail(student.id);
    httpTesting.expectOne('/api/students/7').flush({ student });
    await detailLoading;

    const updatedStudent = { ...student, firstName: 'Maja' };
    const update = store.update(student.id, { firstName: 'Maja' });
    httpTesting.expectOne('/api/students/7').flush({ student: updatedStudent });
    await expect(update).resolves.toEqual(updatedStudent);
    expect(store.students()).toEqual([updatedStudent]);
    expect(store.detail()).toEqual(updatedStudent);

    const inactiveStudent = { ...updatedStudent, isActive: false };
    const deactivation = store.deactivate(student.id);
    httpTesting.expectOne('/api/students/7/deactivate').flush({ student: inactiveStudent });
    await expect(deactivation).resolves.toEqual(inactiveStudent);
    expect(store.students()[0]?.isActive).toBe(false);
    expect(store.detail()?.isActive).toBe(false);

    const activeStudent = { ...inactiveStudent, isActive: true };
    const activation = store.activate(student.id);
    httpTesting.expectOne('/api/students/7/activate').flush({ student: activeStudent });
    await expect(activation).resolves.toEqual(activeStudent);
    expect(store.students()[0]?.isActive).toBe(true);
    expect(store.detail()?.isActive).toBe(true);
  });

  it('exposes safe load errors and clears them explicitly', async () => {
    const listLoading = store.loadPage();
    httpTesting
      .expectOne('/api/students?pageIndex=0&pageSize=10')
      .flush({ error: 'Database trace' }, { status: 500, statusText: 'Server Error' });
    await listLoading;

    expect(store.students()).toEqual([]);
    expect(store.loading()).toBe(false);
    expect(store.error()).toBe('Nije moguće učitati učenike. Pokušajte ponovo.');

    store.clearListError();
    expect(store.error()).toBeNull();

    const detailLoading = store.loadDetail(student.id);
    httpTesting
      .expectOne('/api/students/7')
      .flush({ error: 'Database trace' }, { status: 500, statusText: 'Server Error' });
    await detailLoading;

    expect(store.detail()).toBeNull();
    expect(store.detailLoading()).toBe(false);
    expect(store.detailError()).toBe('Nije moguće učitati podatke učenika. Pokušajte ponovo.');
  });

  it('lets mutation errors propagate without replacing them with unsafe text', async () => {
    const creation = store.create({
      firstName: student.firstName,
      lastName: student.lastName,
      username: student.username,
    });
    httpTesting
      .expectOne('/api/students')
      .flush(
        { error: 'Student username is already in use.' },
        { status: 409, statusText: 'Conflict' },
      );

    await expect(creation).rejects.toBeInstanceOf(HttpErrorResponse);
  });

  it('rejects invalid IDs and pagination before sending a request', async () => {
    await expect(store.loadDetail(0)).rejects.toBeInstanceOf(RangeError);
    await expect(store.loadPage(-1, 10)).rejects.toBeInstanceOf(RangeError);
    await expect(store.loadPage(0, 21)).rejects.toBeInstanceOf(RangeError);
    httpTesting.expectNone((candidate) => candidate.url === '/api/students');
  });
});
