import { DOCUMENT } from '@angular/common';
import {
  InjectionToken,
  OnDestroy,
  Service,
  computed,
  inject,
  signal,
} from '@angular/core';

import { ParticipantSessionStore } from './participant-session.store';
import {
  JoinGameResponse,
  PlayerAnswerResult,
  PlayerFinalResult,
  PlayerParticipant,
  PlayerPhase,
  PlayerQuestion,
  PlayerSession,
  StoredParticipantSession,
  WebSocketEnvelope,
} from './player.models';

export const PLAYER_WEBSOCKET_FACTORY = new InjectionToken<(url: string) => WebSocket>(
  'PLAYER_WEBSOCKET_FACTORY',
  { factory: () => (url) => new WebSocket(url) },
);

const COUNTDOWN_REFRESH_MS = 250;
const RECONNECT_DELAYS_MS = [800, 1_500, 3_000, 5_000, 8_000, 12_000] as const;
const SOCKET_OPEN = 1;
const SOCKET_CLOSED = 3;

interface AuthenticatedPayload {
  participant: PlayerParticipant;
  session: PlayerSession;
}

interface GameStartedPayload {
  session: {
    id: number;
    status: 'ACTIVE';
    startedAt: string;
    questionCount: number;
  };
}

interface QuestionStartedPayload {
  question: PlayerQuestion | null;
  timing: {
    startedAt: string | null;
    answerDeadline: string | null;
  };
  participantAnswer?: {
    answered: boolean;
    selectedOptionIds: number[];
  };
}

interface QuestionClosedPayload {
  questionOrder: number;
  closedAt: string;
  correctOptionIds: number[];
  question?: PlayerQuestion;
}

interface AnswerAcceptedPayload {
  questionOrder: number;
  responseTimeMs: number;
  answeredAt: string;
}

interface ErrorPayload {
  code: string;
  message: string;
}

@Service()
export class PlayerGameStore implements OnDestroy {
  private readonly document = inject(DOCUMENT);
  private readonly socketFactory = inject(PLAYER_WEBSOCKET_FACTORY);
  private readonly participantSessions = inject(ParticipantSessionStore);

  private readonly phaseState = signal<PlayerPhase>('JOIN');
  private readonly participantState = signal<PlayerParticipant | null>(null);
  private readonly sessionState = signal<PlayerSession | null>(null);
  private readonly questionState = signal<PlayerQuestion | null>(null);
  private readonly selectedOptionIdsState = signal<number[]>([]);
  private readonly correctOptionIdsState = signal<number[]>([]);
  private readonly answerResultState = signal<PlayerAnswerResult | null>(null);
  private readonly finalResultState = signal<PlayerFinalResult | null>(null);
  private readonly answerDeadlineState = signal<string | null>(null);
  private readonly remainingSecondsState = signal(0);
  private readonly submissionPendingState = signal(false);
  private readonly answerErrorState = signal<string | null>(null);
  private readonly terminalMessageState = signal<string | null>(null);
  private readonly reconnectAttemptState = signal(0);

  readonly phase = this.phaseState.asReadonly();
  readonly participant = this.participantState.asReadonly();
  readonly session = this.sessionState.asReadonly();
  readonly question = this.questionState.asReadonly();
  readonly selectedOptionIds = this.selectedOptionIdsState.asReadonly();
  readonly correctOptionIds = this.correctOptionIdsState.asReadonly();
  readonly answerResult = this.answerResultState.asReadonly();
  readonly finalResult = this.finalResultState.asReadonly();
  readonly remainingSeconds = this.remainingSecondsState.asReadonly();
  readonly submissionPending = this.submissionPendingState.asReadonly();
  readonly answerError = this.answerErrorState.asReadonly();
  readonly terminalMessage = this.terminalMessageState.asReadonly();
  readonly reconnectAttempt = this.reconnectAttemptState.asReadonly();
  readonly sortedOptions = computed(() =>
    [...(this.question()?.options ?? [])].sort(
      (left, right) => left.optionOrder - right.optionOrder,
    ),
  );
  readonly isMultipleChoice = computed(
    () => this.question()?.questionType === 'MULTIPLE_CHOICE',
  );
  readonly hasValidMultipleSelection = computed(() => {
    const count = this.selectedOptionIds().length;
    return count === 2 || count === 3;
  });
  readonly canInteract = computed(
    () =>
      this.phase() === 'QUESTION_OPEN' &&
      !this.submissionPending() &&
      this.remainingSeconds() > 0,
  );

