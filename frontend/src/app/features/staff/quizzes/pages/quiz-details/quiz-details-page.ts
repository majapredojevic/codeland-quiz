import { HttpErrorResponse } from '@angular/common/http';
import { Component, computed, DestroyRef, inject, OnDestroy, OnInit, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import {
  CdkDrag,
  CdkDragDrop,
  CdkDragHandle,
  CdkDragPlaceholder,
  CdkDropList,
  moveItemInArray,
} from '@angular/cdk/drag-drop';
import { FormField, form, validate } from '@angular/forms/signals';
import { MatDialog } from '@angular/material/dialog';
import { MatMenuModule } from '@angular/material/menu';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { firstValueFrom } from 'rxjs';

import {
  ConfirmDialog,
  ConfirmDialogData,
} from '../../../../../shared/feedback/confirm-dialog/confirm-dialog';
import { NotificationService } from '../../../../../shared/feedback/notification.service';
import { ActiveStatusBadge } from '../../../../../shared/ui/active-status-badge/active-status-badge';
import { EntityAuditMeta } from '../../../../../shared/ui/entity-audit-meta/entity-audit-meta';
import {
  backendErrorMessage,
  isMissingTopicError,
  isQuestionContentLockError,
  QUESTION_CONTENT_LOCK_MESSAGE,
} from '../../data-access/quiz-error.utils';
import { QuizStore } from '../../data-access/quiz.store';
import { CreateQuizRequest, UpdateQuizRequest } from '../../data-access/quizzes.models';
import {
  QuestionItem,
  QUESTION_TYPE_BADGES,
  QuestionType,
} from '../../data-access/questions.models';
import { QuestionsStore } from '../../data-access/questions.store';
import { TopicReferenceStore } from '../../data-access/topic-reference.store';

interface QuizFormModel {
  title: string;
  version: number;
  topicSelection: string;
  description: string;
}

@Component({
  selector: 'clq-quiz-details-page',
  imports: [
    ActiveStatusBadge,
    CdkDrag,
    CdkDragHandle,
    CdkDragPlaceholder,
    CdkDropList,
    FormField,
    MatMenuModule,
    RouterLink,
    EntityAuditMeta,
  ],
  templateUrl: './quiz-details-page.html',
})
export class QuizDetailsPage implements OnInit, OnDestroy {
  protected readonly quizStore = inject(QuizStore);
  protected readonly topics = inject(TopicReferenceStore);
  protected readonly questionsStore = inject(QuestionsStore);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly dialog = inject(MatDialog);
  private readonly notifications = inject(NotificationService);
  private readonly destroyRef = inject(DestroyRef);
  private quizId: number | null = null;

  protected readonly invalidId = signal(false);
  protected readonly activeTab = signal<'basic' | 'questions'>('basic');
  protected readonly formModel = signal<QuizFormModel>({
    title: '',
    version: 1,
    topicSelection: 'none',
    description: '',
  });
  protected readonly quizForm = form(this.formModel, (quiz) => {
    validate(quiz.title, ({ value }) => {
      const title = value().trim();
      if (!title) return { kind: 'required', message: 'Unesite naziv kviza.' };
      if (Array.from(title).length > 180) {
        return { kind: 'maxLength', message: 'Naziv kviza može sadržati najviše 180 znakova.' };
      }
      return undefined;
    });
    validate(quiz.version, ({ value }) =>
      !Number.isInteger(value()) || value() < 1
        ? { kind: 'integer', message: 'Verzija mora biti cijeli broj veći ili jednak 1.' }
        : undefined,
    );
    validate(quiz.description, ({ value }) =>
      Array.from(value().trim()).length > 5000
        ? { kind: 'maxLength', message: 'Opis može sadržati najviše 5000 znakova.' }
        : undefined,
    );
  });
  protected readonly submitted = signal(false);
  protected readonly saving = signal(false);
  protected readonly changingStatus = signal(false);
  protected readonly deleting = signal(false);
  protected readonly unavailableQuestionImages = signal<ReadonlySet<string>>(new Set());
  protected readonly compoundConflict = signal(false);
  protected readonly saveError = signal<string | null>(null);
  protected readonly statusError = signal<string | null>(null);
  protected readonly deleteError = signal<string | null>(null);
  protected readonly titleError = computed(() => this.fieldError('title'));
  protected readonly versionError = computed(() => this.fieldError('version'));
  protected readonly descriptionError = computed(() => this.fieldError('description'));
  protected readonly normalized = computed<CreateQuizRequest>(() => ({
    title: this.formModel().title.trim(),
    version: this.formModel().version,
    description: this.formModel().description.trim() || null,
    topicId: this.selectionToTopicId(this.formModel().topicSelection),
  }));
  protected readonly isDirty = computed(() => {
    const quiz = this.quizStore.detail();
    const value = this.normalized();
    return (
      quiz !== null &&
      (value.title !== quiz.title ||
        value.version !== quiz.version ||
        value.description !== quiz.description ||
        value.topicId !== (quiz.topic?.id ?? null))
    );
  });

  async ngOnInit(): Promise<void> {
    const routeId = this.route.snapshot.paramMap.get('id');
    if (routeId === null || !/^[1-9]\d*$/.test(routeId)) {
      this.invalidId.set(true);
      return;
    }
    const id = Number(routeId);
    if (!Number.isSafeInteger(id)) {
      this.invalidId.set(true);
      return;
    }
    this.quizId = id;
    this.route.queryParamMap.pipe(takeUntilDestroyed(this.destroyRef)).subscribe((params) => {
      const tab = params.get('tab') === 'questions' ? 'questions' : 'basic';
      this.activeTab.set(tab);
      if (tab === 'questions' && this.quizId !== null) {
        void this.questionsStore.loadList(this.quizId);
      }
    });
    await Promise.all([this.quizStore.load(id), this.topics.loadAll()]);
    this.restoreForm();
  }

  ngOnDestroy(): void {
    this.quizStore.clear();
    this.questionsStore.clearList();
  }

  protected clearSaveErrors(): void {
    this.compoundConflict.set(false);
    this.saveError.set(null);
  }

  protected restoreForm(): void {
    const quiz = this.quizStore.detail();
    if (!quiz) return;
    this.quizForm().reset({
      title: quiz.title,
      version: quiz.version,
      topicSelection: quiz.topic ? String(quiz.topic.id) : 'none',
      description: quiz.description ?? '',
    });
    this.submitted.set(false);
    this.compoundConflict.set(false);
    this.saveError.set(null);
  }

  protected async save(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    this.submitted.set(true);
    this.quizForm().markAsTouched();
    this.saveError.set(null);
    const quiz = this.quizStore.detail();
    if (
      !quiz ||
      this.quizId === null ||
      !this.quizForm().valid() ||
      !this.isDirty() ||
      this.saving()
    )
      return;

    const value = this.normalized();
    const changes: Partial<CreateQuizRequest> = {};
    if (value.title !== quiz.title) changes.title = value.title;
    if (value.version !== quiz.version) changes.version = value.version;
    if (value.description !== quiz.description) changes.description = value.description;
    if (value.topicId !== (quiz.topic?.id ?? null)) changes.topicId = value.topicId;

    this.saving.set(true);
    try {
      await this.quizStore.update(this.quizId, changes as UpdateQuizRequest);
      this.restoreForm();
      this.notifications.success('Izmjene su sačuvane.');
    } catch (error: unknown) {
      if (error instanceof HttpErrorResponse && error.status === 409) {
        this.compoundConflict.set(true);
      } else if (isMissingTopicError(error)) {
        this.saveError.set('Odabrana tema više ne postoji. Odaberite drugu temu.');
        await this.topics.loadAll(true);
      } else {
        this.saveError.set('Nije moguće sačuvati izmjene.');
      }
    } finally {
      this.saving.set(false);
    }
  }

  protected async activate(): Promise<void> {
    const quiz = this.quizStore.detail();
    if (!quiz || quiz.questionCount === 0 || this.quizId === null || this.changingStatus()) return;
    this.changingStatus.set(true);
    this.statusError.set(null);
    try {
      await this.quizStore.activate(this.quizId);
      this.notifications.success('Kviz je aktiviran.');
    } catch (error: unknown) {
      const message = backendErrorMessage(error) ?? '';
      const safeMessage =
        error instanceof HttpErrorResponse && error.status === 409
          ? message.includes('open session')
            ? 'Status kviza se ne može mijenjati dok postoji otvorena sesija.'
            : message.includes('at least one question')
              ? 'Kviz mora imati najmanje jedno pitanje prije aktivacije.'
              : 'Kviz se ne može aktivirati dok sva pitanja nisu ispravno podešena.'
          : 'Nije moguće aktivirati kviz.';
      this.statusError.set(safeMessage);
      this.notifications.error(safeMessage);
    } finally {
      this.changingStatus.set(false);
    }
  }

  protected async confirmDeactivation(): Promise<void> {
    const quiz = this.quizStore.detail();
    if (!quiz || this.quizId === null || this.changingStatus()) return;
    const data: ConfirmDialogData = {
      title: 'Deaktivirati kviz?',
      message: `Kviz "${quiz.title}" više neće biti dostupan za pokretanje novih sesija dok ga ponovo ne aktivirate.`,
      confirmLabel: 'Deaktiviraj',
      tone: 'destructive',
    };
    const confirmed = await firstValueFrom(
      this.dialog
        .open(ConfirmDialog, {
          data,
          width: '30rem',
          maxWidth: 'calc(100vw - 2rem)',
          panelClass: 'clq-dialog-panel',
        })
        .afterClosed(),
    );
    if (!confirmed) return;
    this.changingStatus.set(true);
    this.statusError.set(null);
    try {
      await this.quizStore.deactivate(this.quizId);
      this.notifications.success('Kviz je deaktiviran.');
    } catch (error: unknown) {
      const safeMessage =
        error instanceof HttpErrorResponse && error.status === 409
          ? 'Status kviza se ne može mijenjati dok postoji otvorena sesija.'
          : 'Nije moguće deaktivirati kviz.';
      this.statusError.set(safeMessage);
      this.notifications.error(safeMessage);
    } finally {
      this.changingStatus.set(false);
    }
  }

  protected async confirmDelete(): Promise<void> {
    const quiz = this.quizStore.detail();
    if (!quiz || this.quizId === null || this.deleting()) return;
    const data: ConfirmDialogData = {
      title: 'Obrisati kviz?',
      message: `Kviz "${quiz.title}" biće uklonjen iz biblioteke.\nOvu radnju nije moguće poništiti kroz aplikaciju.`,
      confirmLabel: 'Obriši kviz',
      tone: 'destructive',
    };
    const confirmed = await firstValueFrom(
      this.dialog
        .open(ConfirmDialog, {
          data,
          width: '30rem',
          maxWidth: 'calc(100vw - 2rem)',
          panelClass: 'clq-dialog-panel',
        })
        .afterClosed(),
    );
    if (!confirmed) return;
    this.deleting.set(true);
    this.deleteError.set(null);
    try {
      await this.quizStore.delete(this.quizId);
      this.notifications.success('Kviz je obrisan.');
      await this.router.navigateByUrl('/app/quizzes');
    } catch (error: unknown) {
      const safeMessage =
        error instanceof HttpErrorResponse && error.status === 409
          ? 'Kviz se ne može obrisati dok postoji otvorena sesija.'
          : 'Nije moguće obrisati kviz.';
      this.deleteError.set(safeMessage);
      this.notifications.error(safeMessage);
    } finally {
      this.deleting.set(false);
    }
  }

  protected questionCountLabel(count: number): string {
    const mod100 = count % 100;
    const mod10 = count % 10;
    if (mod10 === 1 && mod100 !== 11) return `${count} pitanje`;
    if (mod10 >= 2 && mod10 <= 4 && !(mod100 >= 12 && mod100 <= 14)) return `${count} pitanja`;
    return `${count} pitanja`;
  }

  protected async retry(): Promise<void> {
    if (this.quizId !== null) {
      await this.quizStore.load(this.quizId);
      this.restoreForm();
    }
  }

  protected questionTypeLabel(type: QuestionType): string {
    return QUESTION_TYPE_BADGES[type];
  }

  protected correctAnswerCount(question: QuestionItem): number {
    return question.options.filter(({ isCorrect }) => isCorrect).length;
  }

  protected answerSummary(question: QuestionItem): string {
    const optionCount = question.options.length;
    const correctCount = this.correctAnswerCount(question);
    const optionLabel = optionCount === 1 ? 'odgovor' : 'odgovora';
    return `${optionCount} ${optionLabel} · ${correctCount} ${correctCount === 1 ? 'tačan' : 'tačna'}`;
  }

  protected markQuestionImageUnavailable(path: string): void {
    this.unavailableQuestionImages.update((current) => new Set([...current, path]));
  }

  protected async dropQuestion(event: CdkDragDrop<QuestionItem[]>): Promise<void> {
    if (event.previousIndex === event.currentIndex || this.questionsStore.reordering()) return;
    const reordered = [...this.questionsStore.questions()];
    moveItemInArray(reordered, event.previousIndex, event.currentIndex);
    await this.saveQuestionOrder(reordered.map(({ id }) => id));
  }

  protected async moveQuestion(questionId: number, direction: -1 | 1): Promise<void> {
    if (this.questionsStore.reordering()) return;
    const questions = this.questionsStore.questions();
    const index = questions.findIndex(({ id }) => id === questionId);
    const target = index + direction;
    if (index < 0 || target < 0 || target >= questions.length) return;
    const reordered = [...questions];
    moveItemInArray(reordered, index, target);
    await this.saveQuestionOrder(reordered.map(({ id }) => id));
  }

  protected async confirmQuestionDelete(question: QuestionItem): Promise<void> {
    const quiz = this.quizStore.detail();
    if (!quiz || this.quizId === null) return;
    const preview = this.questionPreview(question.questionText);
    const removesLastActiveQuestion = quiz.isActive && quiz.questionCount === 1;
    const data: ConfirmDialogData = {
      title: 'Obrisati pitanje?',
      message: `Pitanje "${preview}" biće uklonjeno iz kviza.${
        removesLastActiveQuestion
          ? '\nBrisanjem posljednjeg pitanja kviz će biti automatski deaktiviran.'
          : ''
      }`,
      confirmLabel: 'Obriši pitanje',
      tone: 'destructive',
    };
    const confirmed = await firstValueFrom(
      this.dialog
        .open(ConfirmDialog, {
          data,
          width: '30rem',
          maxWidth: 'calc(100vw - 2rem)',
          panelClass: 'clq-dialog-panel',
        })
        .afterClosed(),
    );
    if (!confirmed) return;

    try {
      await this.questionsStore.delete(this.quizId, question.id);
      await Promise.all([
        this.questionsStore.loadList(this.quizId),
        this.quizStore.load(this.quizId),
      ]);
      const canonicalQuiz = this.quizStore.detail();
      this.notifications.success(
        removesLastActiveQuestion && canonicalQuiz && !canonicalQuiz.isActive
          ? 'Pitanje je obrisano. Kviz je deaktiviran jer više nema pitanja.'
          : 'Pitanje je obrisano.',
      );
    } catch (error: unknown) {
      const message = isQuestionContentLockError(error)
        ? QUESTION_CONTENT_LOCK_MESSAGE
        : 'Nije moguće obrisati pitanje.';
      this.notifications.error(message);
    }
  }

  private async saveQuestionOrder(questionIds: number[]): Promise<void> {
    if (this.quizId === null) return;
    try {
      await this.questionsStore.reorder(this.quizId, questionIds);
    } catch (error: unknown) {
      this.notifications.error(
        isQuestionContentLockError(error)
          ? QUESTION_CONTENT_LOCK_MESSAGE
          : 'Nije moguće sačuvati novi redoslijed pitanja.',
      );
      return;
    }

    try {
      await this.quizStore.refresh(this.quizId);
    } catch {
      // The order is already canonical; metadata refresh failure must not report it as failed.
    }
  }

  private questionPreview(questionText: string): string {
    const normalized = questionText.trim().replace(/\s+/g, ' ');
    return normalized.length <= 80 ? normalized : `${normalized.slice(0, 77)}…`;
  }

  private fieldError(field: 'title' | 'version' | 'description'): string | null {
    if (!this.submitted() && !this.quizForm[field]().touched()) return null;
    return this.quizForm[field]().errors()[0]?.message ?? null;
  }

  private selectionToTopicId(selection: string): number | null {
    if (selection === 'none' || selection === '') return null;
    const id = Number(selection);
    return Number.isSafeInteger(id) && id > 0 ? id : null;
  }
}
