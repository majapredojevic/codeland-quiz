import {
  HttpTestingController,
  provideHttpClientTesting,
  TestRequest,
} from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { Router } from '@angular/router';

import { App } from './app';
import { appConfig } from './app.config';
import { PLAYER_WEBSOCKET_FACTORY } from './features/public/player/data-access/player-game.store';

class FakeSocket {
  readonly readyState = 1;
  onmessage: ((event: MessageEvent) => void) | null = null;
  onclose: (() => void) | null = null;
  onerror: (() => void) | null = null;

  send(): void {}

  close(): void {
    this.onclose?.();
  }
}

describe('application auth bootstrap scope', () => {
  let httpTesting: HttpTestingController;
  let router: Router;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        ...appConfig.providers,
        provideHttpClientTesting(),
        {
          provide: PLAYER_WEBSOCKET_FACTORY,
          useValue: () => new FakeSocket() as unknown as WebSocket,
        },
      ],
    });
    httpTesting = TestBed.inject(HttpTestingController);
    router = TestBed.inject(Router);
  });

  afterEach(() => {
    httpTesting.verify();
    sessionStorage.clear();
  });

  async function takeRequest(url: string): Promise<TestRequest> {
    let request: TestRequest | undefined;
    await vi.waitFor(() => {
      const matches = httpTesting.match(url);
      expect(matches).toHaveLength(1);
      request = matches[0];
    });
    return request!;
  }

  it('opens public Join without bootstrapping staff authentication', async () => {
    await expect(router.navigateByUrl('/join')).resolves.toBe(true);

    httpTesting.expectNone('/api/auth/me');
    httpTesting.expectNone('/api/auth/refresh');
  });

  it('opens the player join flow without bootstrapping staff authentication', async () => {
    const fixture = TestBed.createComponent(App);
    fixture.detectChanges();
    const navigation = router.navigateByUrl('/join/123456');
    const request = await takeRequest('/api/game/session/123456');
    request.flush({
      session: {
        quiz: { title: 'PHP osnove', version: 1 },
        status: 'WAITING',
        participantCount: 2,
        canJoin: true,
        joinDeadline: null,
      },
      avatarKeys: ['koda-blue'],
    });

    await expect(navigation).resolves.toBe(true);
    httpTesting.expectNone('/api/auth/me');
    httpTesting.expectNone('/api/auth/refresh');
  });

  it('resumes a stored participant session without staff authentication', async () => {
    sessionStorage.setItem(
      'codeland-quiz.participant-session',
      JSON.stringify({
        version: 1,
        gamePin: '123456',
        participant: {
          id: 7,
          sessionId: 9,
          participantType: 'GUEST',
          nickname: 'Pixel',
          avatarKey: 'koda-blue',
          totalScore: 0,
          isConnected: false,
          joinedAt: '2026-08-18T11:46:00Z',
        },
        session: {
          id: 9,
          quiz: { title: 'PHP osnove', version: 1 },
          gamePin: '123456',
          status: 'ACTIVE',
        },
        participantToken: 'participant-token',
        participantTokenExpiresAt: '2099-08-18T11:46:00Z',
      }),
    );

    const fixture = TestBed.createComponent(App);
    fixture.detectChanges();
    await expect(router.navigateByUrl('/join/123456')).resolves.toBe(true);

    httpTesting.expectNone('/api/game/session/123456');
    httpTesting.expectNone('/api/auth/me');
    httpTesting.expectNone('/api/auth/refresh');
  });

  it('restores a staff session before direct /app navigation', async () => {
    const navigation = router.navigateByUrl('/app/dashboard');
    const request = await takeRequest('/api/auth/me');
    request.flush({
      user: {
        id: 7,
        name: 'Ana Anić',
        email: 'ana@example.com',
        role: 'TEACHER',
        mustChangePassword: false,
      },
    });

    await expect(navigation).resolves.toBe(true);
    expect(router.url).toBe('/app/dashboard');
  });
});
