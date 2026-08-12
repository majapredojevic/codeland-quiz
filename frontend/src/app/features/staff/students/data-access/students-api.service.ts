import { HttpClient, HttpParams } from '@angular/common/http';
import { Service, inject } from '@angular/core';
import { Observable } from 'rxjs';

import {
  CreateStudentRequest,
  StudentResponse,
  StudentsListResponse,
  UpdateStudentRequest,
} from './students.models';

const STUDENTS_URL = '/api/students';

@Service()
export class StudentsApiService {
  private readonly http = inject(HttpClient);

  list(pageIndex: number, pageSize: number, search?: string): Observable<StudentsListResponse> {
    let params = new HttpParams().set('pageIndex', pageIndex).set('pageSize', pageSize);
    const normalizedSearch = search?.trim();

    if (normalizedSearch) {
      params = params.set('search', normalizedSearch);
    }

    return this.http.get<StudentsListResponse>(STUDENTS_URL, { params });
  }

  get(id: number): Observable<StudentResponse> {
    return this.http.get<StudentResponse>(`${STUDENTS_URL}/${id}`);
  }

  create(request: CreateStudentRequest): Observable<StudentResponse> {
    return this.http.post<StudentResponse>(STUDENTS_URL, request);
  }

  update(id: number, request: UpdateStudentRequest): Observable<StudentResponse> {
    return this.http.patch<StudentResponse>(`${STUDENTS_URL}/${id}`, request);
  }

  activate(id: number): Observable<StudentResponse> {
    return this.http.patch<StudentResponse>(`${STUDENTS_URL}/${id}/activate`, null);
  }

  deactivate(id: number): Observable<StudentResponse> {
    return this.http.patch<StudentResponse>(`${STUDENTS_URL}/${id}/deactivate`, null);
  }
}
