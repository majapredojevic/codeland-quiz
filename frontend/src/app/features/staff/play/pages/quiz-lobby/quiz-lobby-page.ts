import { DOCUMENT } from '@angular/common';
import { Component, DestroyRef, inject, OnDestroy, OnInit, signal } from '@angular/core';
import { MatDialog } from '@angular/material/dialog';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { QRCodeComponent } from 'angularx-qrcode';
import { firstValueFrom } from 'rxjs';

import {
  ConfirmDialog,
  ConfirmDialogData,
} from '../../../../../shared/feedback/confirm-dialog/confirm-dialog';
import { NotificationService } from '../../../../../shared/feedback/notification.service';
import { ParticipantCard } from '../../components/participant-card/participant-card';
import { LobbyStore } from '../../data-access/lobby.store';
import { PublicSessionQuestionOption, SessionParticipant } from '../../data-access/play.models';
import { QuizSessionsApiService } from '../../data-access/quiz-sessions-api.service';

const PARTICIPANT_REFRESH_MS = 1_000;
const COUNTDOWN_REFRESH_MS = 250;

@Component({
  selector: 'clq-quiz-lobby-page',
  imports: [ParticipantCard, QRCodeComponent, RouterLink],
  providers: [LobbyStore, QuizSessionsApiService],
  templateUrl: './quiz-lobby-page.html',
  styleUrl: './quiz-lobby-page.scss',
})
export class QuizLobbyPage implements OnInit, OnDestroy {
  private readonly route = inject(ActivatedRoute);
  private readonly dialog = inject(MatDialog);
  private readonly notifications = inject(NotificationService);
  private readonly document = inject(DOCUMENT);
  private readonly destroyRef = inject(DestroyRef);
  private refreshTimer: ReturnType<typeof setInterval> | null = null;
  private countdownTimer: ReturnType<typeof setInterval> | null = null;
  private automaticCloseQuestionId: number | null = null;

  protected readonly store = inject(LobbyStore);
  protected readonly remainingSeconds = signal(0);
  protected readonly imageUnavailable = signal(false);
  protected sessionId: number | null = null;
  protected invalidSessionId = false;

  ngOnInit(): void {
    const rawId = this.route.snapshot.paramMap.get('sessionId');
    const sessionId = rawId && /^\d+$/.test(rawId) ? Number(rawId) : NaN;
    if (!Number.isSafeInteger(sessionId) || sessionId < 1) {
      this.invalidSessionId = true;
      return;
    }

    this.sessionId = sessionId;
    void this.loadSession(sessionId);
    this.refreshTimer = setInterval(() => {
      if (this.store.session()?.status !== 'FINISHED') {
        void this.store.refreshParticipants(sessionId);
      }
    }, PARTICIPANT_REFRESH_MS);
    this.destroyRef.onDestroy(() => {
      this.clearRefreshTimer();
      this.clearCountdownTimer();
    });
  }

  ngOnDestroy(): void {
    this.clearRefreshTimer();
    this.clearCountdownTimer();
    this.store.clear();
  }

  protected joinUrl(gamePin: string): string {
    const origin = this.document.location?.origin;
    const path = `/join?pin=${encodeURIComponent(gamePin)}`;
    return origin && origin !== 'null' ? `${origin}${path}` : path;
  }

  protected async removeParticipant(participant: SessionParticipant): Promise<void> {
    if (this.sessionId === null || this.store.session()?.status !== 'WAITING') return;
    const confirmed = await firstValueFrom(
      this.dialog
        .open<ConfirmDialog, ConfirmDialogData, boolean>(ConfirmDialog, {
          data: {
            title: 'Da li želite ukloniti igrača?',
            message: `Igrač „${participant.nickname}“ više neće moći učestvovati u ovoj sesiji.`,
            confirmLabel: 'Ukloni igrača',
            tone: 'destructive',
          },
          width: '30rem',
          maxWidth: 'calc(100vw - 2rem)',
          panelClass: 'clq-dialog-panel',
        })
        .afterClosed(),
    );
    if (!confirmed) return;

    try {
      await this.store.removeParticipant(this.sessionId, participant.id);
      this.notifications.success('Igrač je uklonjen iz lobbyja.');
    } catch {
      this.notifications.error('Igrača nije moguće ukloniti. Pokušajte ponovo.');
    }
  }

