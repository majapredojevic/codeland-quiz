import { HttpErrorResponse } from '@angular/common/http';
import { Component, computed, DestroyRef, inject, OnInit, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { MatDialog } from '@angular/material/dialog';
import { ActivatedRoute, Params, Router, RouterLink } from '@angular/router';
import { firstValueFrom } from 'rxjs';

import {
  ConfirmDialog,
  ConfirmDialogData,
} from '../../../../../shared/feedback/confirm-dialog/confirm-dialog';
import { NotificationService } from '../../../../../shared/feedback/notification.service';
import { ActiveStatusBadge } from '../../../../../shared/ui/active-status-badge/active-status-badge';
import { TopicCard } from '../../components/topic-card/topic-card';
import { TopicDialog, TopicDialogData } from '../../components/topic-dialog/topic-dialog';
import { QuizLibraryStore } from '../../data-access/quiz-library.store';
import { QuizSort, QuizStatusFilter, TopicItem } from '../../data-access/quizzes.models';

const SEARCH_DEBOUNCE_MS = 300;
const PAGE_SIZE_OPTIONS = [5, 10, 20] as const;
const COLLAPSED_TOPIC_LIMIT = 8;

@Component({
  selector: 'clq-quiz-library-page',
  imports: [ActiveStatusBadge, RouterLink, TopicCard],
  templateUrl: './quiz-library-page.html',
  styleUrl: './quiz-library-page.scss',
})
export class QuizLibraryPage implements OnInit {
  protected readonly store = inject(QuizLibraryStore);
  private readonly dialog = inject(MatDialog);
  private readonly notifications = inject(NotificationService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly destroyRef = inject(DestroyRef);
  private searchTimer: ReturnType<typeof setTimeout> | null = null;
  private topicResolutionVersion = 0;
  private initialQuizRequestMade = false;
  private initialTopicsLoad: Promise<void> | null = null;

  protected readonly searchValue = signal('');
  protected readonly topicsExpanded = signal(false);
  protected readonly pageSizeOptions = PAGE_SIZE_OPTIONS;
  protected readonly visibleTopics = computed(() => {
    const topics = this.store.topics();
    const selected = this.store.selectedTopic();
    const loadedTopics =
      selected && !topics.some((topic) => topic.id === selected.id)
        ? [...topics, selected]
        : topics;

    if (this.topicsExpanded() || loadedTopics.length <= COLLAPSED_TOPIC_LIMIT) {
      return loadedTopics;
    }

    if (
      selected &&
      !loadedTopics.slice(0, COLLAPSED_TOPIC_LIMIT).some(({ id }) => id === selected.id)
    ) {
      return [...loadedTopics.slice(0, COLLAPSED_TOPIC_LIMIT - 1), selected];
    }

    return loadedTopics.slice(0, COLLAPSED_TOPIC_LIMIT);
  });
  protected readonly hasCollapsedTopics = computed(
    () =>
      this.store.topicPagination().totalItems > COLLAPSED_TOPIC_LIMIT ||
      this.visibleTopicCatalogSize() > COLLAPSED_TOPIC_LIMIT,
  );

  constructor() {
    this.destroyRef.onDestroy(() => {
      this.clearSearchTimer();
      ++this.topicResolutionVersion;
    });
  }

  ngOnInit(): void {
    this.searchValue.set(this.store.search());
    void this.initialize();
  }

  protected updateSearch(event: Event): void {
    const value = event.target instanceof HTMLInputElement ? event.target.value : '';
    this.searchValue.set(value);
    this.clearSearchTimer();
    this.searchTimer = setTimeout(() => {
      this.searchTimer = null;
      this.store.setSearch(value);
    }, SEARCH_DEBOUNCE_MS);
  }

  protected clearSearch(): void {
    this.clearSearchTimer();
    this.searchValue.set('');
    this.store.setSearch('');
  }

  protected changeStatus(event: Event): void {
    if (!(event.target instanceof HTMLSelectElement)) return;
    const status = event.target.value;
    if (status === 'all' || status === 'active' || status === 'inactive') {
      this.store.setStatus(status satisfies QuizStatusFilter);
    }
  }

  protected changeSort(event: Event): void {
    if (!(event.target instanceof HTMLSelectElement)) return;
    const sort = event.target.value;
    if (sort === 'recent' || sort === 'titleAsc' || sort === 'titleDesc') {
      this.store.setSort(sort satisfies QuizSort);
    }
  }

  protected selectTopic(topic: TopicItem | null): void {
    void this.updateTopicQuery(topic?.id ?? null);
  }

  protected toggleTopicsExpanded(): void {
    this.topicsExpanded.update((expanded) => !expanded);
  }

  protected async openCreateTopic(): Promise<void> {
    const data: TopicDialogData = { mode: 'create' };
    const result = await firstValueFrom(
      this.dialog
        .open<TopicDialog, TopicDialogData, TopicItem>(TopicDialog, {
          data,
          panelClass: 'clq-topic-dialog-panel',
          width: 'min(34rem, calc(100vw - 2rem))',
        })
        .afterClosed(),
    );
    if (result) this.notifications.success('Tema je uspješno kreirana.');
  }

  protected async openEditTopic(topic: TopicItem): Promise<void> {
    const data: TopicDialogData = { mode: 'edit', topic };
    const result = await firstValueFrom(
      this.dialog
        .open<TopicDialog, TopicDialogData, TopicItem>(TopicDialog, {
          data,
          panelClass: 'clq-topic-dialog-panel',
          width: 'min(34rem, calc(100vw - 2rem))',
        })
        .afterClosed(),
    );
    if (result) this.notifications.success('Izmjene su sačuvane.');
  }

  protected async confirmDeleteTopic(topic: TopicItem): Promise<void> {
    if (topic.quizCount > 0) return;

    const data: ConfirmDialogData = {
      title: 'Obrisati temu?',
      message: `Tema "${topic.name}" će biti trajno obrisana.`,
      confirmLabel: 'Obriši temu',
      tone: 'destructive',
    };
    const confirmed = await firstValueFrom(
      this.dialog
        .open<ConfirmDialog, ConfirmDialogData, boolean>(ConfirmDialog, {
          data,
          panelClass: 'clq-confirm-dialog-panel',
          width: 'min(30rem, calc(100vw - 2rem))',
        })
        .afterClosed(),
    );
    if (!confirmed) return;

    try {
      await this.store.deleteTopic(topic.id);
      if (this.store.selectedTopicId() === topic.id) {
        this.store.setTopicId(null);
        await this.updateTopicQuery(null).catch(() => undefined);
      }
      this.notifications.success('Tema je obrisana.');
    } catch (error: unknown) {
      if (error instanceof HttpErrorResponse && error.status === 409) {
        this.notifications.error('Tema se ne može obrisati dok sadrži kvizove.');
        await this.store.loadTopics(true);
      } else {
        this.notifications.error('Nije moguće obrisati temu.');
      }
    }
  }

  protected previousPage(): void {
    if (this.store.pageIndex() > 0 && !this.store.quizLoading()) {
      this.store.setPage(this.store.pageIndex() - 1);
    }
  }

  protected nextPage(): void {
    if (
      this.store.pageIndex() + 1 < this.store.quizPagination().totalPages &&
      !this.store.quizLoading()
    ) {
      this.store.setPage(this.store.pageIndex() + 1);
    }
  }

  protected changePageSize(event: Event): void {
    if (!(event.target instanceof HTMLSelectElement) || this.store.quizLoading()) return;
    const size = Number(event.target.value);
    if (PAGE_SIZE_OPTIONS.some((option) => option === size)) this.store.setPageSize(size);
  }

  protected retryQuizzes(): void {
    this.store.loadQuizzes();
  }

  protected firstVisibleItem(): number {
    const page = this.store.quizPagination();
    return page.totalItems === 0 ? 0 : page.pageIndex * page.pageSize + 1;
  }

  protected lastVisibleItem(): number {
    const page = this.store.quizPagination();
    return Math.min((page.pageIndex + 1) * page.pageSize, page.totalItems);
  }

  protected quizEmptyMessage(): string {
    const hasSearch = this.store.search().length > 0;
    const hasTopic = this.store.selectedTopicId() !== null;
    const hasStatus = this.store.status() !== 'all';
    if (hasSearch && hasTopic) return 'Nema kvizova u ovoj temi koji odgovaraju pretrazi.';
    if (hasStatus && hasTopic) return 'Nema kvizova u ovoj temi sa odabranim statusom.';
    if (hasTopic) return 'Ova tema još nema kvizova.';
    if (hasSearch) return 'Nema kvizova koji odgovaraju pretrazi.';
    if (hasStatus) return 'Nema kvizova sa odabranim statusom.';
    return 'Nema kvizova za prikaz.';
  }

  private initialize(): void {
    this.initialTopicsLoad = this.store.loadTopics(true);
    this.route.queryParamMap.pipe(takeUntilDestroyed(this.destroyRef)).subscribe((params) => {
      void this.restoreTopicFromUrl(params.get('topicId'));
    });
  }

  private async restoreTopicFromUrl(rawTopicId: string | null): Promise<void> {
    const version = ++this.topicResolutionVersion;
    if (rawTopicId === null) {
      this.activateAllQuizzes();
      return;
    }

    const topicId = Number(rawTopicId);
    if (!Number.isSafeInteger(topicId) || topicId < 1 || String(topicId) !== rawTopicId) {
      this.activateAllQuizzes();
      await this.updateTopicQuery(null, true);
      return;
    }

    this.initialQuizRequestMade = true;
    this.store.setTopicId(topicId);
    await this.initialTopicsLoad;
    if (version !== this.topicResolutionVersion || this.destroyRef.destroyed) return;
    const resolution = await this.store.resolveTopic(topicId);
    if (version !== this.topicResolutionVersion || this.destroyRef.destroyed) return;

    if (resolution.kind === 'found') this.store.setResolvedTopic(resolution.topic);
    else if (resolution.kind === 'not-found') {
      this.activateAllQuizzes();
      await this.updateTopicQuery(null, true);
    }
  }

  private activateAllQuizzes(): void {
    if (this.store.selectedTopicId() !== null) this.store.setTopicId(null);
    else if (!this.initialQuizRequestMade) this.store.loadQuizzes();
    this.initialQuizRequestMade = true;
  }

  private async updateTopicQuery(topicId: number | null, replaceUrl = false): Promise<void> {
    const queryParams: Params = { topicId: topicId ?? null };
    await this.router.navigate([], {
      relativeTo: this.route,
      queryParams,
      queryParamsHandling: 'merge',
      replaceUrl,
    });
  }

  private clearSearchTimer(): void {
    if (this.searchTimer !== null) {
      clearTimeout(this.searchTimer);
      this.searchTimer = null;
    }
  }

  private visibleTopicCatalogSize(): number {
    const selected = this.store.selectedTopic();
    return (
      this.store.topics().length +
      (selected && !this.store.topics().some((topic) => topic.id === selected.id) ? 1 : 0)
    );
  }
}