  private socket: WebSocket | null = null;
  private participantToken: string | null = null;
  private reconnectTimer: ReturnType<typeof setTimeout> | null = null;
  private countdownTimer: ReturnType<typeof setInterval> | null = null;
  private shouldReconnect = false;

  resume(gamePin: string): boolean {
    const stored = this.participantSessions.restore(gamePin);
    if (stored === null) return false;

    this.applyStoredSession(stored);
    this.phaseState.set('RECONNECTING');
    this.connect();
    return true;
  }

  begin(response: JoinGameResponse): void {
    const stored = this.participantSessions.persist(response);
    this.applyStoredSession(stored);
    this.phaseState.set('RECONNECTING');
    this.connect();
  }

  toggleMultipleOption(optionId: number): void {
    if (!this.canInteract() || !this.isMultipleChoice()) return;

    this.answerErrorState.set(null);
    this.selectedOptionIdsState.update((selected) =>
      selected.includes(optionId)
        ? selected.filter((selectedId) => selectedId !== optionId)
        : [...selected, optionId],
    );
  }

  submitSingleOption(optionId: number): void {
    if (!this.canInteract() || this.isMultipleChoice()) return;
    this.selectedOptionIdsState.set([optionId]);
    this.submitAnswer([optionId]);
  }

  confirmMultipleAnswer(): void {
    if (!this.canInteract() || !this.isMultipleChoice() || !this.hasValidMultipleSelection()) {
      return;
    }

    this.submitAnswer(this.selectedOptionIds());
  }

  retryConnection(): void {
    if (this.participantToken === null || this.phase() === 'REMOVED' || this.phase() === 'REPLACED') {
      return;
    }

    this.clearReconnectTimer();
    this.reconnectAttemptState.set(0);
    this.terminalMessageState.set(null);
    this.phaseState.set('RECONNECTING');
    this.connect();
  }

  canRetryConnection(): boolean {
    return this.participantToken !== null && this.phase() === 'TERMINAL_ERROR';
  }

  clearParticipantSession(): void {
    this.shouldReconnect = false;
    this.closeSocket();
    this.clearReconnectTimer();
    this.clearCountdownTimer();
    this.participantSessions.clear();
    this.participantToken = null;
    this.phaseState.set('JOIN');
    this.participantState.set(null);
    this.sessionState.set(null);
    this.questionState.set(null);
    this.selectedOptionIdsState.set([]);
    this.correctOptionIdsState.set([]);
    this.answerResultState.set(null);
    this.finalResultState.set(null);
    this.answerDeadlineState.set(null);
    this.remainingSecondsState.set(0);
    this.submissionPendingState.set(false);
    this.answerErrorState.set(null);
    this.terminalMessageState.set(null);
  }

  ngOnDestroy(): void {
    this.shouldReconnect = false;
    this.closeSocket();
    this.clearReconnectTimer();
    this.clearCountdownTimer();
  }

  private applyStoredSession(stored: StoredParticipantSession): void {
    this.participantToken = stored.participantToken;
    this.participantState.set(stored.participant);
    this.sessionState.set(stored.session);
    this.shouldReconnect = true;
    this.terminalMessageState.set(null);
  }

  private connect(): void {
    if (!this.shouldReconnect || this.participantToken === null || this.socket !== null) return;

    try {
      const socket = this.socketFactory(this.webSocketUrl());
      this.socket = socket;

      socket.onmessage = (event) => this.handleMessage(event.data);
      socket.onclose = () => this.handleClose(socket);
      socket.onerror = () => {
        if (this.phase() !== 'RECONNECTING') this.phaseState.set('RECONNECTING');
      };
    } catch {
      this.scheduleReconnect();
    }
  }

