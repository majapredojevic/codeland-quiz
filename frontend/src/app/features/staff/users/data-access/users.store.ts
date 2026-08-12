import { Service, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';

import { UsersApiService } from './users-api.service';
import {
  CreateUserRequest,
  CreateUserResponse,
  TemporaryPasswordResponse,
  UpdateUserRequest,
  UserDetail,
  UsersListResponse,
  UsersPagination,
} from './users.models';

export const USERS_DEFAULT_PAGE_SIZE = 10;
export const USERS_MAXIMUM_PAGE_SIZE = 20;

const EMPTY_PAGINATION: UsersPagination = {
  pageIndex: 0,
  pageSize: USERS_DEFAULT_PAGE_SIZE,
  totalItems: 0,
  totalPages: 0,
};

const LIST_LOAD_ERROR = 'Nije moguće učitati korisnike. Pokušajte ponovo.';
const DETAIL_LOAD_ERROR = 'Nije moguće učitati podatke korisnika. Pokušajte ponovo.';

@Service()
export class UsersStore {
  private readonly usersApi = inject(UsersApiService);

  private readonly usersState = signal<UserDetail[]>([]);
  private readonly paginationState = signal<UsersPagination>(EMPTY_PAGINATION);
  private readonly loadingState = signal(false);
  private readonly errorState = signal<string | null>(null);
  private readonly searchState = signal('');
  private readonly pageIndexState = signal(0);
  private readonly pageSizeState = signal(USERS_DEFAULT_PAGE_SIZE);
  private readonly detailState = signal<UserDetail | null>(null);
  private readonly detailLoadingState = signal(false);
  private readonly detailErrorState = signal<string | null>(null);

  private listRequestVersion = 0;
  private detailRequestVersion = 0;

  readonly users = this.usersState.asReadonly();
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
      const response =
        search === ''
          ? await firstValueFrom(this.usersApi.list(pageIndex, pageSize))
          : await this.loadFilteredPage(search, pageIndex, pageSize);

      if (requestVersion !== this.listRequestVersion) {
        return;
      }

      this.usersState.set(response.users);
      this.paginationState.set(response.pagination);
      this.pageIndexState.set(response.pagination.pageIndex);
      this.pageSizeState.set(response.pagination.pageSize);
    } catch {
      if (requestVersion !== this.listRequestVersion) {
        return;
      }

      this.usersState.set([]);
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
      const response = await firstValueFrom(this.usersApi.get(id));

      if (requestVersion === this.detailRequestVersion) {
        this.detailState.set(response.user);
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

  async create(request: CreateUserRequest): Promise<CreateUserResponse> {
    return firstValueFrom(this.usersApi.create(request));
  }

  async update(id: number, request: UpdateUserRequest): Promise<UserDetail> {
    this.assertId(id);
    const response = await firstValueFrom(this.usersApi.update(id, request));
    this.commitCanonicalUser(response.user);

    return response.user;
  }

  async activate(id: number): Promise<UserDetail> {
    this.assertId(id);
    const response = await firstValueFrom(this.usersApi.activate(id));
    this.commitCanonicalUser(response.user);

    return response.user;
  }

  async deactivate(id: number): Promise<UserDetail> {
    this.assertId(id);
    const response = await firstValueFrom(this.usersApi.deactivate(id));
    this.commitCanonicalUser(response.user);

    return response.user;
  }

  async resetPassword(id: number): Promise<TemporaryPasswordResponse> {
    this.assertId(id);
    const response = await firstValueFrom(this.usersApi.resetPassword(id));
    this.commitCanonicalUser(response.user);

    return response;
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

  private async loadFilteredPage(
    search: string,
    pageIndex: number,
    pageSize: number,
  ): Promise<UsersListResponse> {
    const firstPage = await firstValueFrom(this.usersApi.list(0, USERS_MAXIMUM_PAGE_SIZE));
    const remainingRequests = Array.from(
      { length: Math.max(0, firstPage.pagination.totalPages - 1) },
      (_, index) => firstValueFrom(this.usersApi.list(index + 1, USERS_MAXIMUM_PAGE_SIZE)),
    );
    const remainingPages = await Promise.all(remainingRequests);
    const normalizedSearch = search.toLocaleLowerCase();
    const matchingUsers = [firstPage, ...remainingPages]
      .flatMap((response) => response.users)
      .filter(
        (user) =>
          user.name.toLocaleLowerCase().includes(normalizedSearch) ||
          user.email.toLocaleLowerCase().includes(normalizedSearch),
      );
    const totalItems = matchingUsers.length;
    const totalPages = totalItems === 0 ? 0 : Math.ceil(totalItems / pageSize);
    const offset = pageIndex * pageSize;

    return {
      users: matchingUsers.slice(offset, offset + pageSize),
      pagination: {
        pageIndex,
        pageSize,
        totalItems,
        totalPages,
      },
    };
  }

  private commitCanonicalUser(user: UserDetail): void {
    this.usersState.update((users) =>
      users.map((currentUser) => (currentUser.id === user.id ? user : currentUser)),
    );

    if (this.detail()?.id === user.id) {
      this.detailState.set(user);
    }
  }

  private assertPagination(pageIndex: number, pageSize: number): void {
    if (!Number.isInteger(pageIndex) || pageIndex < 0) {
      throw new RangeError('pageIndex must be a non-negative integer.');
    }

    if (!Number.isInteger(pageSize) || pageSize < 1 || pageSize > USERS_MAXIMUM_PAGE_SIZE) {
      throw new RangeError(`pageSize must be an integer between 1 and ${USERS_MAXIMUM_PAGE_SIZE}.`);
    }
  }

  private assertId(id: number): void {
    if (!Number.isInteger(id) || id < 1) {
      throw new RangeError('id must be a positive integer.');
    }
  }
}
