import { TestBed } from '@angular/core/testing';
import { of, Subject } from 'rxjs';

import { CloseQuestionResponse } from './play.models';
import { LobbyStore } from './lobby.store';
import { QuizSessionsApiService } from './quiz-sessions-api.service';

describe('LobbyStore', () => {
  const session = {
    id: 41,
    quizId: 4,
    quiz: { title: 'Petlje', version: 1 },
    host: { id: 1, name: 'Nastavnik' },
    gamePin: '123456',
    status: 'WAITING' as const,
    currentQuestionOrder: null,
    currentQuestionStartedAt: null,
    currentQuestionDeadline: null,
    currentQuestionClosedAt: null,
    joinDeadline: null,
    startedAt: null,
    endedAt: null,
    createdAt: '2026-08-12T10:00:00+00:00',
    questionCount: 8,
    participantCount: 1,
  };
  const participant = {
    id: 9,
    participantType: 'GUEST' as const,
    student: null,
    nickname: 'Lana',
    avatarKey: 'koda-pink',
    totalScore: 0,
    isConnected: true,
    disconnectedAt: null,
    joinedAt: '2026-08-12T10:01:00+00:00',
    hasAnsweredCurrentQuestion: false,
  };
  const question = {
    id: 101,
    questionText: 'Koja naredba ispisuje tekst?',
    questionType: 'SINGLE_CHOICE' as const,
    imagePath: null,
    timeLimitSeconds: 20,
    maxPoints: 100,
    questionOrder: 1,
    options: [
      { id: 1002, optionText: 'print', optionOrder: 2 },
      { id: 1001, optionText: 'echo', optionOrder: 1 },
    ],
  };
  const activeSession = {
    ...session,
    status: 'ACTIVE' as const,
    currentQuestionOrder: 1,
    currentQuestionStartedAt: '2026-08-12T10:02:00+00:00',
    currentQuestionDeadline: '2026-08-12T10:02:20+00:00',
    startedAt: '2026-08-12T10:02:00+00:00',
  };
  const questionResult = {
    question,
    closedAt: '2026-08-12T10:02:20+00:00',
    correctOptionIds: [1001],
    stats: {
      participantCount: 1,
      answerCount: 1,
      correctAnswerCount: 1,
      incorrectAnswerCount: 0,
      unansweredCount: 0,
    },
    participantResults: [],
    leaderboard: [
      {
        rank: 1,
        participantId: 9,
        participantType: 'GUEST' as const,
        nickname: 'Lana',
        avatarKey: 'koda-pink',
        totalScore: 90,
        pointsAwardedThisQuestion: 90,
      },
    ],
  };
  const finalResult = {
    participantCount: 1,
    totalAnswerCount: 1,
    totalCorrectAnswerCount: 1,
    topThree: [
      {
        rank: 1,
        participantId: 9,
        participantType: 'GUEST' as const,
        nickname: 'Lana',
        avatarKey: 'koda-pink',
        totalScore: 90,
        answerCount: 1,
        correctAnswerCount: 1,
      },
    ],
    leaderboard: [],
  };
  const get = vi.fn();
  const listParticipants = vi.fn();
  const removeParticipant = vi.fn();
  const start = vi.fn();
  const closeCurrentQuestion = vi.fn();
  const startNextQuestion = vi.fn();
  const finish = vi.fn();

  beforeEach(() => {
    vi.clearAllMocks();
    get.mockReturnValue(
      of({ session, currentQuestion: null, questionResult: null, finalResult: null }),
    );
    listParticipants.mockReturnValue(
      of({
        session: { id: 41, status: 'WAITING', currentQuestionOrder: null },
        participants: [participant],
        participantCount: 1,
        connectedParticipantCount: 1,
        answeredCurrentQuestionCount: 0,
      }),
    );
    removeParticipant.mockReturnValue(of(undefined));
    start.mockReturnValue(
      of({
        session: activeSession,
        currentQuestion: question,
        questionCount: 8,
        stateChanged: true,
      }),
    );
    closeCurrentQuestion.mockReturnValue(
      of({
        session: { ...activeSession, currentQuestionClosedAt: questionResult.closedAt },
        questionResult,
        stateChanged: true,
      }),
    );
    startNextQuestion.mockReturnValue(
      of({
        session: { ...activeSession, currentQuestionOrder: 2 },
        currentQuestion: { ...question, id: 102, questionOrder: 2 },
        questionCount: 8,
        previousQuestionOrder: 1,
      }),
    );
    finish.mockReturnValue(
      of({
        session: { ...activeSession, status: 'FINISHED', endedAt: '2026-08-12T10:03:00+00:00' },
        finalResult,
        stateChanged: true,
      }),
    );
    TestBed.configureTestingModule({
      providers: [
        LobbyStore,
        {
          provide: QuizSessionsApiService,
          useValue: {
            get,
            listParticipants,
            removeParticipant,
            start,
            closeCurrentQuestion,
            startNextQuestion,
            finish,
          },
        },
      ],
    });
  });

  it('loads the session and canonical participant presence', async () => {
    const store = TestBed.inject(LobbyStore);
    await store.load(41);
    expect(store.session()).toEqual(session);
    expect(store.participants()).toEqual([participant]);
    expect(store.participantSummary()?.connectedParticipantCount).toBe(1);
  });

  it('restores an active closed question from the authoritative session read model', async () => {
    get.mockReturnValue(
      of({ session: activeSession, currentQuestion: question, questionResult, finalResult: null }),
    );
    const store = TestBed.inject(LobbyStore);
    await store.load(41);
    expect(store.currentQuestion()).toEqual(question);
    expect(store.questionResult()?.correctOptionIds).toEqual([1001]);
  });

  it('removes a participant canonically and starts the waiting session', async () => {
    const store = TestBed.inject(LobbyStore);
    await store.load(41);
    await store.removeParticipant(41, 9);
    expect(removeParticipant).toHaveBeenCalledWith(41, 9);
    expect(store.participants()).toEqual([]);
    expect(store.session()?.participantCount).toBe(0);

    await store.startSession(41);
    expect(start).toHaveBeenCalledWith(41);
    expect(store.session()?.status).toBe('ACTIVE');
    expect(store.currentQuestion()).toEqual(question);
  });

  it('commits close, next and finish responses without exposing speculative state', async () => {
    const store = TestBed.inject(LobbyStore);
    await store.load(41);
    await store.startSession(41);

    await store.closeCurrentQuestion(41);
    expect(store.questionResult()).toEqual(questionResult);
    expect(store.currentQuestion()).toEqual(question);

    listParticipants.mockReturnValueOnce(
      of({
        session: { id: 41, status: 'ACTIVE', currentQuestionOrder: 1 },
        participants: [{ ...participant, hasAnsweredCurrentQuestion: true }],
        participantCount: 1,
        connectedParticipantCount: 1,
        answeredCurrentQuestionCount: 1,
      }),
    );
    await store.refreshParticipants(41);
    expect(store.participantSummary()?.answeredCurrentQuestionCount).toBe(1);

    await store.startNextQuestion(41);
    expect(store.currentQuestion()?.questionOrder).toBe(2);
    expect(store.questionResult()).toBeNull();
    expect(store.participantSummary()?.answeredCurrentQuestionCount).toBe(0);
    expect(store.participants()[0]?.hasAnsweredCurrentQuestion).toBe(false);

    await store.closeCurrentQuestion(41);
    await store.finishSession(41);
    expect(store.session()?.status).toBe('FINISHED');
    expect(store.currentQuestion()).toBeNull();
    expect(store.finalResult()).toEqual(finalResult);
  });

  it('prevents duplicate lifecycle requests while the canonical mutation is pending', async () => {
    const pending = new Subject<CloseQuestionResponse>();
    closeCurrentQuestion.mockReturnValue(pending);
    const store = TestBed.inject(LobbyStore);
    await store.load(41);

    const first = store.closeCurrentQuestion(41);
    const duplicate = store.closeCurrentQuestion(41);
    expect(closeCurrentQuestion).toHaveBeenCalledOnce();
    expect(store.lifecyclePending()).toBe(true);

    pending.next({
      session: activeSession,
      questionResult,
      stateChanged: true,
    });
    pending.complete();
    await Promise.all([first, duplicate]);
    expect(store.lifecyclePending()).toBe(false);
  });
});
