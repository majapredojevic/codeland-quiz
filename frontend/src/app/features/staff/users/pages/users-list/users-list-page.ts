import { Component, DestroyRef, inject, OnInit, signal } from '@angular/core';
import { Router, RouterLink } from '@angular/router';

import { UserStatusBadge } from '../../components/user-status-badge/user-status-badge';
import { UsersStore } from '../../data-access/users.store';

const SEARCH_DEBOUNCE_MS = 300;
const PAGE_SIZE_OPTIONS = [5, 10, 20] as const;

@Component({
  selector: 'clq-users-list-page',
  imports: [RouterLink, UserStatusBadge],
  templateUrl: './users-list-page.html',
  styleUrl: './users-list-page.scss',
})
export class UsersListPage implements OnInit {
  protected readonly usersStore = inject(UsersStore);
  private readonly router = inject(Router);
  private readonly destroyRef = inject(DestroyRef);
  private searchTimer: ReturnType<typeof setTimeout> | null = null;

  protected readonly searchValue = signal('');
  protected readonly pageSizeOptions = PAGE_SIZE_OPTIONS;

  constructor() {
    this.destroyRef.onDestroy(() => this.clearSearchTimer());
  }

  ngOnInit(): void {
    this.searchValue.set(this.usersStore.search());
    void this.usersStore.loadPage();
  }

  protected updateSearch(event: Event): void {
    const value = event.target instanceof HTMLInputElement ? event.target.value : '';
    this.searchValue.set(value);
    this.clearSearchTimer();
    this.searchTimer = setTimeout(() => {
      this.searchTimer = null;
      void this.usersStore.setSearch(value);
    }, SEARCH_DEBOUNCE_MS);
  }

  protected clearSearch(): void {
    this.clearSearchTimer();
    this.searchValue.set('');
    void this.usersStore.setSearch('');
  }

  protected previousPage(): void {
    const pageIndex = this.usersStore.pageIndex();

    if (pageIndex > 0 && !this.usersStore.loading()) {
      void this.usersStore.loadPage(pageIndex - 1, this.usersStore.pageSize());
    }
  }

  protected nextPage(): void {
    const pageIndex = this.usersStore.pageIndex();
    const totalPages = this.usersStore.pagination().totalPages;

    if (pageIndex + 1 < totalPages && !this.usersStore.loading()) {
      void this.usersStore.loadPage(pageIndex + 1, this.usersStore.pageSize());
    }
  }

  protected changePageSize(event: Event): void {
    if (!(event.target instanceof HTMLSelectElement) || this.usersStore.loading()) {
      return;
    }

    const pageSize = Number(event.target.value);

    if (
      !PAGE_SIZE_OPTIONS.some((option) => option === pageSize) ||
      pageSize === this.usersStore.pageSize()
    ) {
      return;
    }

    void this.usersStore.loadPage(0, pageSize);
  }

  protected retry(): void {
    void this.usersStore.loadPage();
  }

  protected openUser(id: number): void {
    void this.router.navigate(['/app/users', id]);
  }

  protected handleRowKeydown(event: KeyboardEvent, id: number): void {
    if (event.key !== 'Enter' && event.key !== ' ') {
      return;
    }

    event.preventDefault();
    this.openUser(id);
  }

  protected firstVisibleItem(): number {
    const pagination = this.usersStore.pagination();

    return pagination.totalItems === 0 ? 0 : pagination.pageIndex * pagination.pageSize + 1;
  }

  protected lastVisibleItem(): number {
    const pagination = this.usersStore.pagination();

    return Math.min((pagination.pageIndex + 1) * pagination.pageSize, pagination.totalItems);
  }

  private clearSearchTimer(): void {
    if (this.searchTimer !== null) {
      clearTimeout(this.searchTimer);
      this.searchTimer = null;
    }
  }
}
