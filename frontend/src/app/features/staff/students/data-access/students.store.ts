import { Service, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';

import { StudentsApiService } from './students-api.service';
import {
  CreateStudentRequest,
  StudentDetail,
  StudentsPagination,
  UpdateStudentRequest,
} from './students.models';

export const STUDENTS_DEFAULT_PAGE_SIZE = 10;
export const STUDENTS_MAXIMUM_PAGE_SIZE = 20;

const EMPTY_PAGINATION: StudentsPagination = {
  pageIndex: 0,
  pageSize: STUDENTS_DEFAULT_PAGE_SIZE,
  totalItems: 0,
  totalPages: 0,
};

const LIST_LOAD_ERROR = 'Nije moguće učitati učenike. Pokušajte ponovo.';
const DETAIL_LOAD_ERROR = 'Nije moguće učitati podatke učenika. Pokušajte ponovo.';

@Service()
export class StudentsStore {
  private readonly studentsApi = inject(StudentsApiService);

  private readonly studentsState = signal<StudentDetail[]>([]);
  private readonly paginationState = signal<StudentsPagination>(EMPTY_PAGINATION);
  private readonly loadingState = signal(false);
  private readonly errorState = signal<string | null>(null);
  private readonly searchState = signal('');
  private readonly pageIndexState = signal(0);
  private readonly pageSizeState = signal(STUDENTS_DEFAULT_PAGE_SIZE);
  private readonly detailState = signal<StudentDetail | null>(null);
  private readonly detailLoadingState = signal(false);
  private readonly detailErrorState = signal<string | null>(null);

  private listRequestVersion = 0;
  private detailRequestVersion = 0;

  readonly students = this.studentsState.asReadonly();
  readonly pagination = this.paginationState.asReadonly();
  readonly loading = this.loadingState.asReadonly();
  readonly error = this.errorState.asReadonly();
  readonly search = this.searchState.asReadonly();
  readonly pageIndex = this.pageIndexState.asReadonly();
  readonly pageSize = this.pageSizeState.asReadonly();
  readonly detail = this.detailState.asReadonly();
  readonly detailLoading = this.detailLoadingState.asReadonly();
  readonly detailError = this.detailErrorState.asReadonly();

  async loadPage(pageIndex = this.pageIndex(), pageSize = this.pageSize()): Promise<void> {
    this.assertPagination(pageIndex, pageSize);

    const requestVersion = ++this.listRequestVersion;
    const search = this.search();

    this.pageIndexState.set(pageIndex);
    this.pageSizeState.set(pageSize);
    this.loadingState.set(true);
    this.errorState.set(null);

    try {
      const response = await firstValueFrom(
        this.studentsApi.list(pageIndex, pageSize, search || undefined),
      );

      if (requestVersion !== this.listRequestVersion) {
        return;
      }

      this.studentsState.set(response.students);
      this.paginationState.set(response.pagination);
      this.pageIndexState.set(response.pagination.pageIndex);
      this.pageSizeState.set(response.pagination.pageSize);
    } catch {
      if (requestVersion !== this.listRequestVersion) {
        return;
      }

      this.studentsState.set([]);
      this.paginationState.set({
        pageIndex,
        pageSize,
        totalItems: 0,
        totalPages: 0,
      });
      this.errorState.set(LIST_LOAD_ERROR);
    } finally {
      if (requestVersion === this.listRequestVersion) {
        this.loadingState.set(false);
      }
    }
  }

  async setSearch(value: string): Promise<void> {
    const search = value.trim();

    if (search === this.search()) {
      return;
    }

    this.searchState.set(search);
    await this.loadPage(0, this.pageSize());
  }

  async loadDetail(id: number): Promise<void> {
    this.assertId(id);

    const requestVersion = ++this.detailRequestVersion;
    this.detailState.set(null);
    this.detailLoadingState.set(true);
    this.detailErrorState.set(null);

    try {
      const response = await firstValueFrom(this.studentsApi.get(id));

      if (requestVersion === this.detailRequestVersion) {
        this.detailState.set(response.student);
      }
    } catch {
      if (requestVersion === this.detailRequestVersion) {
        this.detailErrorState.set(DETAIL_LOAD_ERROR);
      }
    } finally {
      if (requestVersion === this.detailRequestVersion) {
        this.detailLoadingState.set(false);
      }
    }
  }

  async create(request: CreateStudentRequest): Promise<StudentDetail> {
    const response = await firstValueFrom(this.studentsApi.create(request));

    return response.student;
  }

  async update(id: number, request: UpdateStudentRequest): Promise<StudentDetail> {
    this.assertId(id);
    const response = await firstValueFrom(this.studentsApi.update(id, request));
    this.commitCanonicalStudent(response.student);

    return response.student;
  }

  async activate(id: number): Promise<StudentDetail> {
    this.assertId(id);
    const response = await firstValueFrom(this.studentsApi.activate(id));
    this.commitCanonicalStudent(response.student);

    return response.student;
  }

  async deactivate(id: number): Promise<StudentDetail> {
    this.assertId(id);
    const response = await firstValueFrom(this.studentsApi.deactivate(id));
    this.commitCanonicalStudent(response.student);

    return response.student;
  }

  clearDetail(): void {
    ++this.detailRequestVersion;
    this.detailState.set(null);
    this.detailLoadingState.set(false);
    this.detailErrorState.set(null);
  }

  clearListError(): void {
    this.errorState.set(null);
  }

  private commitCanonicalStudent(student: StudentDetail): void {
    this.studentsState.update((students) =>
      students.map((currentStudent) =>
        currentStudent.id === student.id ? student : currentStudent,
      ),
    );

    if (this.detail()?.id === student.id) {
      this.detailState.set(student);
    }
  }

  private assertPagination(pageIndex: number, pageSize: number): void {
    if (!Number.isInteger(pageIndex) || pageIndex < 0) {
      throw new RangeError('pageIndex must be a non-negative integer.');
    }

    if (!Number.isInteger(pageSize) || pageSize < 1 || pageSize > STUDENTS_MAXIMUM_PAGE_SIZE) {
      throw new RangeError(
        `pageSize must be an integer between 1 and ${STUDENTS_MAXIMUM_PAGE_SIZE}.`,
      );
    }
  }

  private assertId(id: number): void {
    if (!Number.isSafeInteger(id) || id < 1) {
      throw new RangeError('id must be a positive safe integer.');
    }
  }
}
