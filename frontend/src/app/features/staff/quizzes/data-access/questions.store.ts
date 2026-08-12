import { HttpErrorResponse } from '@angular/common/http';
import { Service, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';

import { backendErrorMessage } from './quiz-error.utils';
import { QuestionsApiService } from './questions-api.service';
import {
  CreateQuestionRequest,
  QuestionItem,
  QuestionsListResponse,
  UpdateQuestionRequest,
} from './questions.models';

export type QuestionsListError = 'quiz-not-found' | 'load' | null;
export type QuestionDetailError = 'quiz-not-found' | 'not-found' | 'load' | null;

@Service()
export class QuestionsStore {
  private readonly api = inject(QuestionsApiService);
  private readonly questionsState = signal<QuestionItem[]>([]);
  private readonly questionCountState = signal(0);
  private readonly listLoadingState = signal(false);
  private readonly listErrorState = signal<QuestionsListError>(null);
  private readonly detailState = signal<QuestionItem | null>(null);
  private readonly detailLoadingState = signal(false);
  private readonly detailErrorState = signal<QuestionDetailError>(null);
  private readonly reorderingState = signal(false);
  private listRequestVersion = 0;
  private detailRequestVersion = 0;

  readonly questions = this.questionsState.asReadonly();
  readonly questionCount = this.questionCountState.asReadonly();
  readonly listLoading = this.listLoadingState.asReadonly();
  readonly listError = this.listErrorState.asReadonly();
  readonly detail = this.detailState.asReadonly();
  readonly detailLoading = this.detailLoadingState.asReadonly();
  readonly detailError = this.detailErrorState.asReadonly();
  readonly reordering = this.reorderingState.asReadonly();

  async loadList(quizId: number): Promise<void> {
    this.assertId(quizId, 'quizId');
    const version = ++this.listRequestVersion;
    this.listLoadingState.set(true);
    this.listErrorState.set(null);
    try {
      const response = await firstValueFrom(this.api.list(quizId));
      if (version !== this.listRequestVersion) return;
      this.commitList(response);
    } catch (error: unknown) {
      if (version !== this.listRequestVersion) return;
      this.listErrorState.set(
        error instanceof HttpErrorResponse && error.status === 404 ? 'quiz-not-found' : 'load',
      );
    } finally {
      if (version === this.listRequestVersion) this.listLoadingState.set(false);
    }
  }

  async loadQuestion(quizId: number, questionId: number): Promise<void> {
    this.assertId(quizId, 'quizId');
    this.assertId(questionId, 'questionId');
    const version = ++this.detailRequestVersion;
    this.detailState.set(null);
    this.detailLoadingState.set(true);
    this.detailErrorState.set(null);
    try {
      const response = await firstValueFrom(this.api.get(quizId, questionId));
      if (version === this.detailRequestVersion) this.detailState.set(response.question);
    } catch (error: unknown) {
      if (version !== this.detailRequestVersion) return;
      const backendMessage = backendErrorMessage(error);
      this.detailErrorState.set(
        error instanceof HttpErrorResponse && error.status === 404
          ? backendMessage === 'Quiz was not found.'
            ? 'quiz-not-found'
            : 'not-found'
          : 'load',
      );
    } finally {
      if (version === this.detailRequestVersion) this.detailLoadingState.set(false);
    }
  }

  async create(quizId: number, request: CreateQuestionRequest): Promise<QuestionItem> {
    this.assertId(quizId, 'quizId');
    return (await firstValueFrom(this.api.create(quizId, request))).question;
  }

  async update(
    quizId: number,
    questionId: number,
    request: UpdateQuestionRequest,
  ): Promise<QuestionItem> {
    this.assertId(quizId, 'quizId');
    this.assertId(questionId, 'questionId');
    const question = (await firstValueFrom(this.api.update(quizId, questionId, request))).question;
    this.detailState.set(question);
    return question;
  }

  async delete(quizId: number, questionId: number): Promise<void> {
    this.assertId(quizId, 'quizId');
    this.assertId(questionId, 'questionId');
    await firstValueFrom(this.api.delete(quizId, questionId));
  }

  async reorder(quizId: number, questionIds: number[]): Promise<void> {
    this.assertId(quizId, 'quizId');
    if (this.reorderingState()) return;
    this.assertCompleteOrder(questionIds);

    const previousQuestions = this.questionsState();
    const previousCount = this.questionCountState();
    const byId = new Map(previousQuestions.map((question) => [question.id, question]));
    this.questionsState.set(
      questionIds.map((id, index) => ({ ...byId.get(id)!, questionOrder: index + 1 })),
    );
    this.reorderingState.set(true);
    try {
      this.commitList(await firstValueFrom(this.api.reorder(quizId, { questionIds })));
    } catch (error: unknown) {
      this.questionsState.set(previousQuestions);
      this.questionCountState.set(previousCount);
      await this.loadList(quizId);
      throw error;
    } finally {
      this.reorderingState.set(false);
    }
  }

  clearList(): void {
    ++this.listRequestVersion;
    this.questionsState.set([]);
    this.questionCountState.set(0);
    this.listLoadingState.set(false);
    this.listErrorState.set(null);
    this.reorderingState.set(false);
  }

  clearDetail(): void {
    ++this.detailRequestVersion;
    this.detailState.set(null);
    this.detailLoadingState.set(false);
    this.detailErrorState.set(null);
  }

  private commitList(response: QuestionsListResponse): void {
    this.questionsState.set(response.questions);
    this.questionCountState.set(response.questionCount);
    this.listErrorState.set(null);
  }

  private assertCompleteOrder(questionIds: number[]): void {
    if (
      questionIds.length === 0 ||
      questionIds.some((id) => !Number.isSafeInteger(id) || id < 1) ||
      new Set(questionIds).size !== questionIds.length
    ) {
      throw new RangeError('questionIds must contain unique positive integers.');
    }

    const current = this.questionsState()
      .map(({ id }) => id)
      .sort((left, right) => left - right);
    const requested = [...questionIds].sort((left, right) => left - right);
    if (
      current.length !== requested.length ||
      current.some((id, index) => id !== requested[index])
    ) {
      throw new RangeError('questionIds must contain every loaded question exactly once.');
    }
  }

  private assertId(id: number, field: string): void {
    if (!Number.isSafeInteger(id) || id < 1) throw new RangeError(`${field} must be positive.`);
  }
}
