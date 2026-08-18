import { HttpClient } from '@angular/common/http';
import { Service, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { GamePreviewResponse, JoinGameRequest, JoinGameResponse } from './player.models';

@Service()
export class PlayerApiService {
  private readonly http = inject(HttpClient);

  preview(gamePin: string): Observable<GamePreviewResponse> {
    return this.http.get<GamePreviewResponse>(`/api/game/session/${encodeURIComponent(gamePin)}`);
  }

  join(request: JoinGameRequest): Observable<JoinGameResponse> {
    return this.http.post<JoinGameResponse>('/api/game/join', request);
  }
}
