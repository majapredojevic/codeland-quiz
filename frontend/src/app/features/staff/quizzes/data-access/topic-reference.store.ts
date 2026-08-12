import { Service, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';

import { TopicItem } from './quizzes.models';
import { TopicsApiService } from './topics-api.service';

const REFERENCE_PAGE_SIZE = 20;

@Service()
export class TopicReferenceStore {
  private readonly topicsApi = inject(TopicsApiService);
  private readonly topicsState = signal<TopicItem[]>([]);
  private readonly loadingState = signal(false);
  private readonly errorState = signal<string | null>(null);
  private loaded = false;
  private pendingLoad: Promise<void> | null = null;

  readonly topics = this.topicsState.asReadonly();
  readonly loading = this.loadingState.asReadonly();
  readonly error = this.errorState.asReadonly();

  loadAll(force = false): Promise<void> {
    if (this.pendingLoad !== null) return this.pendingLoad;
    if (this.loaded && !force) return Promise.resolve();

    this.pendingLoad = this.performLoad().finally(() => (this.pendingLoad = null));
    return this.pendingLoad;
  }

  private async performLoad(): Promise<void> {
    this.loadingState.set(true);
    this.errorState.set(null);
    try {
      const topics: TopicItem[] = [];
      let pageIndex = 0;
      let totalPages = 1;

      while (pageIndex < totalPages) {
        const response = await firstValueFrom(
          this.topicsApi.list(pageIndex, REFERENCE_PAGE_SIZE, 'nameAsc'),
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
      this.loaded = true;
    } catch {
      this.errorState.set('Nije moguće učitati teme. Pokušajte ponovo.');
      this.loaded = false;
    } finally {
      this.loadingState.set(false);
    }
  }
}
