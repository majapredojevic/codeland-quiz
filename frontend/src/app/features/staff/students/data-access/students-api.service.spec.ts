import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { firstValueFrom } from 'rxjs';

import { StudentsApiService } from './students-api.service';
import { StudentDetail, StudentResponse, StudentsListResponse } from './students.models';

describe('StudentsApiService', () => {
  const student: StudentDetail = {
    id: 7,
    firstName: 'Ana',
    lastName: 'Anić',
    username: 'ana.anic',
    isActive: true,
    createdAt: '2026-08-12T10:00:00+00:00',
    updatedAt: '2026-08-12T10:00:00+00:00',
  };

  let api: StudentsApiService;
  let httpTesting: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });

    api = TestBed.inject(StudentsApiService);
    httpTesting = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpTesting.verify();
  });

  it('loads a server page without inventing status or sort parameters', async () => {
    const response: StudentsListResponse = {
      students: [student],
      pagination: { pageIndex: 2, pageSize: 10, totalItems: 25, totalPages: 3 },
    };
    const result = firstValueFrom(api.list(2, 10));
    const request = httpTesting.expectOne((candidate) => candidate.url === '/api/students');

    expect(request.request.method).toBe('GET');
    expect(request.request.params.keys().sort()).toEqual(['pageIndex', 'pageSize']);
    expect(request.request.params.get('pageIndex')).toBe('2');
    expect(request.request.params.get('pageSize')).toBe('10');
    request.flush(response);

    await expect(result).resolves.toEqual(response);
  });

  it('sends a trimmed server-side search and omits a blank search', async () => {
    const response: StudentsListResponse = {
      students: [student],
      pagination: { pageIndex: 0, pageSize: 20, totalItems: 1, totalPages: 1 },
    };
    const searched = firstValueFrom(api.list(0, 20, '  Ana Anić  '));
    const searchRequest = httpTesting.expectOne((candidate) => candidate.url === '/api/students');

    expect(searchRequest.request.params.keys().sort()).toEqual(['pageIndex', 'pageSize', 'search']);
    expect(searchRequest.request.params.get('search')).toBe('Ana Anić');
    searchRequest.flush(response);
    await expect(searched).resolves.toEqual(response);

    const unsearched = firstValueFrom(api.list(0, 20, '   '));
    const blankRequest = httpTesting.expectOne((candidate) => candidate.url === '/api/students');

    expect(blankRequest.request.params.has('search')).toBe(false);
    blankRequest.flush(response);
    await expect(unsearched).resolves.toEqual(response);
  });

  it('loads student detail by ID', async () => {
    const response: StudentResponse = { student };
    const result = firstValueFrom(api.get(student.id));
    const request = httpTesting.expectOne('/api/students/7');

    expect(request.request.method).toBe('GET');
    request.flush(response);

    await expect(result).resolves.toEqual(response);
  });

  it('creates a student with only the backend profile fields', async () => {
    const requestBody = {
      firstName: student.firstName,
      lastName: student.lastName,
      username: student.username,
    };
    const result = firstValueFrom(api.create(requestBody));
    const request = httpTesting.expectOne('/api/students');

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual(requestBody);
    request.flush({ student }, { status: 201, statusText: 'Created' });

    await expect(result).resolves.toEqual({ student });
  });

  it('patches only the supplied student fields', async () => {
    const updatedStudent = { ...student, firstName: 'Maja' };
    const result = firstValueFrom(api.update(student.id, { firstName: 'Maja' }));
    const request = httpTesting.expectOne('/api/students/7');

    expect(request.request.method).toBe('PATCH');
    expect(request.request.body).toEqual({ firstName: 'Maja' });
    request.flush({ student: updatedStudent });

    await expect(result).resolves.toEqual({ student: updatedStudent });
  });

  it('uses the exact activation and deactivation routes with empty bodies', async () => {
    const activation = firstValueFrom(api.activate(student.id));
    const activateRequest = httpTesting.expectOne('/api/students/7/activate');

    expect(activateRequest.request.method).toBe('PATCH');
    expect(activateRequest.request.body).toBeNull();
    activateRequest.flush({ student });
    await expect(activation).resolves.toEqual({ student });

    const inactiveStudent = { ...student, isActive: false };
    const deactivation = firstValueFrom(api.deactivate(student.id));
    const deactivateRequest = httpTesting.expectOne('/api/students/7/deactivate');

    expect(deactivateRequest.request.method).toBe('PATCH');
    expect(deactivateRequest.request.body).toBeNull();
    deactivateRequest.flush({ student: inactiveStudent });
    await expect(deactivation).resolves.toEqual({ student: inactiveStudent });
  });
});
