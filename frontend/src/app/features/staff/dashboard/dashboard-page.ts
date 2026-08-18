import { Component, DestroyRef, inject, OnInit } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ActivatedRoute, ParamMap, Router } from '@angular/router';
import { distinctUntilChanged, map } from 'rxjs';

import { AuthStore } from '../../../core/auth/auth.store';
import { formatCodeLandDate } from '../../../shared/utils/date-formatters';
import { PlayQuizCard } from '../play/components/play-quiz-card/play-quiz-card';
import { PlayHubStore } from '../play/data-access/play-hub.store';
import { PlayableQuiz } from '../play/data-access/play.models';
import { QuizLaunchService } from '../play/data-access/quiz-launch.service';
import { QuizSessionsApiService } from '../play/data-access/quiz-sessions-api.service';
import { QuizzesApiService } from '../quizzes/data-access/quizzes-api.service';
import { TopicsApiService } from '../quizzes/data-access/topics-api.service';

@Component({
  selector: 'clq-dashboard-page',
  imports: [PlayQuizCard],
  providers: [PlayHubStore, QuizSessionsApiService, QuizzesApiService, TopicsApiService],
  templateUrl: './dashboard-page.html',
  styleUrl: './dashboard-page.scss',
})
export class DashboardPage implements OnInit {
  private readonly authStore = inject(AuthStore);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly store = inject(PlayHubStore);
  protected readonly launcher = inject(QuizLaunchService);
  protected readonly user = this.authStore.user;

  ngOnInit(): void {
    const initialTopicId = this.topicIdFrom(this.route.snapshot.queryParamMap);
    void this.store.initialize(initialTopicId);

    this.route.queryParamMap
      .pipe(
        map((params) => this.topicIdFrom(params)),
        distinctUntilChanged(),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe((topicId) => void this.store.selectTopic(topicId));
  }

  protected selectTopic(topicId: number | null): void {
    void this.router.navigate([], {
      relativeTo: this.route,
      queryParams: { topicId },
      queryParamsHandling: 'merge',
      replaceUrl: true,
    });
    void this.store.selectTopic(topicId);
  }

  protected async playQuiz(quiz: PlayableQuiz): Promise<void> {
    await this.launcher.launch(quiz.id);
  }

  protected lastPlayedLabel(value: string | null): string | null {
    if (!value) return null;
    const formatted = formatCodeLandDate(value);
    return formatted === '—' ? null : `Igrano ${formatted}`;
  }

  private topicIdFrom(params: ParamMap): number | null {
    const raw = params.get('topicId');
    if (raw === null || !/^\d+$/.test(raw)) return null;
    const id = Number(raw);
    return Number.isSafeInteger(id) && id > 0 ? id : null;
  }
}
