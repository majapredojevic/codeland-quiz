import { Service, computed, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';

import { QuizzesApiService } from '../../quizzes/data-access/quizzes-api.service';
import { Pagination, QuizItem, TopicItem } from '../../quizzes/data-access/quizzes.models';
import { TopicsApiService } from '../../quizzes/data-access/topics-api.service';
import { PlayableQuiz, RecentPlayableQuiz } from './play.models';
import { QuizSessionsApiService } from './quiz-sessions-api.service';

const TOPIC_PAGE_SIZE = 20;
const QUIZ_PAGE_SIZE = 12;
const COLLAPSED_TOPIC_LIMIT = 8;

const emptyPagination = (): Pagination => ({
  pageIndex: 0,
  pageSize: QUIZ_PAGE_SIZE,
  totalItems: 0,
  totalPages: 0,
});

@Service()
export class PlayHubStore {
  private readonly quizzesApi = inject(QuizzesApiService);
  private readonly topicsApi = inject(TopicsApiService);
  private readonly sessionsApi = inject(QuizSessionsApiService);

  private readonly topicsState = signal<TopicItem[]>([]);
  private readonly topicsLoadingState = signal(false);
  private readonly topicsErrorState = signal(false);
  private readonly topicsExpandedState = signal(false);
  private readonly selectedTopicIdState = signal<number | null>(null);
  private readonly quizzesState = signal<PlayableQuiz[]>([]);
  private readonly quizPaginationState = signal<Pagination>(emptyPagination());
  private readonly quizzesLoadingState = signal(false);
  private readonly quizzesLoadingMoreState = signal(false);
  private readonly quizzesErrorState = signal(false);
  private readonly recentQuizzesState = signal<RecentPlayableQuiz[]>([]);
  private readonly recentLoadingState = signal(false);
  private readonly startingQuizIdState = signal<number | null>(null);
  private quizRequestVersion = 0;

  readonly topics = this.topicsState.asReadonly();
  readonly topicsLoading = this.topicsLoadingState.asReadonly();
  readonly topicsError = this.topicsErrorState.asReadonly();
  readonly topicsExpanded = this.topicsExpandedState.asReadonly();
  readonly selectedTopicId = this.selectedTopicIdState.asReadonly();
  readonly quizzes = this.quizzesState.asReadonly();
  readonly quizPagination = this.quizPaginationState.asReadonly();
  readonly quizzesLoading = this.quizzesLoadingState.asReadonly();
  readonly quizzesLoadingMore = this.quizzesLoadingMoreState.asReadonly();
  readonly quizzesError = this.quizzesErrorState.asReadonly();
  readonly recentQuizzes = this.recentQuizzesState.asReadonly();
  readonly recentLoading = this.recentLoadingState.asReadonly();
  readonly startingQuizId = this.startingQuizIdState.asReadonly();
  readonly canToggleTopics = computed(() => this.topics().length > COLLAPSED_TOPIC_LIMIT);
  readonly visibleTopics = computed(() => {
    const topics = this.topics();
    if (this.topicsExpanded() || topics.length <= COLLAPSED_TOPIC_LIMIT) return topics;

    const visible = topics.slice(0, COLLAPSED_TOPIC_LIMIT);
    const selectedId = this.selectedTopicId();
    if (selectedId === null || visible.some(({ id }) => id === selectedId)) return visible;

    const selected = topics.find(({ id }) => id === selectedId);
    return selected ? [...visible.slice(0, COLLAPSED_TOPIC_LIMIT - 1), selected] : visible;
  });
  readonly hasMoreQuizzes = computed(
    () => this.quizPagination().pageIndex + 1 < this.quizPagination().totalPages,
  );

  async initialize(selectedTopicId: number | null): Promise<void> {
    this.selectedTopicIdState.set(this.validIdOrNull(selectedTopicId));
    await Promise.all([this.loadTopics(), this.loadQuizzes(true), this.loadRecentQuizzes()]);
  }

  async loadTopics(): Promise<void> {
    if (this.topicsLoading()) return;
    this.topicsLoadingState.set(true);
    this.topicsErrorState.set(false);
    try {
      const topics: TopicItem[] = [];
      let pageIndex = 0;
      let totalPages = 1;
      while (pageIndex < totalPages) {
        const response = await firstValueFrom(
          this.topicsApi.list(pageIndex, TOPIC_PAGE_SIZE, 'nameAsc'),
        );
        topics.push(...response.topics);
        totalPages = response.pagination.totalPages;
        pageIndex += 1;
      }
      this.topicsState.set(
        [...new Map(topics.map((topic) => [topic.id, topic])).values()].sort((left, right) =>
          left.name.localeCompare(right.name, 'bs'),
        ),
      );
      if (
        this.selectedTopicId() !== null &&
        !this.topics().some(({ id }) => id === this.selectedTopicId())
      ) {
        this.selectedTopicIdState.set(null);
        await this.loadQuizzes(true);
      }
    } catch {
      this.topicsErrorState.set(true);
    } finally {
      this.topicsLoadingState.set(false);
    }
  }

  async selectTopic(topicId: number | null): Promise<void> {
    const normalizedId = this.validIdOrNull(topicId);
    if (normalizedId === this.selectedTopicId()) return;
    this.selectedTopicIdState.set(normalizedId);
    await this.loadQuizzes(true);
  }

  toggleTopics(): void {
    this.topicsExpandedState.update((expanded) => !expanded);
  }

  async loadQuizzes(reset: boolean): Promise<void> {
    if (!reset && (this.quizzesLoading() || this.quizzesLoadingMore() || !this.hasMoreQuizzes())) {
      return;
    }

    const pageIndex = reset ? 0 : this.quizPagination().pageIndex + 1;
    const version = ++this.quizRequestVersion;
    (reset ? this.quizzesLoadingState : this.quizzesLoadingMoreState).set(true);
    this.quizzesErrorState.set(false);
    try {
      const response = await firstValueFrom(
        this.quizzesApi.list({
          pageIndex,
          pageSize: QUIZ_PAGE_SIZE,
          search: '',
          topicId: this.selectedTopicId(),
          status: 'active',
          sort: 'recent',
        }),
      );
      if (version !== this.quizRequestVersion) return;
      const quizzes = response.quizzes.map((quiz) => this.toPlayableQuiz(quiz));
      this.quizzesState.set(reset ? quizzes : this.uniqueQuizzes([...this.quizzes(), ...quizzes]));
      this.quizPaginationState.set(response.pagination);
    } catch {
      if (version !== this.quizRequestVersion) return;
      if (reset) this.quizzesState.set([]);
      this.quizzesErrorState.set(true);
    } finally {
      if (version === this.quizRequestVersion) {
        this.quizzesLoadingState.set(false);
        this.quizzesLoadingMoreState.set(false);
      }
    }
  }

  async loadRecentQuizzes(): Promise<void> {
    if (this.recentLoading()) return;
    this.recentLoadingState.set(true);
    try {
      const response = await firstValueFrom(this.sessionsApi.listRecentFinished());
      const sessions = [
        ...new Map(response.sessions.map((session) => [session.quizId, session])).values(),
      ].slice(0, 4);
      const resolved = await Promise.all(
        sessions.map(async (session): Promise<RecentPlayableQuiz | null> => {
          try {
            const { quiz } = await firstValueFrom(this.quizzesApi.get(session.quizId));
            if (!quiz.isActive) return null;
            return {
              ...this.toPlayableQuiz(quiz),
              questionCount: session.questionCount,
              lastPlayedAt: session.endedAt ?? session.startedAt,
            };
          } catch {
            return null;
          }
        }),
      );
      this.recentQuizzesState.set(
        resolved.filter((quiz): quiz is RecentPlayableQuiz => quiz !== null),
      );
    } catch {
      this.recentQuizzesState.set([]);
    } finally {
      this.recentLoadingState.set(false);
    }
  }

  async createSession(quizId: number): Promise<number> {
    if (this.startingQuizId() !== null) {
      throw new Error('A quiz session is already being created.');
    }
    this.startingQuizIdState.set(quizId);
    try {
      return (await firstValueFrom(this.sessionsApi.create(quizId))).session.id;
    } finally {
      this.startingQuizIdState.set(null);
    }
  }

  private toPlayableQuiz(quiz: QuizItem): PlayableQuiz {
    return {
      id: quiz.id,
      title: quiz.title,
      description: quiz.description,
      questionCount: quiz.questionCount,
      topic: quiz.topic,
    };
  }

  private uniqueQuizzes(quizzes: PlayableQuiz[]): PlayableQuiz[] {
    return [...new Map(quizzes.map((quiz) => [quiz.id, quiz])).values()];
  }

  private validIdOrNull(id: number | null): number | null {
    return id !== null && Number.isSafeInteger(id) && id > 0 ? id : null;
  }
}
