import { DOCUMENT } from '@angular/common';
import { Service, inject, signal } from '@angular/core';

import { JoinGameResponse, StoredParticipantSession } from './player.models';

const STORAGE_KEY = 'codeland-quiz.participant-session';

@Service()
export class ParticipantSessionStore {
  private readonly document = inject(DOCUMENT);
  private readonly state = signal<StoredParticipantSession | null>(null);

  readonly session = this.state.asReadonly();

  persist(response: JoinGameResponse): StoredParticipantSession {
    const stored: StoredParticipantSession = {
      version: 1,
      gamePin: response.session.gamePin,
      participant: response.participant,
      session: response.session,
      participantToken: response.participantToken,
      participantTokenExpiresAt: response.participantTokenExpiresAt,
    };

    this.state.set(stored);
    this.storage()?.setItem(STORAGE_KEY, JSON.stringify(stored));
    return stored;
  }

  restore(gamePin: string): StoredParticipantSession | null {
    const memory = this.state();
    if (memory?.gamePin === gamePin && this.isUsable(memory)) return memory;

    const serialized = this.storage()?.getItem(STORAGE_KEY);
    if (!serialized) return null;

    try {
      const value: unknown = JSON.parse(serialized);
      if (!this.isStoredSession(value) || value.gamePin !== gamePin || !this.isUsable(value)) {
        this.clear();
        return null;
      }

      this.state.set(value);
      return value;
    } catch {
      this.clear();
      return null;
    }
  }

  clear(): void {
    this.state.set(null);
    this.storage()?.removeItem(STORAGE_KEY);
  }

  private storage(): Storage | null {
    return this.document.defaultView?.sessionStorage ?? null;
  }

  private isUsable(value: StoredParticipantSession): boolean {
    const expiresAt = new Date(value.participantTokenExpiresAt).getTime();
    return Number.isFinite(expiresAt) && expiresAt > Date.now();
  }

  private isStoredSession(value: unknown): value is StoredParticipantSession {
    if (!this.isRecord(value) || value['version'] !== 1) return false;
    const participant = value['participant'];
    const session = value['session'];

    return (
      typeof value['gamePin'] === 'string' &&
      /^\d{6}$/.test(value['gamePin']) &&
      typeof value['participantToken'] === 'string' &&
      value['participantToken'].length > 0 &&
      typeof value['participantTokenExpiresAt'] === 'string' &&
      this.isRecord(participant) &&
      typeof participant['id'] === 'number' &&
      typeof participant['nickname'] === 'string' &&
      typeof participant['avatarKey'] === 'string' &&
      this.isRecord(session) &&
      typeof session['id'] === 'number' &&
      session['gamePin'] === value['gamePin']
    );
  }

  private isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
  }
}
