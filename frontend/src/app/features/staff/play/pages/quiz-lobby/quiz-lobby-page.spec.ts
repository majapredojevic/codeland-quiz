import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { MatDialog } from '@angular/material/dialog';
import { ActivatedRoute, convertToParamMap, provideRouter } from '@angular/router';
import { of } from 'rxjs';

import { NotificationService } from '../../../../../shared/feedback/notification.service';
import { LobbyStore } from '../../data-access/lobby.store';
import {
  FinalResult,
  PublicSessionQuestion,
  QuestionResult,
  QuizSession,
  SessionParticipantsResponse,
} from '../../data-access/play.models';
import { QuizLobbyPage } from './quiz-lobby-page';

describe('QuizLobbyPage', () => {
  const waitingSession: QuizSession = {
    id: 41,
    quizId: 4,
    quiz: { title: 'Petlje', version: 1 },
    host: { id: 1, name: 'Nastavnik' },
    gamePin: '123456',
    status: 'WAITING',
    currentQuestionOrder: null,
    currentQuestionStartedAt: null,
    currentQuestionDeadline: null,
    currentQuestionClosedAt: null,
    joinDeadline: null,
    startedAt: null,
    endedAt: null,
    createdAt: '2026-08-12T10:00:00+00:00',
    questionCount: 2,
    participantCount: 10,
  };
  const questionOne: PublicSessionQuestion = {
    id: 101,
    questionText: 'Koja naredba ispisuje tekst u PHP-u?',
    questionType: 'SINGLE_CHOICE',
    imagePath: null,
    timeLimitSeconds: 20,
    maxPoints: 100,
    questionOrder: 1,
    options: [
      { id: 1002, optionText: 'print_r', optionOrder: 2 },
      { id: 1001, optionText: 'echo', optionOrder: 1 },
      { id: 1004, optionText: 'write', optionOrder: 4 },
      { id: 1003, optionText: 'input', optionOrder: 3 },
    ],
  };
  const resultOne: QuestionResult = {
    question: questionOne,
    closedAt: '2026-08-12T10:00:20+00:00',
    correctOptionIds: [1001],
    stats: {
      participantCount: 10,
      answerCount: 9,
      correctAnswerCount: 6,
      incorrectAnswerCount: 3,
      unansweredCount: 1,
    },
    participantResults: [],
    leaderboard: [
      {
        rank: 1,
        participantId: 9,
        participantType: 'GUEST',
        nickname: 'Lana',
        avatarKey: 'koda-pink',
        totalScore: 95,
        pointsAwardedThisQuestion: 95,
      },
    ],
  };
  const finalResult: FinalResult = {
    participantCount: 10,
    totalAnswerCount: 18,
    totalCorrectAnswerCount: 12,
    topThree: [
      {
        rank: 3,
        participantId: 11,
        participantType: 'GUEST',
        nickname: 'Ena',
        avatarKey: 'koda-orange',
        totalScore: 140,
        answerCount: 2,
        correctAnswerCount: 1,
      },
      {
        rank: 2,
        participantId: 10,
        participantType: 'GUEST',
        nickname: 'Niko',
        avatarKey: 'koda-blue',
        totalScore: 165,
        answerCount: 2,
        correctAnswerCount: 2,
      },
      {
        rank: 1,
        participantId: 9,
        participantType: 'GUEST',
        nickname: 'Lana',
        avatarKey: 'koda-pink',
        totalScore: 190,
        answerCount: 2,
        correctAnswerCount: 2,
      },
    ],
    leaderboard: [],
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

  const sessionState = signal<QuizSession | null>(waitingSession);
  const participantsState = signal([participant]);
  const participantSummaryState = signal<SessionParticipantsResponse | null>(null);
  const currentQuestionState = signal<PublicSessionQuestion | null>(null);
  const questionResultState = signal<QuestionResult | null>(null);
  const finalResultState = signal<FinalResult | null>(null);
  const loadingState = signal(false);
  const errorState = signal<'not-found' | 'load' | null>(null);
  const startingState = signal(false);
  const lifecyclePendingState = signal(false);
  const load = vi.fn().mockResolvedValue(undefined);
  const refreshParticipants = vi.fn().mockResolvedValue(undefined);
  const removeParticipant = vi.fn().mockResolvedValue(undefined);
  const startSession = vi.fn();
  const closeCurrentQuestion = vi.fn();
  const startNextQuestion = vi.fn();
  const finishSession = vi.fn();
  const clear = vi.fn();
  const open = vi.fn(() => ({ afterClosed: () => of(true) }));
  const success = vi.fn();
  const error = vi.fn();
  const store = {
    session: sessionState,
    participants: participantsState,
    participantSummary: participantSummaryState,
    currentQuestion: currentQuestionState,
    questionResult: questionResultState,
    finalResult: finalResultState,
    loading: loadingState,
    error: errorState,
    starting: startingState,
    lifecyclePending: lifecyclePendingState,
    removingParticipantId: signal<number | null>(null),
    load,
    refreshParticipants,
    removeParticipant,
    startSession,
    closeCurrentQuestion,
    startNextQuestion,
    finishSession,
    clear,
  };

  beforeEach(async () => {
    vi.clearAllMocks();
    sessionState.set({ ...waitingSession });
    participantsState.set([participant]);
    participantSummaryState.set(null);
    currentQuestionState.set(null);
    questionResultState.set(null);
    finalResultState.set(null);
    loadingState.set(false);
    errorState.set(null);
    startingState.set(false);
    lifecyclePendingState.set(false);

    startSession.mockImplementation(async () => {
      sessionState.set(activeSession(1, 20));
      currentQuestionState.set(questionOne);
    });
    closeCurrentQuestion.mockImplementation(async () => {
      sessionState.update((session) =>
        session ? { ...session, currentQuestionClosedAt: new Date().toISOString() } : session,
      );
      questionResultState.set(resultOne);
    });
    startNextQuestion.mockImplementation(async () => {
      sessionState.set(activeSession(2, 20));
      currentQuestionState.set({ ...questionOne, id: 102, questionOrder: 2 });
      questionResultState.set(null);
      participantSummaryState.update((summary) =>
        summary ? { ...summary, answeredCurrentQuestionCount: 0 } : summary,
      );
    });
    finishSession.mockImplementation(async () => {
      sessionState.update((session) =>
        session
          ? {
              ...session,
              status: 'FINISHED',
              endedAt: new Date().toISOString(),
              currentQuestionDeadline: null,
            }
          : session,
      );
      currentQuestionState.set(null);
      questionResultState.set(null);
      finalResultState.set(finalResult);
    });

    await TestBed.configureTestingModule({
      imports: [QuizLobbyPage],
      providers: [
        provideRouter([]),
        {
          provide: ActivatedRoute,
          useValue: { snapshot: { paramMap: convertToParamMap({ sessionId: '41' }) } },
        },
        { provide: MatDialog, useValue: { open } },
        { provide: NotificationService, useValue: { success, error } },
      ],
    })
      .overrideComponent(QuizLobbyPage, {
        set: { providers: [{ provide: LobbyStore, useValue: store }] },
      })
      .compileComponents();
  });

  afterEach(() => vi.useRealTimers());

  function activeSession(questionOrder: number, seconds: number): QuizSession {
    const startedAt = new Date().toISOString();
    return {
      ...waitingSession,
      status: 'ACTIVE',
      currentQuestionOrder: questionOrder,
      currentQuestionStartedAt: startedAt,
      currentQuestionDeadline: new Date(Date.now() + seconds * 1_000).toISOString(),
      startedAt,
    };
  }

  function render() {
    const fixture = TestBed.createComponent(QuizLobbyPage);
    fixture.detectChanges();
    return { fixture, element: fixture.nativeElement as HTMLElement };
  }

  async function renderActive(question = questionOne, seconds = 20) {
    sessionState.set(activeSession(question.questionOrder, seconds));
    currentQuestionState.set(question);
    participantSummaryState.set({
      session: { id: 41, status: 'ACTIVE', currentQuestionOrder: question.questionOrder },
      participants: [participant],
      participantCount: 10,
      connectedParticipantCount: 3,
      answeredCurrentQuestionCount: 7,
    });
    const rendered = render();
    await rendered.fixture.whenStable();
    rendered.fixture.detectChanges();
    return rendered;
  }

  it('preserves the approved waiting lobby, participant removal and start entry point', async () => {
    const { fixture, element } = render();
    await fixture.whenStable();
    expect(load).toHaveBeenCalledWith(41);
    expect(element.querySelector('.game-pin')?.textContent).toContain('123456');
    expect(element.querySelector('qrcode')).not.toBeNull();
    expect(element.querySelector('clq-participant-card')?.textContent).toContain('Lana');

    element.querySelector<HTMLButtonElement>('.remove-player')?.click();
    await fixture.whenStable();
    expect(removeParticipant).toHaveBeenCalledWith(41, 9);
    fixture.destroy();
  });

  it('regresses start into the first canonical snapshot question on the same page', async () => {
    const { fixture, element } = render();
    element.querySelector<HTMLButtonElement>('.lobby-actions button')?.click();
    await fixture.whenStable();
    fixture.detectChanges();

    expect(startSession).toHaveBeenCalledWith(41);
    expect(element.querySelector('.join-panel')).toBeNull();
    expect(element.querySelector('.question-text')?.textContent).toContain(
      questionOne.questionText,
    );
    expect(element.querySelectorAll('.answer-card')).toHaveLength(4);
    expect(element.querySelector('.answer-card.is-correct')).toBeNull();
    expect(element.textContent).not.toContain('Rezultat pitanja');
    expect(element.querySelector('.game-header')?.textContent).toContain('Pitanje 1 od 2');
    expect(element.querySelector('.countdown strong')?.textContent?.trim()).toBe('20');
    fixture.destroy();
  });

  it('sorts canonical options, uses two or four real cards, and omits a null image area', async () => {
    const four = await renderActive();
    expect(
      Array.from(four.element.querySelectorAll('.answer-card strong')).map((node) =>
        node.textContent?.trim(),
      ),
    ).toEqual(['echo', 'print_r', 'input', 'write']);
    expect(four.element.querySelector('.question-image')).toBeNull();
    expect(four.element.querySelector('.image-fallback')).toBeNull();
    four.fixture.destroy();

    const two = await renderActive({
      ...questionOne,
      questionType: 'TRUE_FALSE',
      options: questionOne.options.slice(0, 2),
      imagePath: '/media/questions/example.webp',
    });
    expect(two.element.querySelectorAll('.answer-card')).toHaveLength(2);
    expect(two.element.querySelector('.answer-grid')?.classList).toContain('has-two-options');
    const image = two.element.querySelector<HTMLImageElement>('.question-image');
    expect(image?.src).toContain('/media/questions/example.webp');
    const prompt = two.element.querySelector('.presentation-question-prompt')!;
    const answers = two.element.querySelector('.answer-grid')!;
    const controls = two.element.querySelector('.game-controls')!;
    expect(
      prompt.compareDocumentPosition(image!) & Node.DOCUMENT_POSITION_CONTAINED_BY,
    ).toBeTruthy();
    expect(prompt.compareDocumentPosition(answers) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(
      answers.compareDocumentPosition(controls) & Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy();
    image?.dispatchEvent(new Event('error'));
    two.fixture.detectChanges();
    expect(two.element.querySelector('.question-image')).toBeNull();
    expect(two.element.querySelector('.image-fallback')?.textContent).toContain(
      'Slika nije dostupna.',
    );
    two.fixture.destroy();
  });

  it('uses backend answer totals rather than connected participants', async () => {
    const { fixture, element } = await renderActive();
    expect(element.querySelector('.answer-count')?.textContent?.replace(/\s+/g, ' ').trim()).toBe(
      '7 / 10 odgovorilo',
    );
    fixture.destroy();
  });

  it('derives countdown from the absolute deadline, survives rerender and closes once at zero', async () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-08-12T10:00:00.000Z'));
    closeCurrentQuestion.mockResolvedValue(undefined);
    const { fixture, element } = await renderActive(questionOne, 3);
    expect(element.querySelector('.countdown strong')?.textContent?.trim()).toBe('3');
    fixture.detectChanges();
    expect(element.querySelector('.countdown strong')?.textContent?.trim()).toBe('3');

    await vi.advanceTimersByTimeAsync(3_200);
    fixture.detectChanges();
    expect(element.querySelector('.countdown strong')?.textContent?.trim()).toBe('0');
    expect(closeCurrentQuestion).toHaveBeenCalledOnce();
    await vi.advanceTimersByTimeAsync(2_000);
    expect(closeCurrentQuestion).toHaveBeenCalledOnce();
    fixture.destroy();
  });

  it('closes to reveal, starts the next question, then finishes after the last result', async () => {
    const { fixture, element } = await renderActive();
    element.querySelector<HTMLButtonElement>('.game-controls button')?.click();
    await fixture.whenStable();
    fixture.detectChanges();
    expect(closeCurrentQuestion).toHaveBeenCalledOnce();
    expect(element.textContent).toContain('Tačno');
    expect(element.querySelectorAll('.answer-card.is-correct')).toHaveLength(1);
    expect(element.querySelector('.leaderboard-preview')?.textContent).toContain('Lana');

    element.querySelector<HTMLButtonElement>('.game-controls button')?.click();
    await fixture.whenStable();
    fixture.detectChanges();
    expect(startNextQuestion).toHaveBeenCalledOnce();
    expect(element.querySelector('.game-header')?.textContent).toContain('Pitanje 2 od 2');

    questionResultState.set({ ...resultOne, question: currentQuestionState()! });
    fixture.detectChanges();
    expect(element.querySelector('.game-controls button')?.textContent).toContain('Završi kviz');
    element.querySelector<HTMLButtonElement>('.game-controls button')?.click();
    await fixture.whenStable();
    fixture.detectChanges();
    expect(finishSession).toHaveBeenCalledOnce();
    expect(element.querySelector('.final-screen')?.textContent).toContain('Kviz završen!');
    element.querySelector<HTMLButtonElement>('.skip-reveal')?.click();
    fixture.detectChanges();
    expect(element.querySelector('.podium')?.textContent).toContain('Lana');
    expect(element.querySelector<HTMLAnchorElement>('.final-results')?.getAttribute('href')).toBe(
      '/app/results/sessions/41',
    );
    fixture.destroy();
  });

  it('reveals the final podium in the dramatic order 3, then 2, then 1', async () => {
    vi.useFakeTimers();
    sessionState.set({
      ...waitingSession,
      status: 'FINISHED',
      endedAt: new Date().toISOString(),
    });
    finalResultState.set(finalResult);
    const { fixture, element } = render();
    await Promise.resolve();
    fixture.detectChanges();

    expect(element.querySelectorAll('.podium-place')).toHaveLength(0);

    await vi.advanceTimersByTimeAsync(350);
    fixture.detectChanges();
    expect(
      Array.from(element.querySelectorAll('.podium-place')).map((node) =>
        node.getAttribute('data-rank'),
      ),
    ).toEqual(['3']);

    await vi.advanceTimersByTimeAsync(900);
    fixture.detectChanges();
    expect(
      Array.from(element.querySelectorAll('.podium-place')).map((node) =>
        node.getAttribute('data-rank'),
      ),
    ).toEqual(['3', '2']);

    await vi.advanceTimersByTimeAsync(900);
    fixture.detectChanges();
    expect(
      Array.from(element.querySelectorAll('.podium-place')).map((node) =>
        node.getAttribute('data-rank'),
      ),
    ).toEqual(['3', '2', '1']);
    expect(element.querySelector('.podium-confetti')).not.toBeNull();
    fixture.destroy();
  });
});
