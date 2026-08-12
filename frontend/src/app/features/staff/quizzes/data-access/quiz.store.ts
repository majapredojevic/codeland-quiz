import { HttpErrorResponse } from '@angular/common/http';
import { Service, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';

import { QuizzesApiService } from './quizzes-api.service';
import { CreateQuizRequest, QuizItem, UpdateQuizRequest } from './quizzes.models';

@Service()
export class QuizStore {
  private readonly quizzesApi = inject(QuizzesApiService);
  private readonly detailState = signal<QuizItem | null>(null);
  private readonly loadingState = signal(false);
  private readonly errorState = signal<'not-found' | 'load' | null>(null);
  private detailRequestVersion = 0;
  private refreshRequestVersion = 0;

  readonly detail = this.detailState.asReadonly();
  readonly loading = this.loadingState.asReadonly();
  readonly error = this.errorState.asReadonly();

  async load(id: number): Promise<void> {
    this.assertId(id);
    const version = ++this.detailRequestVersion;
    this.detailState.set(null);
    this.loadingState.set(true);
    this.errorState.set(null);
    try {
      const response = await firstValueFrom(this.quizzesApi.get(id));
      if (version === this.detailRequestVersion) this.detailState.set(response.quiz);
    } catch (error: unknown) {
      if (version === this.detailRequestVersion) {
        this.errorState.set(
          error instanceof HttpErrorResponse && error.status === 404 ? 'not-found' : 'load',
        );
      }
    } finally {
      if (version === this.detailRequestVersion) this.loadingState.set(false);
    }
  }

  async create(request: CreateQuizRequest): Promise<QuizItem> {
    return (await firstValueFrom(this.quizzesApi.create(request))).quiz;
  }

  async refresh(id: number): Promise<boolean> {
    this.assertId(id);
    const current = this.detailState();
    if (!current || current.id !== id) return false;

    const refreshVersion = ++this.refreshRequestVersion;
    const detailVersion = this.detailRequestVersion;
    try {
      const response = await firstValueFrom(this.quizzesApi.get(id));
      if (
        refreshVersion !== this.refreshRequestVersion ||
        detailVersion !== this.detailRequestVersion ||
        this.detailState() !== current
      ) {
        return false;
      }
      this.detailState.set(response.quiz);
      return true;
    } catch {
      return false;
    }
  }

  async update(id: number, request: UpdateQuizRequest): Promise<QuizItem> {
    this.assertId(id);
    const quiz = (await firstValueFrom(this.quizzesApi.update(id, request))).quiz;
    this.detailState.set(quiz);
    return quiz;
  }

  async activate(id: number): Promise<QuizItem> {
    this.assertId(id);
    const quiz = (await firstValueFrom(this.quizzesApi.activate(id))).quiz;
    this.detailState.set(quiz);
    return quiz;
  }

  async deactivate(id: number): Promise<QuizItem> {
    this.assertId(id);
    const quiz = (await firstValueFrom(this.quizzesApi.deactivate(id))).quiz;
    this.detailState.set(quiz);
    return quiz;
  }

  async delete(id: number): Promise<void> {
    this.assertId(id);
    await firstValueFrom(this.quizzesApi.delete(id));
  }

  clear(): void {
    ++this.detailRequestVersion;
    ++this.refreshRequestVersion;
    this.detailState.set(null);
    this.loadingState.set(false);
    this.errorState.set(null);
  }

  private assertId(id: number): void {
    if (!Number.isSafeInteger(id) || id < 1) throw new RangeError('id must be positive.');
  }
}
