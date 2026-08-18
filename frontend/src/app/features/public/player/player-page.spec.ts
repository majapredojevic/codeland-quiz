import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, convertToParamMap, provideRouter } from '@angular/router';

import { PLAYER_WEBSOCKET_FACTORY } from './data-access/player-game.store';
import { JoinGameResponse, PlayerQuestion } from './data-access/player.models';
import { PlayerPage } from './player-page';

class FakeSocket {
  readonly sent: string[] = [];
  readyState = 1;
  onmessage: ((event: MessageEvent) => void) | null = null;
  onclose: (() => void) | null = null;
  onerror: (() => void) | null = null;

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

describe('PlayerPage', () => {
  const avatarKeys = [
    'koda-blue',
    'koda-green',
    'koda-orange',
    'koda-pink',
    'koda-purple',
    'koda-red',
    'koda-turquoise',
    'koda-yellow',
  ];
  const sockets: FakeSocket[] = [];
  let http: HttpTestingController;

  beforeEach(async () => {
    sessionStorage.clear();
    sockets.length = 0;
    await TestBed.configureTestingModule({
      imports: [PlayerPage],
      providers: [
        provideRouter([]),
        provideHttpClient(),
        provideHttpClientTesting(),
        {
          provide: ActivatedRoute,
          useValue: { snapshot: { paramMap: convertToParamMap({ gamePin: '123456' }) } },
        },
        {
          provide: PLAYER_WEBSOCKET_FACTORY,
          useValue: () => {
            const socket = new FakeSocket();
            sockets.push(socket);
            return socket as unknown as WebSocket;
          },
        },
      ],
    }).compileComponents();
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    http.verify();
    sessionStorage.clear();
  });

  async function renderPreview() {
    const fixture = TestBed.createComponent(PlayerPage);
    fixture.detectChanges();
    http.expectOne('/api/game/session/123456').flush({
      session: {
        quiz: { title: 'PHP osnove', version: 1 },
        status: 'WAITING',
        participantCount: 2,
        canJoin: true,
        joinDeadline: null,
      },
      avatarKeys,
    });
    await fixture.whenStable();
    fixture.detectChanges();
    return { fixture, element: fixture.nativeElement as HTMLElement };
  }

  function clickButton(element: HTMLElement, text: string): void {
    const button = Array.from(element.querySelectorAll('button')).find((candidate) =>
      candidate.textContent?.includes(text),
    );
    if (!button) throw new Error(`Button ${text} was not rendered.`);
    button.click();
  }

  function setInput(element: HTMLElement, selector: string, value: string): void {
    const input = element.querySelector<HTMLInputElement>(selector);
    if (!input) throw new Error(`Input ${selector} was not rendered.`);
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function joinResponse(type: 'REGISTERED' | 'GUEST'): JoinGameResponse {
    return {
      participant: {
        id: 7,
        sessionId: 9,
        participantType: type,
        studentId: type === 'REGISTERED' ? 4 : null,
        nickname: 'Pixel',
        avatarKey: 'koda-blue',
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
  }

  function playerQuestion(
    questionType: PlayerQuestion['questionType'],
    questionOrder: number,
    imagePath: string | null,
  ): PlayerQuestion {
    const options =
      questionType === 'TRUE_FALSE'
        ? [
            { id: 201, optionText: 'Tačno', optionOrder: 1 },
            { id: 202, optionText: 'Netačno', optionOrder: 2 },
          ]
        : [
            { id: 101, optionText: 'echo', optionOrder: 1 },
            { id: 102, optionText: 'foreach', optionOrder: 2 },
            { id: 103, optionText: 'console.log', optionOrder: 3 },
            { id: 104, optionText: 'SELECT', optionOrder: 4 },
          ];

    return {
      id: 20 + questionOrder,
      questionText:
        questionType === 'TRUE_FALSE'
          ? 'PHP se izvršava na serveru.'
          : 'Koji odgovori pripadaju PHP-u?',
      questionType,
      imagePath,
      timeLimitSeconds: 60,
      maxPoints: 1_000,
      questionOrder,
      questionCount: 4,
      options,
    };
  }

  it('renders exactly the eight canonical Koda choices and requires a deliberate selection', async () => {
    const { fixture, element } = await renderPreview();
    clickButton(element, 'Nastavi kao gost');
    fixture.detectChanges();
    setInput(element, '#player-nickname', 'Pixel');
    clickButton(element, 'Izaberi Kodu');
    fixture.detectChanges();

    expect(element.querySelectorAll('.avatar-choice')).toHaveLength(8);
    expect(
      Array.from(element.querySelectorAll<HTMLImageElement>('.avatar-choice img')).map((image) =>
        image.getAttribute('src'),
      ),
    ).toEqual(avatarKeys.map((_, index) => `/avatars/Koda${index + 1}.png`));
    expect(element.querySelector<HTMLButtonElement>('.join-final-action')?.disabled).toBe(true);
    expect(element.textContent).not.toContain('koda-blue');
    fixture.destroy();
  });

  it('sends username for a registered student and enters the public game state', async () => {
    const { fixture, element } = await renderPreview();
    clickButton(element, 'Imam korisničko ime');
    fixture.detectChanges();
    setInput(element, '#player-username', 'ANA.ANIC');
    setInput(element, '#player-nickname', 'Pixel');
    clickButton(element, 'Izaberi Kodu');
    fixture.detectChanges();
    element.querySelectorAll<HTMLButtonElement>('.avatar-choice')[0].click();
    fixture.detectChanges();
    clickButton(element, 'Uđi u igru');

    const request = http.expectOne('/api/game/join');
    expect(request.request.body).toEqual({
      participantType: 'REGISTERED',
      gamePin: '123456',
      username: 'ana.anic',
      nickname: 'Pixel',
      avatarKey: 'koda-blue',
    });
    request.flush(joinResponse('REGISTERED'));
    await fixture.whenStable();
    expect(sessionStorage.length).toBe(1);
    fixture.destroy();
  });

  it('removes a previously entered username from the guest join request', async () => {
    const { fixture, element } = await renderPreview();
    clickButton(element, 'Imam korisničko ime');
    fixture.detectChanges();
    setInput(element, '#player-username', 'ana.anic');
    setInput(element, '#player-nickname', 'Pixel');
    clickButton(element, 'Nazad');
    fixture.detectChanges();
    clickButton(element, 'Nastavi kao gost');
    fixture.detectChanges();
    clickButton(element, 'Izaberi Kodu');
    fixture.detectChanges();
    element.querySelectorAll<HTMLButtonElement>('.avatar-choice')[0].click();
    fixture.detectChanges();
    clickButton(element, 'Uđi u igru');

    const request = http.expectOne('/api/game/join');
    expect(request.request.body).toEqual({
      participantType: 'GUEST',
      gamePin: '123456',
      nickname: 'Pixel',
      avatarKey: 'koda-blue',
    });
    expect(request.request.body).not.toHaveProperty('username');
    request.flush(joinResponse('GUEST'));
    await fixture.whenStable();
    fixture.destroy();
  });

  it('renders the complete question, canonical result, and a clean next two-option question', () => {
    const storedResponse = joinResponse('GUEST');
    sessionStorage.setItem(
      'codeland-quiz.participant-session',
      JSON.stringify({
        version: 1,
        gamePin: '123456',
        ...storedResponse,
      }),
    );
    const fixture = TestBed.createComponent(PlayerPage);
    fixture.detectChanges();
    const socket = sockets[0];
    socket.receive('AUTHENTICATION_REQUIRED', { timeoutSeconds: 10 });
    socket.receive('PARTICIPANT_AUTHENTICATED', {
      participant: { ...storedResponse.participant, isConnected: true },
      session: { ...storedResponse.session, status: 'ACTIVE', currentQuestionOrder: 1 },
    });
    socket.receive('GAME_STARTED', {
      session: { id: 9, status: 'ACTIVE', startedAt: new Date().toISOString(), questionCount: 4 },
    });
    socket.receive('QUESTION_STARTED', {
      question: playerQuestion('SINGLE_CHOICE', 1, '/media/question-images/example.png'),
      timing: {
        startedAt: new Date().toISOString(),
        answerDeadline: new Date(Date.now() + 60_000).toISOString(),
      },
      participantAnswer: { answered: false, selectedOptionIds: [] },
    });
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;

    expect(element.textContent).toContain('Pitanje 1 / 4');
    expect(element.textContent).toContain('Koji odgovori pripadaju PHP-u?');
    expect(element.querySelector('.player-question-image')?.getAttribute('src')).toBe(
      '/media/question-images/example.png',
    );
    expect(element.querySelectorAll('.player-answer')).toHaveLength(4);
    expect(element.textContent).toContain('A');
    expect(element.textContent).toContain('SELECT');
    const prompt = element.querySelector('.question-prompt')!;
    const image = element.querySelector('.player-question-image')!;
    const answers = element.querySelector('.player-answer-grid')!;
    const footer = element.querySelector('.answer-footer')!;
    expect(
      prompt.compareDocumentPosition(image) & Node.DOCUMENT_POSITION_CONTAINED_BY,
    ).toBeTruthy();
    expect(prompt.compareDocumentPosition(answers) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(answers.compareDocumentPosition(footer) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();

    element.querySelector<HTMLButtonElement>('.player-answer')?.click();
    fixture.detectChanges();
    expect(
      socket.sent.filter((message) => JSON.parse(message).type === 'ANSWER_SUBMIT'),
    ).toHaveLength(1);
    socket.receive('ANSWER_ACCEPTED', {
      questionOrder: 1,
      responseTimeMs: 500,
      answeredAt: new Date().toISOString(),
    });
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
    fixture.detectChanges();
    expect(element.textContent).toContain('Tačno!');
    expect(element.textContent).toContain('+950 bodova');
    expect(element.textContent).toContain('Tačan odgovor');

    socket.receive('QUESTION_STARTED', {
      question: playerQuestion('TRUE_FALSE', 2, null),
      timing: {
        startedAt: new Date().toISOString(),
        answerDeadline: new Date(Date.now() + 60_000).toISOString(),
      },
      participantAnswer: { answered: false, selectedOptionIds: [] },
    });
    fixture.detectChanges();
    expect(element.textContent).toContain('Pitanje 2 / 4');
    expect(element.querySelectorAll('.player-answer')).toHaveLength(2);
    expect(element.querySelector('.player-question-image')).toBeNull();
    expect(element.textContent).not.toContain('+950 bodova');
    expect(element.querySelector<HTMLButtonElement>('.player-answer')?.disabled).toBe(false);
    fixture.destroy();
  });
});