  private handleMessage(data: unknown): void {
    const message = this.parseMessage(data);
    if (message === null) return;

    switch (message.type) {
      case 'HEARTBEAT':
        this.acknowledgeHeartbeat();
        break;
      case 'AUTHENTICATION_REQUIRED':
        this.authenticateSocket();
        break;
      case 'PARTICIPANT_AUTHENTICATED':
        this.applyAuthenticated(message.payload as unknown as AuthenticatedPayload);
        break;
      case 'GAME_STARTED':
        this.applyGameStarted(message.payload as unknown as GameStartedPayload);
        break;
      case 'QUESTION_STARTED':
        this.applyQuestionStarted(message.payload as unknown as QuestionStartedPayload);
        break;
      case 'ANSWER_ACCEPTED':
        this.applyAnswerAccepted(message.payload as unknown as AnswerAcceptedPayload);
        break;
      case 'QUESTION_CLOSED':
        this.applyQuestionClosed(message.payload as unknown as QuestionClosedPayload);
        break;
      case 'ANSWER_RESULT':
        this.applyAnswerResult(message.payload as unknown as PlayerAnswerResult);
        break;
      case 'GAME_FINISHED':
        this.applyGameFinished();
        break;
      case 'FINAL_RESULT':
        this.applyFinalResult(message.payload as unknown as PlayerFinalResult);
        break;
      case 'PARTICIPANT_REMOVED':
        this.applyRemoved();
        break;
      case 'CONNECTION_REPLACED':
        this.applyReplaced();
        break;
      case 'ERROR':
        this.applyError(message.payload as unknown as ErrorPayload);
        break;
    }
  }

  private authenticateSocket(): void {
    if (this.socket?.readyState !== SOCKET_OPEN || this.participantToken === null) return;
    this.socket.send(
      JSON.stringify({
        type: 'PARTICIPANT_AUTHENTICATE',
        payload: { participantToken: this.participantToken },
      }),
    );
  }

  private acknowledgeHeartbeat(): void {
    if (this.socket?.readyState !== SOCKET_OPEN) return;
    this.socket.send(JSON.stringify({ type: 'HEARTBEAT_ACK', payload: {} }));
  }

  private applyAuthenticated(payload: AuthenticatedPayload): void {
    if (!payload.participant || !payload.session) return;
    this.reconnectAttemptState.set(0);
    this.participantState.set(payload.participant);
    this.sessionState.update((session) => ({ ...session, ...payload.session }));

    if (payload.session.status === 'WAITING') {
      this.resetQuestionState();
      this.phaseState.set('WAITING');
    } else if (payload.session.status === 'FINISHED') {
      this.phaseState.set('FINISHED');
    }
  }

  private applyGameStarted(payload: GameStartedPayload): void {
    this.sessionState.update((session) =>
      session
        ? {
            ...session,
            status: 'ACTIVE',
            questionCount: payload.session.questionCount,
          }
        : session,
    );
    this.phaseState.set('BETWEEN_QUESTIONS');
  }

  private applyQuestionStarted(payload: QuestionStartedPayload): void {
    if (payload.question === null || payload.timing.answerDeadline === null) {
      this.failTerminal('Ova igra više nije dostupna.', false);
      return;
    }

    const participantAnswer = payload.participantAnswer;
    this.questionState.set(payload.question);
    this.answerDeadlineState.set(payload.timing.answerDeadline);
    this.selectedOptionIdsState.set(participantAnswer?.selectedOptionIds ?? []);
    this.correctOptionIdsState.set([]);
    this.answerResultState.set(null);
    this.answerErrorState.set(null);
    this.submissionPendingState.set(false);
    this.sessionState.update((session) =>
      session
        ? {
            ...session,
            status: 'ACTIVE',
            currentQuestionOrder: payload.question?.questionOrder ?? null,
            questionCount: payload.question?.questionCount ?? session.questionCount,
          }
        : session,
    );
    this.phaseState.set(participantAnswer?.answered ? 'ANSWER_SUBMITTED' : 'QUESTION_OPEN');
    this.startCountdown();
  }

