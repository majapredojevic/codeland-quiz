import { Component, DestroyRef, inject, OnInit, signal } from '@angular/core';
import { RouterLink } from '@angular/router';

import { ActiveStatusBadge } from '../../../../../shared/ui/active-status-badge/active-status-badge';
import { StudentsStore } from '../../data-access/students.store';

const SEARCH_DEBOUNCE_MS = 300;
const PAGE_SIZE_OPTIONS = [5, 10, 20] as const;

@Component({
  selector: 'clq-students-list-page',
  imports: [RouterLink, ActiveStatusBadge],
  templateUrl: './students-list-page.html',
  styleUrl: './students-list-page.scss',
})
export class StudentsListPage implements OnInit {
  protected readonly studentsStore = inject(StudentsStore);
  private readonly destroyRef = inject(DestroyRef);
  private searchTimer: ReturnType<typeof setTimeout> | null = null;

  protected readonly searchValue = signal('');
  protected readonly pageSizeOptions = PAGE_SIZE_OPTIONS;

  constructor() {
    this.destroyRef.onDestroy(() => this.clearSearchTimer());
  }

  ngOnInit(): void {
    this.searchValue.set(this.studentsStore.search());
    void this.studentsStore.loadPage();
  }

  protected updateSearch(event: Event): void {
    const value = event.target instanceof HTMLInputElement ? event.target.value : '';
    this.searchValue.set(value);
    this.clearSearchTimer();
    this.searchTimer = setTimeout(() => {
      this.searchTimer = null;
      void this.studentsStore.setSearch(value);
    }, SEARCH_DEBOUNCE_MS);
  }

  protected clearSearch(): void {
    this.clearSearchTimer();
    this.searchValue.set('');
    void this.studentsStore.setSearch('');
  }

  protected previousPage(): void {
    const pageIndex = this.studentsStore.pageIndex();

    if (pageIndex > 0 && !this.studentsStore.loading()) {
      void this.studentsStore.loadPage(pageIndex - 1, this.studentsStore.pageSize());
    }
  }

  protected nextPage(): void {
    const pageIndex = this.studentsStore.pageIndex();
    const totalPages = this.studentsStore.pagination().totalPages;

    if (pageIndex + 1 < totalPages && !this.studentsStore.loading()) {
      void this.studentsStore.loadPage(pageIndex + 1, this.studentsStore.pageSize());
    }
  }

  protected changePageSize(event: Event): void {
    if (!(event.target instanceof HTMLSelectElement) || this.studentsStore.loading()) {
      return;
    }

    const pageSize = Number(event.target.value);

    if (
      !PAGE_SIZE_OPTIONS.some((option) => option === pageSize) ||
      pageSize === this.studentsStore.pageSize()
    ) {
      return;
    }

    void this.studentsStore.loadPage(0, pageSize);
  }

  protected retry(): void {
    void this.studentsStore.loadPage();
  }

  protected firstVisibleItem(): number {
    const pagination = this.studentsStore.pagination();

    return pagination.totalItems === 0 ? 0 : pagination.pageIndex * pagination.pageSize + 1;
  }

  protected lastVisibleItem(): number {
    const pagination = this.studentsStore.pagination();

    return Math.min((pagination.pageIndex + 1) * pagination.pageSize, pagination.totalItems);
  }

  private clearSearchTimer(): void {
    if (this.searchTimer !== null) {
      clearTimeout(this.searchTimer);
      this.searchTimer = null;
    }
  }
}
