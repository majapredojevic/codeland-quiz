import { HttpClient } from '@angular/common/http';
import { Service, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { QuestionImageResponse } from './question-images.models';

@Service()
export class QuestionImagesApiService {
  private readonly http = inject(HttpClient);

  upload(quizId: number, image: File): Observable<QuestionImageResponse> {
    const body = new FormData();
    body.append('image', image);
    return this.http.post<QuestionImageResponse>(`/api/quizzes/${quizId}/question-images`, body);
  }

  cleanup(quizId: number, fileName: string): Observable<void> {
    return this.http.delete<void>(
      `/api/quizzes/${quizId}/question-images/${encodeURIComponent(fileName)}`,
    );
  }
}