  private applyAnswerAccepted(payload: AnswerAcceptedPayload): void {
    if (payload.questionOrder !== this.question()?.questionOrder) return;
    this.submissionPendingState.set(false);
    this.answerErrorState.set(null);
    this.phaseState.set('ANSWER_SUBMITTED');
  }

  private applyQuestionClosed(payload: QuestionClosedPayload): void {
    if (this.question() === null && payload.question) {
      this.questionState.set(payload.question);
      this.sessionState.update((session) =>
        session
          ? {
              ...session,
              currentQuestionOrder: payload.question?.questionOrder ?? null,
              questionCount: payload.question?.questionCount ?? session.questionCount,
            }
          : session,
      );
    }
    if (payload.questionOrder !== this.question()?.questionOrder) return;
    this.clearCountdownTimer();
    this.remainingSecondsState.set(0);
    this.submissionPendingState.set(false);
    this.correctOptionIdsState.set(payload.correctOptionIds);
    this.phaseState.set('BETWEEN_QUESTIONS');
  }

  private applyAnswerResult(payload: PlayerAnswerResult): void {
    if (payload.questionOrder !== this.question()?.questionOrder) return;
    this.answerResultState.set(payload);
    this.selectedOptionIdsState.set(payload.selectedOptionIds);
    this.participantState.update((participant) =>
      participant ? { ...participant, totalScore: payload.totalScore } : participant,
    );
    this.phaseState.set('QUESTION_RESULT');
  }

  private applyGameFinished(): void {
    this.clearCountdownTimer();
    this.remainingSecondsState.set(0);
    this.sessionState.update((session) =>
      session ? { ...session, status: 'FINISHED' } : session,
    );
    this.phaseState.set('FINISHED');
  }

  private applyFinalResult(payload: PlayerFinalResult): void {
    this.finalResultState.set(payload);
    this.participantState.update((participant) =>
      participant ? { ...participant, totalScore: payload.totalScore } : participant,
    );
    this.phaseState.set('FINISHED');
  }

  private applyRemoved(): void {
    this.shouldReconnect = false;
    this.participantToken = null;
    this.participantSessions.clear();
    this.clearReconnectTimer();
    this.clearCountdownTimer();
    this.phaseState.set('REMOVED');
  }

  private applyReplaced(): void {
    this.shouldReconnect = false;
    this.participantToken = null;
    this.participantSessions.clear();
    this.clearReconnectTimer();
    this.clearCountdownTimer();
    this.phaseState.set('REPLACED');
  }

  private applyError(payload: ErrorPayload): void {
    switch (payload.code) {
      case 'ANSWER_ALREADY_SUBMITTED':
        this.resolveSubmissionThroughReconnect();
        return;
      case 'ANSWER_DEADLINE_EXPIRED':
      case 'ANSWER_QUESTION_CLOSED':
        this.submissionPendingState.set(false);
        this.remainingSecondsState.set(0);
        this.phaseState.set('BETWEEN_QUESTIONS');
        this.answerErrorState.set('Vrijeme je isteklo. Čekamo rezultat…');
        return;
      case 'INVALID_SELECTED_OPTIONS':
      case 'INVALID_ANSWER_MESSAGE':
        this.submissionPendingState.set(false);
        this.answerErrorState.set('Izaberi 2 ili 3 odgovora pa pokušaj ponovo.');
        return;
      case 'PARTICIPANT_AUTHENTICATION_FAILED':
      case 'PARTICIPANT_CONNECTION_REJECTED':
      case 'AUTHENTICATION_TIMEOUT':
        this.failTerminal('Ova igra više nije dostupna.', true);
        return;
      case 'PARTICIPANT_SESSION_EXPIRED':
        this.failTerminal('Ova sesija je istekla. Pridruži se igri ponovo.', true);
        return;
      case 'INTERNAL_ERROR':
        if (this.submissionPending()) {
          this.resolveSubmissionThroughReconnect();
          return;
        }
        break;
    }

    this.answerErrorState.set('Odgovor trenutno nije moguće poslati. Pokušaj ponovo.');
    this.submissionPendingState.set(false);
  }

