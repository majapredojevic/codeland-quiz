import { TestBed } from '@angular/core/testing';

import { JoinGameResponse } from './player.models';
import { ParticipantSessionStore } from './participant-session.store';

describe('ParticipantSessionStore', () => {
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

  beforeEach(() => {
    sessionStorage.clear();
    localStorage.clear();
    TestBed.configureTestingModule({ providers: [ParticipantSessionStore] });
  });

  it('keeps participant state in memory and sessionStorage, never localStorage', () => {
    const store = TestBed.inject(ParticipantSessionStore);
    store.persist(response);

    expect(store.session()?.participantToken).toBe('participant-token');
    expect(sessionStorage.length).toBe(1);
    expect(localStorage.length).toBe(0);
  });

  it('restores only the matching unexpired game session', () => {
    TestBed.inject(ParticipantSessionStore).persist(response);
    const restoredStore = TestBed.runInInjectionContext(() => new ParticipantSessionStore());

    expect(restoredStore.restore('123456')?.participant.nickname).toBe('Pixel');
    expect(restoredStore.restore('654321')).toBeNull();
  });

  it('clears unusable participant state', () => {
    const store = TestBed.inject(ParticipantSessionStore);
    store.persist(response);
    store.clear();

    expect(store.session()).toBeNull();
    expect(sessionStorage.length).toBe(0);
  });
});