  protected async startQuiz(): Promise<void> {
    if (this.sessionId === null || this.store.session()?.status !== 'WAITING') return;
    try {
      await this.store.startSession(this.sessionId);
      this.prepareQuestionPresentation();
      this.notifications.success('Kviz je započet.');
    } catch {
      this.notifications.error(
        this.store.participants().length === 0
          ? 'Za početak kviza potreban je najmanje jedan igrač.'
          : 'Kviz trenutno nije moguće započeti.',
      );
    }
  }

  protected async advance(): Promise<void> {
    if (this.sessionId === null || this.store.lifecyclePending()) return;
    try {
      if (this.store.questionResult() === null) {
        await this.store.closeCurrentQuestion(this.sessionId);
        this.clearCountdownTimer();
        return;
      }

      const session = this.store.session();
      if (session?.currentQuestionOrder === session?.questionCount) {
        await this.store.finishSession(this.sessionId);
        this.clearRefreshTimer();
        return;
      }

      await this.store.startNextQuestion(this.sessionId);
      this.prepareQuestionPresentation();
    } catch {
      this.notifications.error(
        this.store.questionResult() === null
          ? 'Pitanje trenutno nije moguće zatvoriti.'
          : 'Nije moguće nastaviti igru. Pokušajte ponovo.',
      );
    }
  }

  protected async retry(): Promise<void> {
    if (this.sessionId !== null) await this.loadSession(this.sessionId);
  }

  protected optionLabel(index: number): string {
    return String.fromCharCode(65 + index);
  }

  protected avatarInitials(nickname: string): string {
    return Array.from(nickname.trim()).slice(0, 2).join('').toLocaleUpperCase('bs');
  }

  protected sortedOptions(): PublicSessionQuestionOption[] {
    return [...(this.store.currentQuestion()?.options ?? [])].sort(
      (left, right) => left.optionOrder - right.optionOrder,
    );
  }

  protected markImageUnavailable(): void {
    this.imageUnavailable.set(true);
  }

  private async loadSession(sessionId: number): Promise<void> {
    await this.store.load(sessionId);
    const session = this.store.session();
    if (session?.status === 'ACTIVE') {
      this.prepareQuestionPresentation();
    } else if (session?.status === 'FINISHED') {
      this.clearRefreshTimer();
    }
  }

  private prepareQuestionPresentation(): void {
    this.imageUnavailable.set(false);
    this.automaticCloseQuestionId = null;
    this.clearCountdownTimer();
    if (this.store.questionResult() !== null) {
      this.remainingSeconds.set(0);
      return;
    }
    this.updateCountdown();
    this.countdownTimer = setInterval(() => this.updateCountdown(), COUNTDOWN_REFRESH_MS);
  }

  private updateCountdown(): void {
    const deadlineValue = this.store.session()?.currentQuestionDeadline;
    const question = this.store.currentQuestion();
    if (!deadlineValue || !question || this.store.questionResult() !== null) {
      this.remainingSeconds.set(0);
      return;
    }

    const deadline = new Date(deadlineValue).getTime();
    const remaining = Number.isFinite(deadline)
      ? Math.max(0, Math.ceil((deadline - Date.now()) / 1_000))
      : 0;
    this.remainingSeconds.set(remaining);

    if (remaining === 0 && this.automaticCloseQuestionId !== question.id) {
      this.automaticCloseQuestionId = question.id;
      this.clearCountdownTimer();
      void this.advance();
    }
  }

  private clearRefreshTimer(): void {
    if (this.refreshTimer === null) return;
    clearInterval(this.refreshTimer);
    this.refreshTimer = null;
  }

  private clearCountdownTimer(): void {
    if (this.countdownTimer === null) return;
    clearInterval(this.countdownTimer);
    this.countdownTimer = null;
  }
}