  private submitAnswer(selectedOptionIds: number[]): void {
    if (this.socket?.readyState !== SOCKET_OPEN) {
      this.resolveSubmissionThroughReconnect();
      return;
    }

    this.submissionPendingState.set(true);
    this.answerErrorState.set(null);
    this.socket.send(
      JSON.stringify({
        type: 'ANSWER_SUBMIT',
        payload: { selectedOptionIds },
      }),
    );
  }

  private resolveSubmissionThroughReconnect(): void {
    this.submissionPendingState.set(false);
    this.answerErrorState.set('Provjeravamo da li je odgovor zabilježen…');
    this.phaseState.set('RECONNECTING');
    this.closeSocket();
    this.scheduleReconnect(true);
  }

  private startCountdown(): void {
    this.clearCountdownTimer();
    this.updateCountdown();
    this.countdownTimer = setInterval(() => this.updateCountdown(), COUNTDOWN_REFRESH_MS);
  }

  private updateCountdown(): void {
    const deadline = new Date(this.answerDeadlineState() ?? '').getTime();
    const remaining = Number.isFinite(deadline)
      ? Math.max(0, Math.ceil((deadline - Date.now()) / 1_000))
      : 0;
    this.remainingSecondsState.set(remaining);

    if (remaining === 0) this.clearCountdownTimer();
  }

  private handleClose(socket: WebSocket): void {
    if (this.socket !== socket) return;
    this.socket = null;
    if (!this.shouldReconnect) return;
    this.phaseState.set('RECONNECTING');
    this.scheduleReconnect();
  }

  private scheduleReconnect(immediate = false): void {
    if (!this.shouldReconnect || this.reconnectTimer !== null) return;
    const attempt = this.reconnectAttempt();
    if (attempt >= RECONNECT_DELAYS_MS.length) {
      this.phaseState.set('TERMINAL_ERROR');
      this.terminalMessageState.set('Veza je prekinuta. Pokušaj se ponovo povezati.');
      return;
    }

    const delay = immediate ? 0 : RECONNECT_DELAYS_MS[attempt];
    this.reconnectAttemptState.set(attempt + 1);
    this.reconnectTimer = setTimeout(() => {
      this.reconnectTimer = null;
      this.connect();
    }, delay);
  }

  private failTerminal(message: string, clearSession: boolean): void {
    this.shouldReconnect = false;
    this.clearReconnectTimer();
    this.clearCountdownTimer();
    if (clearSession) {
      this.participantToken = null;
      this.participantSessions.clear();
    }
    this.terminalMessageState.set(message);
    this.phaseState.set('TERMINAL_ERROR');
  }

  private resetQuestionState(): void {
    this.clearCountdownTimer();
    this.questionState.set(null);
    this.selectedOptionIdsState.set([]);
    this.correctOptionIdsState.set([]);
    this.answerResultState.set(null);
    this.answerDeadlineState.set(null);
    this.remainingSecondsState.set(0);
    this.submissionPendingState.set(false);
    this.answerErrorState.set(null);
  }

  private webSocketUrl(): string {
    const location = this.document.location;
    const protocol = location.protocol === 'https:' ? 'wss:' : 'ws:';
    return `${protocol}//${location.host}/ws/game`;
  }

  private parseMessage(data: unknown): WebSocketEnvelope | null {
    if (typeof data !== 'string') return null;
    try {
      const value: unknown = JSON.parse(data);
      if (!this.isRecord(value) || typeof value['type'] !== 'string') return null;
      const payload = value['payload'];
      if (!this.isRecord(payload)) return null;
      return { type: value['type'], payload };
    } catch {
      return null;
    }
  }

  private isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
  }

  private closeSocket(): void {
    const socket = this.socket;
    this.socket = null;
    if (socket === null || socket.readyState === SOCKET_CLOSED) return;
    socket.close();
  }

  private clearReconnectTimer(): void {
    if (this.reconnectTimer === null) return;
    clearTimeout(this.reconnectTimer);
    this.reconnectTimer = null;
  }

  private clearCountdownTimer(): void {
    if (this.countdownTimer === null) return;
    clearInterval(this.countdownTimer);
    this.countdownTimer = null;
  }
}
