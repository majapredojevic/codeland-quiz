import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';

import { PlayerApiService } from './player-api.service';

describe('PlayerApiService', () => {
  let api: PlayerApiService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [PlayerApiService, provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(PlayerApiService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('loads the canonical public preview by six-digit PIN', () => {
    api.preview('001234').subscribe();
    const request = http.expectOne('/api/game/session/001234');
    expect(request.request.method).toBe('GET');
    request.flush({ session: {}, avatarKeys: [] });
  });

  it('sends a registered join with username', () => {
    const payload = {
      participantType: 'REGISTERED' as const,
      gamePin: '123456',
      username: 'ana.anic',
      nickname: 'Pixel',
      avatarKey: 'koda-purple',
    };
    api.join(payload).subscribe();
    const request = http.expectOne('/api/game/join');
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual(payload);
    request.flush({});
  });

  it('never adds a stale username to a guest join', () => {
    const payload = {
      participantType: 'GUEST' as const,
      gamePin: '123456',
      nickname: 'Nova',
      avatarKey: 'koda-turquoise',
    };
    api.join(payload).subscribe();
    const request = http.expectOne('/api/game/join');
    expect(request.request.body).toEqual(payload);
    expect(request.request.body).not.toHaveProperty('username');
    request.flush({});
  });
});
