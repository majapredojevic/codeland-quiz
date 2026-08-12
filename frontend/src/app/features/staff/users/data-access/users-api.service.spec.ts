import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { firstValueFrom } from 'rxjs';

import { UsersApiService } from './users-api.service';
import {
  CreateUserResponse,
  TemporaryPasswordResponse,
  UserDetail,
  UserResponse,
  UsersListResponse,
} from './users.models';

describe('UsersApiService', () => {
  const teacher: UserDetail = {
    id: 7,
    name: 'Ana Anić',
    email: 'ana@example.com',
    role: 'TEACHER',
    isActive: true,
    mustChangePassword: false,
  };

  let api: UsersApiService;
  let httpTesting: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });

    api = TestBed.inject(UsersApiService);
    httpTesting = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpTesting.verify();
  });

  it('loads a list with only the backend pageIndex and pageSize parameters', async () => {
    const response: UsersListResponse = {
      users: [teacher],
      pagination: { pageIndex: 2, pageSize: 10, totalItems: 25, totalPages: 3 },
    };
    const result = firstValueFrom(api.list(2, 10));
    const request = httpTesting.expectOne((candidate) => candidate.url === '/api/admin/users');

    expect(request.request.method).toBe('GET');
    expect(request.request.params.keys().sort()).toEqual(['pageIndex', 'pageSize']);
    expect(request.request.params.get('pageIndex')).toBe('2');
    expect(request.request.params.get('pageSize')).toBe('10');
    request.flush(response);

    await expect(result).resolves.toEqual(response);
  });

  it('loads user detail by ID', async () => {
    const response: UserResponse = { user: teacher };
    const result = firstValueFrom(api.get(teacher.id));
    const request = httpTesting.expectOne('/api/admin/users/7');

    expect(request.request.method).toBe('GET');
    request.flush(response);

    await expect(result).resolves.toEqual(response);
  });

  it('creates a teacher without inventing a role or password request field', async () => {
    const response: CreateUserResponse = {
      user: {
        id: teacher.id,
        name: teacher.name,
        email: teacher.email,
        role: 'TEACHER',
      },
      temporaryPassword: 'Temporary1!',
    };
    const result = firstValueFrom(api.create({ name: teacher.name, email: teacher.email }));
    const request = httpTesting.expectOne('/api/admin/users');

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({ name: teacher.name, email: teacher.email });
    request.flush(response, { status: 201, statusText: 'Created' });

    await expect(result).resolves.toEqual(response);
  });

  it('patches only the supplied profile fields', async () => {
    const response: UserResponse = { user: { ...teacher, name: 'Ana Nova' } };
    const result = firstValueFrom(api.update(teacher.id, { name: 'Ana Nova' }));
    const request = httpTesting.expectOne('/api/admin/users/7');

    expect(request.request.method).toBe('PATCH');
    expect(request.request.body).toEqual({ name: 'Ana Nova' });
    request.flush(response);

    await expect(result).resolves.toEqual(response);
  });

  it('uses the exact activation and deactivation endpoints with empty bodies', async () => {
    const activation = firstValueFrom(api.activate(teacher.id));
    const activateRequest = httpTesting.expectOne('/api/admin/users/7/activate');

    expect(activateRequest.request.method).toBe('PATCH');
    expect(activateRequest.request.body).toBeNull();
    activateRequest.flush({ user: teacher });
    await expect(activation).resolves.toEqual({ user: teacher });

    const inactiveTeacher = { ...teacher, isActive: false };
    const deactivation = firstValueFrom(api.deactivate(teacher.id));
    const deactivateRequest = httpTesting.expectOne('/api/admin/users/7/deactivate');

    expect(deactivateRequest.request.method).toBe('PATCH');
    expect(deactivateRequest.request.body).toBeNull();
    deactivateRequest.flush({ user: inactiveTeacher });
    await expect(deactivation).resolves.toEqual({ user: inactiveTeacher });
  });

  it('uses the exact reset-password endpoint and returns the temporary password envelope', async () => {
    const response: TemporaryPasswordResponse = {
      user: { ...teacher, mustChangePassword: true },
      temporaryPassword: 'Temporary2!',
    };
    const result = firstValueFrom(api.resetPassword(teacher.id));
    const request = httpTesting.expectOne('/api/admin/users/7/reset-password');

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toBeNull();
    request.flush(response);

    await expect(result).resolves.toEqual(response);
  });
});
