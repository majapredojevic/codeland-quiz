import { HttpErrorResponse } from '@angular/common/http';
import { Service, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';

import {
  FinalResult,
  PublicSessionQuestion,
  QuestionResult,
  QuizSession,
  SessionParticipant,
  SessionParticipantsResponse,
} from './play.models';
import { QuizSessionsApiService } from './quiz-sessions-api.service';

@Service()
export class LobbyStore {
  private readonly sessionsApi = inject(QuizSessionsApiService);
  private readonly sessionState = signal<QuizSession | null>(null);
  private readonly participantsState = signal<SessionParticipant[]>([]);
  private readonly participantSummaryState = signal<SessionParticipantsResponse | null>(null);
  private readonly currentQuestionState = signal<PublicSessionQuestion | null>(null);
  private readonly questionResultState = signal<QuestionResult | null>(null);
  private readonly finalResultState = signal<FinalResult | null>(null);
  private readonly loadingState = signal(false);
  private readonly errorState = signal<'not-found' | 'load' | null>(null);
  private readonly startingState = signal(false);
  private readonly lifecyclePendingState = signal(false);
  private readonly removingParticipantIdState = signal<number | null>(null);
  private requestVersion = 0;

  readonly session = this.sessionState.asReadonly();
  readonly participants = this.participantsState.asReadonly();
  readonly participantSummary = this.participantSummaryState.asReadonly();
  readonly currentQuestion = this.currentQuestionState.asReadonly();
  readonly questionResult = this.questionResultState.asReadonly();
  readonly finalResult = this.finalResultState.asReadonly();
  readonly loading = this.loadingState.asReadonly();
  readonly error = this.errorState.asReadonly();
  readonly starting = this.startingState.asReadonly();
  readonly lifecyclePending = this.lifecyclePendingState.asReadonly();
  readonly removingParticipantId = this.removingParticipantIdState.asReadonly();

  async load(sessionId: number): Promise<void> {
    this.assertId(sessionId);
    const version = ++this.requestVersion;
    this.loadingState.set(true);
    this.errorState.set(null);
    try {
      const [sessionResponse, participantsResponse] = await Promise.all([
        firstValueFrom(this.sessionsApi.get(sessionId)),
        firstValueFrom(this.sessionsApi.listParticipants(sessionId)),
      ]);
      if (version !== this.requestVersion) return;
      this.sessionState.set(sessionResponse.session);
      this.currentQuestionState.set(sessionResponse.currentQuestion);
      this.questionResultState.set(sessionResponse.questionResult);
      this.finalResultState.set(sessionResponse.finalResult);
      this.applyParticipants(participantsResponse);
    } catch (error: unknown) {
      if (version !== this.requestVersion) return;
      this.errorState.set(
        error instanceof HttpErrorResponse && error.status === 404 ? 'not-found' : 'load',
      );
    } finally {
      if (version === this.requestVersion) this.loadingState.set(false);
    }
  }

  async refreshParticipants(sessionId: number): Promise<void> {
    if (this.loading() || this.session()?.id !== sessionId) return;
    try {
      const response = await firstValueFrom(this.sessionsApi.listParticipants(sessionId));
      this.applyParticipants(response);
      this.sessionState.update((session) =>
        session
          ? {
              ...session,
              status: response.session.status,
              currentQuestionOrder: response.session.currentQuestionOrder,
              participantCount: response.participantCount,
            }
          : session,
      );
    } catch {
      // A transient polling failure must not blank a usable lobby.
    }
  }

  async removeParticipant(sessionId: number, participantId: number): Promise<void> {
    this.assertId(sessionId);
    this.assertId(participantId);
    if (this.removingParticipantId() !== null) return;
    this.removingParticipantIdState.set(participantId);
    try {
      await firstValueFrom(this.sessionsApi.removeParticipant(sessionId, participantId));
      this.participantsState.update((participants) =>
        participants.filter(({ id }) => id !== participantId),
      );
      this.participantSummaryState.update((summary) =>
        summary
          ? {
              ...summary,
              participants: summary.participants.filter(({ id }) => id !== participantId),
              participantCount: Math.max(0, summary.participantCount - 1),
              connectedParticipantCount: this.participants().filter(
                ({ isConnected }) => isConnected,
              ).length,
            }
          : summary,
      );
      this.sessionState.update((session) =>
        session ? { ...session, participantCount: this.participants().length } : session,
      );
    } finally {
      this.removingParticipantIdState.set(null);
    }
  }

  async startSession(sessionId: number): Promise<void> {
    this.assertId(sessionId);
    if (this.starting()) return;
    this.startingState.set(true);
    try {
      const response = await firstValueFrom(this.sessionsApi.start(sessionId));
      this.sessionState.set(response.session);
      this.currentQuestionState.set(response.currentQuestion);
      this.questionResultState.set(null);
      this.participantSummaryState.update((summary) =>
        summary
          ? {
              ...summary,
              session: {
                ...summary.session,
                status: response.session.status,
                currentQuestionOrder: response.session.currentQuestionOrder,
              },
              participants: summary.participants.map((participant) => ({
                ...participant,
                hasAnsweredCurrentQuestion: false,
              })),
              answeredCurrentQuestionCount: 0,
            }
          : summary,
      );
      this.participantsState.update((participants) =>
        participants.map((participant) => ({
          ...participant,
          hasAnsweredCurrentQuestion: false,
        })),
      );
      this.finalResultState.set(null);
    } finally {
      this.startingState.set(false);
    }
  }

  async closeCurrentQuestion(sessionId: number): Promise<void> {
    this.assertId(sessionId);
    if (this.lifecyclePending() || this.questionResult() !== null) return;
    this.lifecyclePendingState.set(true);
    try {
      const response = await firstValueFrom(this.sessionsApi.closeCurrentQuestion(sessionId));
      this.sessionState.set(response.session);
      this.currentQuestionState.set(response.questionResult.question);
      this.questionResultState.set(response.questionResult);
    } finally {
      this.lifecyclePendingState.set(false);
    }
  }

  async startNextQuestion(sessionId: number): Promise<void> {
    this.assertId(sessionId);
    if (this.lifecyclePending() || this.questionResult() === null) return;
    this.lifecyclePendingState.set(true);
    try {
      const response = await firstValueFrom(this.sessionsApi.startNextQuestion(sessionId));
      this.sessionState.set(response.session);
      this.currentQuestionState.set(response.currentQuestion);
      this.questionResultState.set(null);
      this.participantSummaryState.update((summary) =>
        summary
          ? {
              ...summary,
              session: {
                ...summary.session,
                status: response.session.status,
                currentQuestionOrder: response.session.currentQuestionOrder,
              },
              participants: summary.participants.map((participant) => ({
                ...participant,
                hasAnsweredCurrentQuestion: false,
              })),
              answeredCurrentQuestionCount: 0,
            }
          : summary,
      );
      this.participantsState.update((participants) =>
        participants.map((participant) => ({
          ...participant,
          hasAnsweredCurrentQuestion: false,
        })),
      );
    } finally {
      this.lifecyclePendingState.set(false);
    }
  }

  async finishSession(sessionId: number): Promise<void> {
    this.assertId(sessionId);
    if (this.lifecyclePending() || this.questionResult() === null) return;
    this.lifecyclePendingState.set(true);
    try {
      const response = await firstValueFrom(this.sessionsApi.finish(sessionId));
      this.sessionState.set(response.session);
      this.currentQuestionState.set(null);
      this.questionResultState.set(null);
      this.finalResultState.set(response.finalResult);
    } finally {
      this.lifecyclePendingState.set(false);
    }
  }

  clear(): void {
    ++this.requestVersion;
    this.sessionState.set(null);
    this.participantsState.set([]);
    this.participantSummaryState.set(null);
    this.currentQuestionState.set(null);
    this.questionResultState.set(null);
    this.finalResultState.set(null);
    this.loadingState.set(false);
    this.errorState.set(null);
    this.lifecyclePendingState.set(false);
  }

  private applyParticipants(response: SessionParticipantsResponse): void {
    this.participantsState.set(response.participants);
    this.participantSummaryState.set(response);
  }

  private assertId(id: number): void {
    if (!Number.isSafeInteger(id) || id < 1) throw new RangeError('id must be positive.');
  }
}
