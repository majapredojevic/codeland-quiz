import { HttpErrorResponse } from '@angular/common/http';
import { DestroyRef, Service, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { catchError, firstValueFrom, map, of, Subject, switchMap } from 'rxjs';

import { QuizzesApiService } from './quizzes-api.service';
import {
  CreateTopicRequest,
  Pagination,
  QuizItem,
  QuizListQuery,
  QuizSort,
  QuizStatusFilter,
  TopicItem,
  UpdateTopicRequest,
} from './quizzes.models';
import { TopicsApiService } from './topics-api.service';

export const QUIZZES_DEFAULT_PAGE_SIZE = 10;
export const TOPICS_PAGE_SIZE = 20;

const emptyPagination = (pageSize: number): Pagination => ({
  pageIndex: 0,
  pageSize,
  totalItems: 0,
  totalPages: 0,
});

type TopicResolution =
  { kind: 'found'; topic: TopicItem } | { kind: 'not-found' } | { kind: 'unavailable' };

@Service()
export class QuizLibraryStore {
  private readonly quizzesApi = inject(QuizzesApiService);
  private readonly topicsApi = inject(TopicsApiService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly quizRequests = new Subject<QuizListQuery>();

  private readonly quizzesState = signal<QuizItem[]>([]);
  private readonly quizPaginationState = signal(emptyPagination(QUIZZES_DEFAULT_PAGE_SIZE));
  private readonly quizLoadingState = signal(false);
  private readonly quizErrorState = signal<string | null>(null);
  private readonly searchState = signal('');
  private readonly selectedTopicIdState = signal<number | null>(null);
  private readonly selectedTopicState = signal<TopicItem | null>(null);
  private readonly statusState = signal<QuizStatusFilter>('all');
  private readonly sortState = signal<QuizSort>('recent');
  private readonly pageIndexState = signal(0);
  private readonly pageSizeState = signal(QUIZZES_DEFAULT_PAGE_SIZE);

  private readonly topicsState = signal<TopicItem[]>([]);
  private readonly topicPaginationState = signal(emptyPagination(TOPICS_PAGE_SIZE));
  private readonly topicsLoadingState = signal(false);
  private readonly topicsLoadingMoreState = signal(false);
  private readonly topicsErrorState = signal<string | null>(null);

  readonly quizzes = this.quizzesState.asReadonly();
  readonly quizPagination = this.quizPaginationState.asReadonly();
  readonly quizLoading = this.quizLoadingState.asReadonly();
  readonly quizError = this.quizErrorState.asReadonly();
  readonly search = this.searchState.asReadonly();
  readonly selectedTopicId = this.selectedTopicIdState.asReadonly();
  readonly selectedTopic = this.selectedTopicState.asReadonly();
  readonly status = this.statusState.asReadonly();
  readonly sort = this.sortState.asReadonly();
  readonly pageIndex = this.pageIndexState.asReadonly();
  readonly pageSize = this.pageSizeState.asReadonly();
  readonly topics = this.topicsState.asReadonly();
  readonly topicPagination = this.topicPaginationState.asReadonly();
  readonly topicsLoading = this.topicsLoadingState.asReadonly();
  readonly topicsLoadingMore = this.topicsLoadingMoreState.asReadonly();
  readonly topicsError = this.topicsErrorState.asReadonly();
  readonly hasMoreTopics = computed(
    () => this.topicPagination().pageIndex + 1 < this.topicPagination().totalPages,
  );

  constructor() {
    this.quizRequests
      .pipe(
        switchMap((query) => {
          this.quizLoadingState.set(true);
          this.quizErrorState.set(null);

          return this.quizzesApi.list(query).pipe(
            map((response) => ({ kind: 'success' as const, response })),
            catchError(() => of({ kind: 'error' as const, query })),
          );
        }),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe((result) => {
        this.quizLoadingState.set(false);

        if (result.kind === 'success') {
          this.quizzesState.set(result.response.quizzes);
          this.quizPaginationState.set(result.response.pagination);
          this.pageIndexState.set(result.response.pagination.pageIndex);
          this.pageSizeState.set(result.response.pagination.pageSize);
          return;
        }

        this.quizzesState.set([]);
        this.quizPaginationState.set({
          pageIndex: result.query.pageIndex,
          pageSize: result.query.pageSize,
          totalItems: 0,
          totalPages: 0,
        });
        this.quizErrorState.set('Nije moguće učitati kvizove. Pokušajte ponovo.');
      });
  }

  loadQuizzes(): void {
    this.quizRequests.next(this.currentQuery());
  }

  setSearch(value: string): void {
    const search = value.trim();
    if (search === this.search()) return;
    this.searchState.set(search);
    this.pageIndexState.set(0);
    this.loadQuizzes();
  }

  setTopic(topic: TopicItem | null): void {
    const id = topic?.id ?? null;
    if (id === this.selectedTopicId()) {
      this.selectedTopicState.set(topic);
      return;
    }
    this.selectedTopicIdState.set(id);
    this.selectedTopicState.set(topic);
    this.pageIndexState.set(0);
    this.loadQuizzes();
  }

  setTopicId(id: number | null): void {
    const matchingTopic =
      id === null ? null : (this.topics().find((topic) => topic.id === id) ?? null);
    if (id === this.selectedTopicId()) {
      if (matchingTopic) this.selectedTopicState.set(matchingTopic);
      return;
    }
    this.selectedTopicIdState.set(id);
    this.selectedTopicState.set(matchingTopic);
    this.pageIndexState.set(0);
    this.loadQuizzes();
  }

  setResolvedTopic(topic: TopicItem): void {
    if (this.selectedTopicId() === topic.id) this.selectedTopicState.set(topic);
  }

  setStatus(status: QuizStatusFilter): void {
    if (status === this.status()) return;
    this.statusState.set(status);
    this.pageIndexState.set(0);
    this.loadQuizzes();
  }

  setSort(sort: QuizSort): void {
    if (sort === this.sort()) return;
    this.sortState.set(sort);
    this.pageIndexState.set(0);
    this.loadQuizzes();
  }

  setPageSize(pageSize: number): void {
    if (pageSize === this.pageSize()) return;
    this.assertPageSize(pageSize);
    this.pageSizeState.set(pageSize);
    this.pageIndexState.set(0);
    this.loadQuizzes();
  }

  setPage(pageIndex: number): void {
    if (!Number.isInteger(pageIndex) || pageIndex < 0 || pageIndex === this.pageIndex()) return;
    this.pageIndexState.set(pageIndex);
    this.loadQuizzes();
  }

  async loadTopics(reset = true): Promise<void> {
    if (this.topicsLoading() || this.topicsLoadingMore()) return;
    const pageIndex = reset ? 0 : this.topicPagination().pageIndex + 1;
    if (!reset && !this.hasMoreTopics()) return;

    (reset ? this.topicsLoadingState : this.topicsLoadingMoreState).set(true);
    this.topicsErrorState.set(null);
    try {
      const response = await firstValueFrom(
        this.topicsApi.list(pageIndex, TOPICS_PAGE_SIZE, 'nameAsc'),
      );
      const merged = reset ? response.topics : [...this.topics(), ...response.topics];
      this.topicsState.set(this.uniqueTopics(merged));
      this.topicPaginationState.set(response.pagination);
      const selected = this.topics().find((topic) => topic.id === this.selectedTopicId());
      if (selected) this.selectedTopicState.set(selected);
    } catch {
      this.topicsErrorState.set('Nije moguće učitati teme. Pokušajte ponovo.');
    } finally {
      this.topicsLoadingState.set(false);
      this.topicsLoadingMoreState.set(false);
    }
  }

  async resolveTopic(id: number): Promise<TopicResolution> {
    const loaded = this.topics().find((topic) => topic.id === id);
    if (loaded) return { kind: 'found', topic: loaded };
    try {
      const response = await firstValueFrom(this.topicsApi.get(id));
      return { kind: 'found', topic: response.topic };
    } catch (error) {
      return error instanceof HttpErrorResponse && error.status === 404
        ? { kind: 'not-found' }
        : { kind: 'unavailable' };
    }
  }

  async createTopic(request: CreateTopicRequest): Promise<TopicItem> {
    const response = await firstValueFrom(this.topicsApi.create(request));
    this.topicsState.update((topics) => this.uniqueTopics([...topics, response.topic]));
    this.topicPaginationState.update((pagination) => ({
      ...pagination,
      totalItems: pagination.totalItems + 1,
      totalPages: Math.ceil((pagination.totalItems + 1) / TOPICS_PAGE_SIZE),
    }));
    return response.topic;
  }

  async updateTopic(id: number, request: UpdateTopicRequest): Promise<TopicItem> {
    const response = await firstValueFrom(this.topicsApi.update(id, request));
    this.topicsState.update((topics) =>
      this.uniqueTopics(topics.map((topic) => (topic.id === id ? response.topic : topic))),
    );
    if (this.selectedTopicId() === id) this.selectedTopicState.set(response.topic);
    return response.topic;
  }

  async deleteTopic(id: number): Promise<void> {
    await firstValueFrom(this.topicsApi.delete(id));
    this.topicsState.update((topics) => topics.filter((topic) => topic.id !== id));
    this.topicPaginationState.update((pagination) => {
      const totalItems = Math.max(0, pagination.totalItems - 1);
      return { ...pagination, totalItems, totalPages: Math.ceil(totalItems / TOPICS_PAGE_SIZE) };
    });
  }

  private currentQuery(): QuizListQuery {
    return {
      pageIndex: this.pageIndex(),
      pageSize: this.pageSize(),
      search: this.search(),
      topicId: this.selectedTopicId(),
      status: this.status(),
      sort: this.sort(),
    };
  }

  private uniqueTopics(topics: TopicItem[]): TopicItem[] {
    return [...new Map(topics.map((topic) => [topic.id, topic])).values()].sort((a, b) =>
      a.name.localeCompare(b.name, 'bs'),
    );
  }

  private assertPageSize(pageSize: number): void {
    if (![5, 10, 20].includes(pageSize)) throw new RangeError('Unsupported page size.');
  }
}
