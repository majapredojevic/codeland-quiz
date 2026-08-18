import { Service, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { firstValueFrom } from 'rxjs';

import { NotificationService } from '../../../../shared/feedback/notification.service';
import { QuizSessionsApiService } from './quiz-sessions-api.service';

@Service()
export class QuizLaunchService {
  private readonly sessionsApi = inject(QuizSessionsApiService);
  private readonly router = inject(Router);
  private readonly notifications = inject(NotificationService);
  private readonly startingQuizIdState = signal<number | null>(null);

  readonly startingQuizId = this.startingQuizIdState.asReadonly();

  async launch(quizId: number): Promise<boolean> {
    if (!Number.isSafeInteger(quizId) || quizId < 1 || this.startingQuizId() !== null) {
      return false;
    }

    this.startingQuizIdState.set(quizId);
    try {
      const response = await firstValueFrom(this.sessionsApi.create(quizId));
      return await this.router.navigate(['/app/sessions', response.session.id]);
    } catch {
      this.notifications.error(
        'Nije moguće pokrenuti kviz. Provjerite da li je aktivan i ima važeća pitanja.',
      );
      return false;
    } finally {
      this.startingQuizIdState.set(null);
    }
  }
}
