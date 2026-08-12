import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';

import { UserDetail, UsersListResponse } from './users.models';
import { UsersStore } from './users.store';

describe('UsersStore', () => {
  const teacher: UserDetail = {
    id: 7,
    name: 'Ana Anić',
    email: 'ana@example.com',
    role: 'TEACHER',
    isActive: true,
    mustChangePassword: false,
  };

  let store: UsersStore;
  let httpTesting: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });

    store = TestBed.inject(UsersStore);
    httpTesting = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpTesting.verify();
  });

  it('starts with the backend default page size of 10', () => {
    expect(store.pageIndex()).toBe(0);
    expect(store.pageSize()).toBe(10);
  });

  it('loads and exposes a canonical server page', async () => {
    const response: UsersListResponse = {
      users: [teacher],
      pagination: { pageIndex: 1, pageSize: 10, totalItems: 12, totalPages: 2 },
    };
    const loading = store.loadPage(1, 10);
    const request = httpTesting.expectOne(
      (candidate) => candidate.urlWithParams === '/api/admin/users?pageIndex=1&pageSize=10',
    );

    expect(store.loading()).toBe(true);
    request.flush(response);
    await loading;

    expect(store.users()).toEqual([teacher]);
    expect(store.pagination()).toEqual(response.pagination);
    expect(store.pageIndex()).toBe(1);
    expect(store.pageSize()).toBe(10);
    expect(store.loading()).toBe(false);
    expect(store.error()).toBeNull();
  });

  it('ignores a stale list response after a newer page request wins', async () => {
    const firstLoad = store.loadPage(0, 10);
    const firstRequest = httpTesting.expectOne('/api/admin/users?pageIndex=0&pageSize=10');
    const secondLoad = store.loadPage(1, 10);
    const secondRequest = httpTesting.expectOne('/api/admin/users?pageIndex=1&pageSize=10');
    const newerTeacher = { ...teacher, id: 8, name: 'Noviji odgovor' };

    secondRequest.flush({
      users: [newerTeacher],
      pagination: { pageIndex: 1, pageSize: 10, totalItems: 11, totalPages: 2 },
    });
    await secondLoad;

    firstRequest.flush({
      users: [teacher],
      pagination: { pageIndex: 0, pageSize: 10, totalItems: 11, totalPages: 2 },
    });
    await firstLoad;

    expect(store.users()).toEqual([newerTeacher]);
    expect(store.pageIndex()).toBe(1);
    expect(store.loading()).toBe(false);
  });

  it('searches all backend pages without sending an unsupported search parameter', async () => {
    const matchingUsers = Array.from({ length: 12 }, (_, index): UserDetail => ({
      ...teacher,
      id: index + 1,
      name: index === 11 ? 'Druga osoba' : `Traženi korisnik ${index + 1}`,
      email: index === 11 ? 'traženi@example.com' : `teacher${index + 1}@example.com`,
    }));
    const search = store.setSearch('  TRAŽENI  ');
    const firstRequest = httpTesting.expectOne(
      (candidate) => candidate.urlWithParams === '/api/admin/users?pageIndex=0&pageSize=20',
    );

    expect(firstRequest.request.params.has('search')).toBe(false);
    firstRequest.flush({
      users: matchingUsers.slice(0, 4),
      pagination: { pageIndex: 0, pageSize: 20, totalItems: 41, totalPages: 3 },
    });

    await Promise.resolve();
    const remainingRequests = httpTesting.match(
      (candidate) => candidate.url === '/api/admin/users',
    );
    expect(remainingRequests).toHaveLength(2);
    expect(remainingRequests.map((request) => request.request.params.get('pageIndex'))).toEqual([
      '1',
      '2',
    ]);
    expect(remainingRequests.every((request) => !request.request.params.has('search'))).toBe(true);
    remainingRequests[0].flush({
      users: matchingUsers.slice(4, 8),
      pagination: { pageIndex: 1, pageSize: 20, totalItems: 41, totalPages: 3 },
    });
    remainingRequests[1].flush({
      users: matchingUsers.slice(8),
      pagination: { pageIndex: 2, pageSize: 20, totalItems: 41, totalPages: 3 },
    });
    await search;

    expect(store.search()).toBe('TRAŽENI');
    expect(store.pageIndex()).toBe(0);
    expect(store.users()).toHaveLength(10);
    expect(store.pagination()).toEqual({
      pageIndex: 0,
      pageSize: 10,
      totalItems: 12,
      totalPages: 2,
    });

    await store.setSearch(' TRAŽENI ');
    httpTesting.expectNone((candidate) => candidate.url === '/api/admin/users');
  });

  it('reloads page zero at a new size while preserving the active search', async () => {
    const otherTeacher: UserDetail = {
      ...teacher,
      id: 8,
      name: 'Maja Majić',
      email: 'maja@example.com',
    };
    const initialSearch = store.setSearch('Ana');
    httpTesting.expectOne('/api/admin/users?pageIndex=0&pageSize=20').flush({
      users: [teacher, otherTeacher],
      pagination: { pageIndex: 0, pageSize: 20, totalItems: 2, totalPages: 1 },
    });
    await initialSearch;

    expect(store.search()).toBe('Ana');
    expect(store.users()).toEqual([teacher]);

    const resize = store.loadPage(0, 20);
    const request = httpTesting.expectOne('/api/admin/users?pageIndex=0&pageSize=20');

    expect(request.request.params.has('search')).toBe(false);
    request.flush({
      users: [teacher, otherTeacher],
      pagination: { pageIndex: 0, pageSize: 20, totalItems: 2, totalPages: 1 },
    });
    await resize;

    expect(store.search()).toBe('Ana');
    expect(store.pageIndex()).toBe(0);
    expect(store.pageSize()).toBe(20);
    expect(store.users()).toEqual([teacher]);
  });

  it('loads detail independently of list row state', async () => {
    const loading = store.loadDetail(teacher.id);
    const request = httpTesting.expectOne('/api/admin/users/7');

    expect(store.detailLoading()).toBe(true);
    request.flush({ user: teacher });
    await loading;

    expect(store.detail()).toEqual(teacher);
    expect(store.detailLoading()).toBe(false);
    expect(store.detailError()).toBeNull();
  });

  it('returns create credentials without retaining the temporary password', async () => {
    const creation = store.create({ name: teacher.name, email: teacher.email });
    const request = httpTesting.expectOne('/api/admin/users');

    request.flush(
      {
        user: {
          id: teacher.id,
          name: teacher.name,
          email: teacher.email,
          role: 'TEACHER',
        },
        temporaryPassword: 'Temporary1!',
      },
      { status: 201, statusText: 'Created' },
    );
    const result = await creation;

    expect(result.temporaryPassword).toBe('Temporary1!');
    expect(store.users()).toEqual([]);
    expect(store.detail()).toBeNull();
  });

  it('commits update, activation and deactivation responses to canonical detail state', async () => {
    const detailLoading = store.loadDetail(teacher.id);
    httpTesting.expectOne('/api/admin/users/7').flush({ user: teacher });
    await detailLoading;

    const update = store.update(teacher.id, { name: 'Ana Nova' });
    const updatedTeacher = { ...teacher, name: 'Ana Nova' };
    httpTesting.expectOne('/api/admin/users/7').flush({ user: updatedTeacher });
    await expect(update).resolves.toEqual(updatedTeacher);
    expect(store.detail()).toEqual(updatedTeacher);

    const deactivation = store.deactivate(teacher.id);
    const inactiveTeacher = { ...updatedTeacher, isActive: false };
    httpTesting.expectOne('/api/admin/users/7/deactivate').flush({ user: inactiveTeacher });
    await expect(deactivation).resolves.toEqual(inactiveTeacher);
    expect(store.detail()?.isActive).toBe(false);

    const activation = store.activate(teacher.id);
    const activeTeacher = { ...inactiveTeacher, isActive: true };
    httpTesting.expectOne('/api/admin/users/7/activate').flush({ user: activeTeacher });
    await expect(activation).resolves.toEqual(activeTeacher);
    expect(store.detail()?.isActive).toBe(true);
  });

  it('returns a reset password once while retaining only canonical user state', async () => {
    const detailLoading = store.loadDetail(teacher.id);
    httpTesting.expectOne('/api/admin/users/7').flush({ user: teacher });
    await detailLoading;

    const reset = store.resetPassword(teacher.id);
    const passwordChangeRequiredUser = { ...teacher, mustChangePassword: true };
    httpTesting.expectOne('/api/admin/users/7/reset-password').flush({
      user: passwordChangeRequiredUser,
      temporaryPassword: 'Temporary2!',
    });
    const result = await reset;

    expect(result.temporaryPassword).toBe('Temporary2!');
    expect(store.detail()).toEqual(passwordChangeRequiredUser);
    expect('temporaryPassword' in (store.detail() as unknown as Record<string, unknown>)).toBe(
      false,
    );
  });

  it('exposes safe load errors and resets loading state', async () => {
    const loading = store.loadPage();
    httpTesting
      .expectOne('/api/admin/users?pageIndex=0&pageSize=10')
      .flush({ error: 'Internal server error.' }, { status: 500, statusText: 'Server Error' });
    await loading;

    expect(store.users()).toEqual([]);
    expect(store.loading()).toBe(false);
    expect(store.error()).toBe('Nije moguće učitati korisnike. Pokušajte ponovo.');
  });
});
