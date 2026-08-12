import { Component, DestroyRef, inject, OnInit, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { firstValueFrom } from 'rxjs';

import { ActiveStatusBadge } from '../../../../../shared/ui/active-status-badge/active-status-badge';
import { QuizzesApiService } from '../../../quizzes/data-access/quizzes-api.service';
import { Pagination, QuizItem } from '../../../quizzes/data-access/quizzes.models';
import { StudentsApiService } from '../../../students/data-access/students-api.service';
import { Student } from '../../../students/data-access/students.models';
import { ResultsApiService } from '../../data-access/results-api.service';
import { SessionHistoryItem, SessionHistorySort } from '../../data-access/results.models';
import { formatStaffDate } from '../../utils/results-formatters';

type ResultsTab = 'sessions' | 'quizzes' | 'students';
type ListKind = ResultsTab;

const PAGE_SIZES = [5, 10, 20] as const;
const SEARCH_DEBOUNCE_MS = 300;
const emptyPagination = (pageSize = 10): Pagination => ({
  pageIndex: 0,
  pageSize,
  totalItems: 0,
  totalPages: 0,
});

@Component({
  selector: 'clq-results-home-page',
  imports: [ActiveStatusBadge, RouterLink],
  providers: [ResultsApiService, QuizzesApiService, StudentsApiService],
  templateUrl: './results-home-page.html',
  styleUrl: './results-home-page.scss',
})
export class ResultsHomePage implements OnInit {
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly resultsApi = inject(ResultsApiService);
  private readonly quizzesApi = inject(QuizzesApiService);
  private readonly studentsApi = inject(StudentsApiService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly requestVersions: Record<ListKind, number> = {
    sessions: 0,
    quizzes: 0,
    students: 0,
  };
  private readonly loaded: Record<ListKind, boolean> = {
    sessions: false,
    quizzes: false,
    students: false,
  };
  private searchTimer: ReturnType<typeof setTimeout> | null = null;

  protected readonly activeTab = signal<ResultsTab>('sessions');
  protected readonly pageSizeOptions = PAGE_SIZES;
  protected readonly formatDate = formatStaffDate;

  protected readonly sessions = signal<SessionHistoryItem[]>([]);
  protected readonly sessionsPagination = signal(emptyPagination());
  protected readonly sessionsLoading = signal(false);
  protected readonly sessionsError = signal(false);
  protected readonly sessionsSearch = signal('');
  protected readonly sessionSort = signal<SessionHistorySort>('RECENT');

  protected readonly quizzes = signal<QuizItem[]>([]);
  protected readonly quizzesPagination = signal(emptyPagination());
  protected readonly quizzesLoading = signal(false);
  protected readonly quizzesError = signal(false);
  protected readonly quizzesSearch = signal('');

  protected readonly students = signal<Student[]>([]);
  protected readonly studentsPagination = signal(emptyPagination());
  protected readonly studentsLoading = signal(false);
  protected readonly studentsError = signal(false);
  protected readonly studentsSearch = signal('');

  constructor() {
    this.destroyRef.onDestroy(() => this.clearSearchTimer());
  }

  ngOnInit(): void {
    this.route.queryParamMap.pipe(takeUntilDestroyed(this.destroyRef)).subscribe((params) => {
      const rawTab = params.get('tab');
      const tab: ResultsTab =
        rawTab === 'quizzes' || rawTab === 'students' || rawTab === 'sessions'
          ? rawTab
          : 'sessions';
      this.activeTab.set(tab);
      this.ensureLoaded(tab);
    });
  }

  protected selectTab(tab: ResultsTab): void {
    if (tab === this.activeTab()) return;
    void this.router.navigate([], {
      relativeTo: this.route,
      queryParams: { tab },
      queryParamsHandling: 'merge',
    });
  }

  protected updateSearch(kind: ListKind, event: Event): void {
    const value = event.target instanceof HTMLInputElement ? event.target.value : '';
    this.searchSignal(kind).set(value);
    this.clearSearchTimer();
    this.searchTimer = setTimeout(() => {
      this.searchTimer = null;
      this.load(kind, 0);
    }, SEARCH_DEBOUNCE_MS);
  }

  protected clearSearch(kind: ListKind): void {
    this.clearSearchTimer();
    this.searchSignal(kind).set('');
    this.load(kind, 0);
  }

  protected changeSessionSort(event: Event): void {
    if (!(event.target instanceof HTMLSelectElement)) return;
    const value = event.target.value;
    if (
      value === 'RECENT' ||
      value === 'OLDEST' ||
      value === 'QUIZ_TITLE_ASC' ||
      value === 'QUIZ_TITLE_DESC'
    ) {
      this.sessionSort.set(value);
      this.loadSessions(0);
    }
  }

  protected changePageSize(kind: ListKind, event: Event): void {
    if (!(event.target instanceof HTMLSelectElement)) return;
    const pageSize = Number(event.target.value);
    if (!PAGE_SIZES.some((option) => option === pageSize)) return;
    this.paginationSignal(kind).update((pagination) => ({
      ...pagination,
      pageIndex: 0,
      pageSize,
    }));
    this.load(kind, 0);
  }

  protected previousPage(kind: ListKind): void {
    const page = this.paginationSignal(kind)();
    if (page.pageIndex > 0 && !this.loadingSignal(kind)()) this.load(kind, page.pageIndex - 1);
  }

  protected nextPage(kind: ListKind): void {
    const page = this.paginationSignal(kind)();
    if (page.pageIndex + 1 < page.totalPages && !this.loadingSignal(kind)()) {
      this.load(kind, page.pageIndex + 1);
    }
  }

  protected firstVisible(kind: ListKind): number {
    const page = this.paginationSignal(kind)();
    return page.totalItems === 0 ? 0 : page.pageIndex * page.pageSize + 1;
  }

  protected lastVisible(kind: ListKind): number {
    const page = this.paginationSignal(kind)();
    return Math.min((page.pageIndex + 1) * page.pageSize, page.totalItems);
  }

  protected retry(kind: ListKind): void {
    this.load(kind, this.paginationSignal(kind)().pageIndex);
  }

  private ensureLoaded(kind: ListKind): void {
    if (this.loaded[kind]) return;
    this.loaded[kind] = true;
    this.load(kind, 0);
  }

  private load(kind: ListKind, pageIndex: number): void {
    if (kind === 'sessions') this.loadSessions(pageIndex);
    else if (kind === 'quizzes') this.loadQuizzes(pageIndex);
    else this.loadStudents(pageIndex);
  }

  private async loadSessions(pageIndex: number): Promise<void> {
    const version = ++this.requestVersions.sessions;
    this.sessionsLoading.set(true);
    this.sessionsError.set(false);
    const pageSize = this.sessionsPagination().pageSize;
    try {
      const response = await firstValueFrom(
        this.resultsApi.listSessions({
          pageIndex,
          pageSize,
          search: this.sessionsSearch().trim() || undefined,
          status: 'FINISHED',
          sort: this.sessionSort(),
        }),
      );
      if (version !== this.requestVersions.sessions) return;
      this.sessions.set(response.sessions);
      this.sessionsPagination.set(response.pagination);
    } catch {
      if (version !== this.requestVersions.sessions) return;
      this.sessions.set([]);
      this.sessionsPagination.set(emptyPagination(pageSize));
      this.sessionsError.set(true);
    } finally {
      if (version === this.requestVersions.sessions) this.sessionsLoading.set(false);
    }
  }

  private async loadQuizzes(pageIndex: number): Promise<void> {
    const version = ++this.requestVersions.quizzes;
    this.quizzesLoading.set(true);
    this.quizzesError.set(false);
    const pageSize = this.quizzesPagination().pageSize;
    try {
      const response = await firstValueFrom(
        this.quizzesApi.list({
          pageIndex,
          pageSize,
          search: this.quizzesSearch().trim(),
          topicId: null,
          status: 'all',
          sort: 'recent',
        }),
      );
      if (version !== this.requestVersions.quizzes) return;
      this.quizzes.set(response.quizzes);
      this.quizzesPagination.set(response.pagination);
    } catch {
      if (version !== this.requestVersions.quizzes) return;
      this.quizzes.set([]);
      this.quizzesPagination.set(emptyPagination(pageSize));
      this.quizzesError.set(true);
    } finally {
      if (version === this.requestVersions.quizzes) this.quizzesLoading.set(false);
    }
  }

  private async loadStudents(pageIndex: number): Promise<void> {
    const version = ++this.requestVersions.students;
    this.studentsLoading.set(true);
    this.studentsError.set(false);
    const pageSize = this.studentsPagination().pageSize;
    try {
      const response = await firstValueFrom(
        this.studentsApi.list(pageIndex, pageSize, this.studentsSearch().trim() || undefined),
      );
      if (version !== this.requestVersions.students) return;
      this.students.set(response.students);
      this.studentsPagination.set(response.pagination);
    } catch {
      if (version !== this.requestVersions.students) return;
      this.students.set([]);
      this.studentsPagination.set(emptyPagination(pageSize));
      this.studentsError.set(true);
    } finally {
      if (version === this.requestVersions.students) this.studentsLoading.set(false);
    }
  }

  private searchSignal(kind: ListKind) {
    return kind === 'sessions'
      ? this.sessionsSearch
      : kind === 'quizzes'
        ? this.quizzesSearch
        : this.studentsSearch;
  }

  private paginationSignal(kind: ListKind) {
    return kind === 'sessions'
      ? this.sessionsPagination
      : kind === 'quizzes'
        ? this.quizzesPagination
        : this.studentsPagination;
  }

  private loadingSignal(kind: ListKind) {
    return kind === 'sessions'
      ? this.sessionsLoading
      : kind === 'quizzes'
        ? this.quizzesLoading
        : this.studentsLoading;
  }

  private clearSearchTimer(): void {
    if (this.searchTimer !== null) clearTimeout(this.searchTimer);
    this.searchTimer = null;
  }
}
