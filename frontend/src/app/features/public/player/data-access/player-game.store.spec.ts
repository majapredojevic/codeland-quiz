import { TestBed } from '@angular/core/testing';

import { ParticipantSessionStore } from './participant-session.store';
import { PLAYER_WEBSOCKET_FACTORY, PlayerGameStore } from './player-game.store';
import { JoinGameResponse, PlayerQuestion, QuestionType } from './player.models';

class FakeSocket {
  readonly sent: string[] = [];
  readyState = 1;
  onmessage: ((event: MessageEvent) => void) | null = null;
  onclose: (() => void) | null = null;
  onerror: (() => void) | null = null;

  constructor(readonly url: string) {}

  send(message: string): void {
    this.sent.push(message);
  }

  close(): void {
    this.readyState = 3;
    this.onclose?.();
  }

  receive(type: string, payload: object): void {
    this.onmessage?.({ data: JSON.stringify({ type, payload }) } as MessageEvent);
  }
}

describe('PlayerGameStore', () => {
  const response: JoinGameResponse = {
    participant: {
      id: 7,
      sessionId: 9,
      participantType: 'GUEST',
      studentId: null,
      nickname: 'Pixel',
      avatarKey: 'koda-purple',
      totalScore: 0,
      isConnected: false,
      joinedAt: '2026-08-13T10:00:00+00:00',
    },
    session: {
      id: 9,
      quiz: { title: 'PHP osnove', version: 1 },
      gamePin: '123456',
      status: 'WAITING',
    },
    participantToken: 'participant-token',
    participantTokenExpiresAt: '2099-08-13T10:00:00+00:00',
  };
  const sockets: FakeSocket[] = [];
  let store: PlayerGameStore;

  beforeEach(() => {
    sessionStorage.clear();
    sockets.length = 0;
    TestBed.configureTestingModule({
      providers: [
        ParticipantSessionStore,
        PlayerGameStore,
        {
          provide: PLAYER_WEBSOCKET_FACTORY,
          useValue: (url: string) => {
            const socket = new FakeSocket(url);
            sockets.push(socket);
            return socket as unknown as WebSocket;
          },
        },
      ],
    });
    store = TestBed.inject(PlayerGameStore);
  });

  afterEach(() => {
    store.ngOnDestroy();
    sessionStorage.clear();
    vi.useRealTimers();
  });

  function authenticate(status: 'WAITING' | 'ACTIVE' | 'FINISHED' = 'WAITING'): FakeSocket {
    store.begin(response);
    const socket = sockets[0];
    socket.receive('AUTHENTICATION_REQUIRED', { timeoutSeconds: 10 });
    socket.receive('PARTICIPANT_AUTHENTICATED', {
      participant: { ...response.participant, isConnected: true },
      session: { ...response.session, status, currentQuestionOrder: status === 'ACTIVE' ? 1 : null },
    });
    return socket;
  }

  function question(questionType: QuestionType): PlayerQuestion {
    return {
      id: 21,
      questionText: 'Koji odgovori pripadaju PHP-u?',
      questionType,
      imagePath: '/media/question-images/1/example.png',
      timeLimitSeconds: 60,
      maxPoints: 1_000,
      questionOrder: 1,
      questionCount: 4,
      options: [
        { id: 101, optionText: 'echo', optionOrder: 1 },
        { id: 102, optionText: 'foreach', optionOrder: 2 },
        { id: 103, optionText: 'console.log', optionOrder: 3 },
        { id: 104, optionText: 'SELECT', optionOrder: 4 },
      ],
    };
  }

  function startQuestion(socket: FakeSocket, type: QuestionType, answered = false): void {
    socket.receive('GAME_STARTED', {
      session: { id: 9, status: 'ACTIVE', startedAt: new Date().toISOString(), questionCount: 4 },
    });
    socket.receive('QUESTION_STARTED', {
      question: question(type),
      timing: {
        startedAt: new Date().toISOString(),
        answerDeadline: new Date(Date.now() + 60_000).toISOString(),
      },
      participantAnswer: {
        answered,
        selectedOptionIds: answered ? [101] : [],
      },
    });
  }

  function answerMessages(socket: FakeSocket): Array<{ type: string; payload: object }> {
    return socket.sent
      .map((message) => JSON.parse(message) as { type: string; payload: object })
      .filter(({ type }) => type === 'ANSWER_SUBMIT');
  }

  it('authenticates /ws/game with only the participant token and reaches the lobby', () => {
    const socket = authenticate();
    expect(socket.url).toMatch(/^ws:\/\/localhost:\d+\/ws\/game$/);
    expect(JSON.parse(socket.sent[0])).toEqual({
      type: 'PARTICIPANT_AUTHENTICATE',
      payload: { participantToken: 'participant-token' },
    });
    expect(store.phase()).toBe('WAITING');
    expect(store.participant()?.nickname).toBe('Pixel');
  });

  it('acknowledges server heartbeats without changing UI state or leaking after destroy', () => {
    const socket = authenticate();
    socket.receive('HEARTBEAT', { acknowledge: true });

    expect(JSON.parse(socket.sent.at(-1) ?? '')).toEqual({
      type: 'HEARTBEAT_ACK',
      payload: {},
    });
    expect(store.phase()).toBe('WAITING');

    const sentCount = socket.sent.length;
    store.ngOnDestroy();
    socket.receive('HEARTBEAT', { acknowledge: true });
    expect(socket.sent).toHaveLength(sentCount);
  });

  it('submits single choice once, locks it, and reveals only canonical result data', () => {
    const socket = authenticate('ACTIVE');
    startQuestion(socket, 'SINGLE_CHOICE');

    store.submitSingleOption(101);
    store.submitSingleOption(102);
    expect(answerMessages(socket)).toEqual([
      { type: 'ANSWER_SUBMIT', payload: { selectedOptionIds: [101] } },
    ]);
    expect(store.submissionPending()).toBe(true);
    expect(store.correctOptionIds()).toEqual([]);

    socket.receive('ANSWER_ACCEPTED', {
      questionOrder: 1,
      responseTimeMs: 500,
      answeredAt: new Date().toISOString(),
    });
    expect(store.phase()).toBe('ANSWER_SUBMITTED');

    socket.receive('QUESTION_CLOSED', {
      questionOrder: 1,
      closedAt: new Date().toISOString(),
      correctOptionIds: [101],
      stats: {},
    });
    socket.receive('ANSWER_RESULT', {
      questionOrder: 1,
      answered: true,
      selectedOptionIds: [101],
      isCorrect: true,
      responseTimeMs: 500,
      pointsAwarded: 950,
      totalScore: 950,
      answeredAt: new Date().toISOString(),
    });

    expect(store.phase()).toBe('QUESTION_RESULT');
    expect(store.correctOptionIds()).toEqual([101]);
    expect(store.answerResult()?.pointsAwarded).toBe(950);
  });

  it('requires two or three selections and an explicit confirmation for multiple choice', () => {
    const socket = authenticate('ACTIVE');
    startQuestion(socket, 'MULTIPLE_CHOICE');

    store.toggleMultipleOption(101);
    store.confirmMultipleAnswer();
    expect(answerMessages(socket)).toHaveLength(0);

    store.toggleMultipleOption(102);
    store.confirmMultipleAnswer();
    store.confirmMultipleAnswer();
    expect(answerMessages(socket)).toEqual([
      { type: 'ANSWER_SUBMIT', payload: { selectedOptionIds: [101, 102] } },
    ]);
  });

  it('restores canonical already-answered state after refresh without joining again', () => {
    TestBed.inject(ParticipantSessionStore).persist(response);
    expect(store.resume('123456')).toBe(true);
    const socket = sockets[0];
    socket.receive('AUTHENTICATION_REQUIRED', { timeoutSeconds: 10 });
    socket.receive('PARTICIPANT_AUTHENTICATED', {
      participant: { ...response.participant, isConnected: true },
      session: { ...response.session, status: 'ACTIVE', currentQuestionOrder: 1 },
    });
    startQuestion(socket, 'SINGLE_CHOICE', true);

    expect(store.phase()).toBe('ANSWER_SUBMITTED');
    expect(store.selectedOptionIds()).toEqual([101]);
    store.submitSingleOption(102);
    expect(answerMessages(socket)).toHaveLength(0);
  });

  it('reconstructs the result when reconnecting after the question has closed', () => {
    TestBed.inject(ParticipantSessionStore).persist(response);
    expect(store.resume('123456')).toBe(true);
    const socket = sockets[0];
    socket.receive('AUTHENTICATION_REQUIRED', { timeoutSeconds: 10 });
    socket.receive('PARTICIPANT_AUTHENTICATED', {
      participant: { ...response.participant, isConnected: true },
      session: { ...response.session, status: 'ACTIVE', currentQuestionOrder: 1 },
    });
    socket.receive('GAME_STARTED', {
      session: { id: 9, status: 'ACTIVE', startedAt: new Date().toISOString(), questionCount: 4 },
    });
    socket.receive('QUESTION_CLOSED', {
      questionOrder: 1,
      closedAt: new Date().toISOString(),
      correctOptionIds: [101, 102],
      question: question('MULTIPLE_CHOICE'),
      stats: {},
    });
    socket.receive('ANSWER_RESULT', {
      questionOrder: 1,
      answered: true,
      selectedOptionIds: [101, 103],
      isCorrect: false,
      responseTimeMs: 5_000,
      pointsAwarded: 0,
      totalScore: 500,
      answeredAt: new Date().toISOString(),
    });

    expect(store.question()?.id).toBe(21);
    expect(store.correctOptionIds()).toEqual([101, 102]);
    expect(store.selectedOptionIds()).toEqual([101, 103]);
    expect(store.phase()).toBe('QUESTION_RESULT');
  });

  it('clears unusable state and stops reconnecting when the teacher removes the player', () => {
    const socket = authenticate();
    socket.receive('PARTICIPANT_REMOVED', { message: 'removed' });

    expect(store.phase()).toBe('REMOVED');
    expect(sessionStorage.length).toBe(0);
  });

  it('clears unusable state when another connection replaces this player', () => {
    const socket = authenticate();
    socket.receive('CONNECTION_REPLACED', { message: 'replaced' });

    expect(store.phase()).toBe('REPLACED');
    expect(sessionStorage.length).toBe(0);
  });

  it('stops reconnecting and clears the expired participant session', () => {
    const socket = authenticate('ACTIVE');
    socket.receive('ERROR', {
      code: 'PARTICIPANT_SESSION_EXPIRED',
      message: 'expired',
    });

    expect(store.phase()).toBe('TERMINAL_ERROR');
    expect(store.terminalMessage()).toBe(
      'Ova sesija je istekla. Pridruži se igri ponovo.',
    );
    expect(sessionStorage.length).toBe(0);
  });

  it('derives the countdown from the server deadline and locks locally at zero', () => {
    vi.useFakeTimers();
    const socket = authenticate('ACTIVE');
    startQuestion(socket, 'SINGLE_CHOICE');

    expect(store.remainingSeconds()).toBe(60);
    vi.advanceTimersByTime(60_250);

    expect(store.remainingSeconds()).toBe(0);
    expect(store.canInteract()).toBe(false);
    expect(store.phase()).toBe('QUESTION_OPEN');
    expect(answerMessages(socket)).toHaveLength(0);
  });

  it('moves from game finished to the personalized canonical final result', () => {
    const socket = authenticate('ACTIVE');
    startQuestion(socket, 'TRUE_FALSE');
    socket.receive('GAME_FINISHED', {
      session: { id: 9, status: 'FINISHED' },
      topThree: [],
    });
    expect(store.phase()).toBe('FINISHED');
    expect(store.finalResult()).toBeNull();

    socket.receive('FINAL_RESULT', {
      rank: 4,
      participantId: 7,
      participantType: 'GUEST',
      nickname: 'Pixel',
      avatarKey: 'koda-purple',
      totalScore: 2_800,
      answerCount: 4,
      correctAnswerCount: 3,
    });
    expect(store.finalResult()?.rank).toBe(4);
    expect(store.finalResult()?.correctAnswerCount).toBe(3);
  });
});
