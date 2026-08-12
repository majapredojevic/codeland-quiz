import { HttpErrorResponse } from '@angular/common/http';
import { Component, inject, OnInit, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { firstValueFrom } from 'rxjs';

import { Pagination } from '../../../quizzes/data-access/quizzes.models';
import { ResultsApiService } from '../../data-access/results-api.service';
import { QuizStatistics, SessionHistoryItem } from '../../data-access/results.models';
import {
  formatNumber,
  formatPercentage,
  formatResponseTime,
  formatScore,
  formatStaffDate,
} from '../../utils/results-formatters';

const PAGE_SIZES = [5, 10, 20] as const;
const emptyPagination = (): Pagination => ({
  pageIndex: 0,
  pageSize: 5,
  totalItems: 0,
  totalPages: 0,
});

@Component({
  selector: 'clq-quiz-statistics-page',
  imports: [RouterLink],
  providers: [ResultsApiService],
  templateUrl: './quiz-statistics-page.html',
  styleUrl: './quiz-statistics-page.scss',
})
export class QuizStatisticsPage implements OnInit {
  private readonly route = inject(ActivatedRoute);
  private readonly api = inject(ResultsApiService);
  private statsRequestVersion = 0;
  private sessionsRequestVersion = 0;

  protected readonly statistics = signal<QuizStatistics | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal<'invalid' | 'not-found' | 'generic' | null>(null);
  protected readonly sessions = signal<SessionHistoryItem[]>([]);
  protected readonly sessionsPagination = signal(emptyPagination());
  protected readonly sessionsLoading = signal(true);
  protected readonly sessionsError = signal(false);
  protected readonly pageSizeOptions = PAGE_SIZES;
  protected readonly formatNumber = formatNumber;
  protected readonly formatPercentage = formatPercentage;
  protected readonly formatResponseTime = formatResponseTime;
  protected readonly formatScore = formatScore;
  protected readonly formatDate = formatStaffDate;
  protected quizId: number | null = null;

  ngOnInit(): void {
    const rawId = this.route.snapshot.paramMap.get('id');
    const id = rawId && /^\d+$/.test(rawId) ? Number(rawId) : NaN;
    if (!Number.isSafeInteger(id) || id < 1) {
      this.loading.set(false);
      this.sessionsLoading.set(false);
      this.error.set('invalid');
      return;
    }
    this.quizId = id;
    void this.loadStatistics();
    void this.loadSessions(0);
  }

  protected progress(value: number | null): number {
    return value === null || !Number.isFinite(value) ? 0 : Math.max(0, Math.min(100, value));
  }

  protected retryStatistics(): void {
    void this.loadStatistics();
  }

  protected retrySessions(): void {
    void this.loadSessions(this.sessionsPagination().pageIndex);
  }

  protected previousPage(): void {
    const page = this.sessionsPagination();
    if (page.pageIndex > 0 && !this.sessionsLoading()) void this.loadSessions(page.pageIndex - 1);
  }

  protected nextPage(): void {
    const page = this.sessionsPagination();
    if (page.pageIndex + 1 < page.totalPages && !this.sessionsLoading()) {
      void this.loadSessions(page.pageIndex + 1);
    }
  }

  protected changePageSize(event: Event): void {
    if (!(event.target instanceof HTMLSelectElement)) return;
    const pageSize = Number(event.target.value);
    if (!PAGE_SIZES.some((option) => option === pageSize)) return;
    this.sessionsPagination.update((page) => ({ ...page, pageSize }));
    void this.loadSessions(0);
  }

  protected firstVisible(): number {
    const page = this.sessionsPagination();
    return page.totalItems === 0 ? 0 : page.pageIndex * page.pageSize + 1;
  }

  protected lastVisible(): number {
    const page = this.sessionsPagination();
    return Math.min((page.pageIndex + 1) * page.pageSize, page.totalItems);
  }

  private async loadStatistics(): Promise<void> {
    if (this.quizId === null) return;
    const version = ++this.statsRequestVersion;
    this.loading.set(true);
    this.error.set(null);
    try {
      const statistics = await firstValueFrom(this.api.getQuizStatistics(this.quizId));
      if (version === this.statsRequestVersion) this.statistics.set(statistics);
    } catch (error: unknown) {
      if (version !== this.statsRequestVersion) return;
      this.statistics.set(null);
      this.error.set(
        error instanceof HttpErrorResponse && error.status === 404 ? 'not-found' : 'generic',
      );
    } finally {
      if (version === this.statsRequestVersion) this.loading.set(false);
    }
  }

  private async loadSessions(pageIndex: number): Promise<void> {
    if (this.quizId === null) return;
    const version = ++this.sessionsRequestVersion;
    this.sessionsLoading.set(true);
    this.sessionsError.set(false);
    try {
      const response = await firstValueFrom(
        this.api.listSessions({
          pageIndex,
          pageSize: this.sessionsPagination().pageSize,
          status: 'FINISHED',
          quizId: this.quizId,
          sort: 'RECENT',
        }),
      );
      if (version !== this.sessionsRequestVersion) return;
      this.sessions.set(response.sessions);
      this.sessionsPagination.set(response.pagination);
    } catch {
      if (version !== this.sessionsRequestVersion) return;
      this.sessions.set([]);
      this.sessionsError.set(true);
    } finally {
      if (version === this.sessionsRequestVersion) this.sessionsLoading.set(false);
    }
  }
}
