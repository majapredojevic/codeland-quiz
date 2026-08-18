import { HttpErrorResponse } from '@angular/common/http';
import { Component, computed, inject, OnDestroy, OnInit, signal } from '@angular/core';
import { disabled, FormField, form, validate } from '@angular/forms/signals';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { firstValueFrom } from 'rxjs';

import { NotificationService } from '../../../../../shared/feedback/notification.service';
import { QuestionImagesApiService } from '../../data-access/question-images-api.service';
import { QuestionImageItem } from '../../data-access/question-images.models';
import {
  backendErrorMessage,
  isQuestionContentLockError,
  questionImageUploadErrorMessage,
  questionMutationErrorMessage,
} from '../../data-access/quiz-error.utils';
import { QuizStore } from '../../data-access/quiz.store';
import {
  CreateQuestionRequest,
  QuestionItem,
  QuestionOptionInput,
  QUESTION_TEXT_MAX_LENGTH,
  QUESTION_TYPE_LABELS,
  QuestionType,
  UpdateQuestionRequest,
} from '../../data-access/questions.models';
import { QuestionsStore } from '../../data-access/questions.store';
import { QuestionTextLimitDirective } from './question-text-limit.directive';

interface QuestionFormModel {
  questionText: string;
  questionType: QuestionType;
  timeLimitSeconds: number;
  maxPoints: number;
  options: QuestionOptionInput[];
}

interface SelectedQuestionImage {
  file: File;
  previewUrl: string;
}

const QUESTION_IMAGE_MAX_BYTES = 5 * 1024 * 1024;
const QUESTION_IMAGE_TYPES = new Set(['image/jpeg', 'image/png', 'image/webp']);
const QUESTION_IMAGE_EXTENSIONS = new Set(['jpg', 'jpeg', 'png', 'webp']);

const blankOptions = (count: number): QuestionOptionInput[] =>
  Array.from({ length: count }, () => ({ optionText: '', isCorrect: false }));

const defaultModel = (): QuestionFormModel => ({
  questionText: '',
  questionType: 'SINGLE_CHOICE',
  timeLimitSeconds: 30,
  maxPoints: 1000,
  options: blankOptions(4),
});

@Component({
  selector: 'clq-question-editor-page',
  imports: [FormField, RouterLink, QuestionTextLimitDirective],
  templateUrl: './question-editor-page.html',
})
export class QuestionEditorPage implements OnInit, OnDestroy {
  protected readonly quizStore = inject(QuizStore);
  protected readonly questionsStore = inject(QuestionsStore);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly notifications = inject(NotificationService);
  private readonly questionImagesApi = inject(QuestionImagesApiService);
  private destroyed = false;

  protected readonly quizId = signal<number | null>(null);
  protected readonly questionId = signal<number | null>(null);
  protected readonly editMode = signal(false);
  protected readonly invalidQuiz = signal(false);
  protected readonly invalidQuestion = signal(false);
  protected readonly mutationQuizMissing = signal(false);
  protected readonly mutationQuestionMissing = signal(false);
  protected readonly submitted = signal(false);
  protected readonly saving = signal(false);
  protected readonly requestError = signal<string | null>(null);
  protected readonly selectedImage = signal<SelectedQuestionImage | null>(null);
  protected readonly imageRemoved = signal(false);
  protected readonly imagePreviewFailed = signal(false);
  protected readonly imageSelectionError = signal<string | null>(null);
  protected readonly imageAccept = 'image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp';
  protected readonly questionTextMaxLength = QUESTION_TEXT_MAX_LENGTH;
  protected readonly legacyQuestionTextOverLimit = signal(false);
  private readonly legacyQuestionTextLength = signal(0);
  protected readonly formModel = signal<QuestionFormModel>(defaultModel());
  protected readonly questionForm = form(this.formModel, (question) => {
    disabled(question.questionText, { when: () => this.saving() });
    disabled(question.timeLimitSeconds, { when: () => this.saving() });
    disabled(question.maxPoints, { when: () => this.saving() });
    validate(question.questionText, ({ value }) => {
      const questionText = value().trim();
      if (!questionText) return { kind: 'required', message: 'Unesite tekst pitanja.' };
      if (Array.from(questionText).length > QUESTION_TEXT_MAX_LENGTH) {
        return {
          kind: 'maxLength',
          message: 'Tekst pitanja može imati najviše 250 znakova.',
        };
      }
      return undefined;
    });
    validate(question.timeLimitSeconds, ({ value }) =>
      !Number.isInteger(value()) || value() < 30 || value() > 300
        ? { kind: 'range', message: 'Vrijeme mora biti između 30 i 300 sekundi.' }
        : undefined,
    );
    validate(question.maxPoints, ({ value }) =>
      !Number.isInteger(value()) || value() < 1 || value() > 10000
        ? { kind: 'range', message: 'Broj bodova mora biti između 1 i 10000.' }
        : undefined,
    );
  });
  protected readonly typeChoices = (
    ['TRUE_FALSE', 'SINGLE_CHOICE', 'MULTIPLE_CHOICE'] as const
  ).map((value) => ({ value, label: QUESTION_TYPE_LABELS[value] }));
  protected readonly optionLetters = ['A', 'B', 'C', 'D'];
  protected readonly normalized = computed<QuestionFormModel>(() => ({
    ...this.formModel(),
    questionText: this.formModel().questionText.trim(),
    options: this.formModel().options.map((option) => ({
      optionText: option.optionText.trim(),
      isCorrect: option.isCorrect,
    })),
  }));
  protected readonly optionsValid = computed(() => {
    const { options, questionType } = this.normalized();
    if (options.some(({ optionText }) => !optionText || Array.from(optionText).length > 255)) {
      return false;
    }
    const normalizedTexts = options.map(({ optionText }) => optionText.toLocaleLowerCase('bs'));
    if (new Set(normalizedTexts).size !== normalizedTexts.length) return false;
    const correctCount = options.filter(({ isCorrect }) => isCorrect).length;
    if (questionType === 'TRUE_FALSE') {
      return (
        options.length === 2 &&
        options[0]?.optionText === 'Tačno' &&
        options[1]?.optionText === 'Netačno' &&
        correctCount === 1
      );
    }
    if (questionType === 'SINGLE_CHOICE') {
      return (options.length === 2 || options.length === 4) && correctCount === 1;
    }
    return options.length === 4 && (correctCount === 2 || correctCount === 3);
  });
  protected readonly duplicateOptions = computed(() => {
    const texts = this.normalized()
      .options.map(({ optionText }) => optionText.toLocaleLowerCase('bs'))
      .filter(Boolean);
    return new Set(texts).size !== texts.length;
  });
  protected readonly correctCount = computed(
    () => this.formModel().options.filter(({ isCorrect }) => isCorrect).length,
  );
  protected readonly questionTextLength = computed(
    () =>
      this.legacyQuestionTextOverLimit()
        ? this.legacyQuestionTextLength()
        : Array.from(this.formModel().questionText).length,
  );
  protected readonly formValid = computed(() => this.questionForm().valid() && this.optionsValid());
  protected readonly previewSource = computed(() => {
    const selected = this.selectedImage();
    if (selected) return selected.previewUrl;
    if (this.imageRemoved()) return null;
    return this.editMode() ? (this.questionsStore.detail()?.imagePath ?? null) : null;
  });
  protected readonly imageChanged = computed(() => {
    if (this.selectedImage()) return true;
    return this.imageRemoved() && (this.questionsStore.detail()?.imagePath ?? null) !== null;
  });
  protected readonly dirty = computed(() => {
    if (!this.editMode()) return true;
    const question = this.questionsStore.detail();
    if (!question) return false;
    const value = this.normalized();
    return (
      value.questionText !== question.questionText ||
      value.questionType !== question.questionType ||
      value.timeLimitSeconds !== question.timeLimitSeconds ||
      value.maxPoints !== question.maxPoints ||
      !this.optionsEqual(value.options, question.options) ||
      this.imageChanged()
    );
  });
  protected readonly questionTextError = computed(() => this.fieldError('questionText'));
  protected readonly timeError = computed(() => this.fieldError('timeLimitSeconds'));
  protected readonly pointsError = computed(() => this.fieldError('maxPoints'));

  async ngOnInit(): Promise<void> {
    const quizId = this.parseId(this.route.snapshot.paramMap.get('quizId'));
    if (quizId === null) {
      this.invalidQuiz.set(true);
      return;
    }
    this.quizId.set(quizId);

    const questionParam = this.route.snapshot.paramMap.get('questionId');
    this.editMode.set(questionParam !== null);
    if (questionParam !== null) {
      const questionId = this.parseId(questionParam);
      if (questionId === null) {
        this.invalidQuestion.set(true);
        return;
      }
      this.questionId.set(questionId);
      await Promise.all([
        this.quizStore.load(quizId),
        this.questionsStore.loadQuestion(quizId, questionId),
      ]);
      this.restoreCanonical();
      return;
    }

    await this.quizStore.load(quizId);
  }

  ngOnDestroy(): void {
    this.destroyed = true;
    this.revokeSelectedPreview();
    this.quizStore.clear();
    this.questionsStore.clearDetail();
  }

  protected guardPendingNavigation(event: Event): void {
    if (!this.saving()) return;
    event.preventDefault();
    event.stopImmediatePropagation();
  }

  protected openImagePicker(input: HTMLInputElement): void {
    if (this.saving()) return;
    this.imageSelectionError.set(null);
    input.click();
  }

  protected selectImage(event: Event): void {
    const input = event.target as HTMLInputElement;
    if (this.saving()) {
      input.value = '';
      return;
    }
    const file = input.files?.item(0) ?? null;
    input.value = '';
    if (!file) return;

    const validationError = this.validateImage(file);
    if (validationError) {
      this.imageSelectionError.set(validationError);
      return;
    }

    const previewUrl = URL.createObjectURL(file);
    this.revokeSelectedPreview();
    this.selectedImage.set({ file, previewUrl });
    this.imageRemoved.set(false);
    this.imagePreviewFailed.set(false);
    this.imageSelectionError.set(null);
    this.clearRequestError();
  }

  protected removeImage(): void {
    if (this.saving()) return;
    this.revokeSelectedPreview();
    this.selectedImage.set(null);
    this.imageRemoved.set(this.editMode() && this.questionsStore.detail()?.imagePath !== null);
    this.imagePreviewFailed.set(false);
    this.imageSelectionError.set(null);
    this.clearRequestError();
  }

  protected markImagePreviewUnavailable(): void {
    this.imagePreviewFailed.set(true);
  }

  protected selectType(type: QuestionType): void {
    if (this.saving()) return;
    const current = this.formModel();
    if (current.questionType === type) return;
    let options: QuestionOptionInput[];
    if (type === 'TRUE_FALSE') {
      options = [
        { optionText: 'Tačno', isCorrect: false },
        { optionText: 'Netačno', isCorrect: false },
      ];
    } else if (current.questionType === 'TRUE_FALSE') {
      options = blankOptions(4);
    } else {
      options = [...current.options, ...blankOptions(4)].slice(0, 4).map((option) => ({
        optionText: option.optionText,
        isCorrect: false,
      }));
    }
    this.formModel.set({ ...current, questionType: type, options });
    this.clearRequestError();
  }

  protected setSingleOptionCount(count: 2 | 4): void {
    if (this.saving()) return;
    const current = this.formModel();
    if (current.questionType !== 'SINGLE_CHOICE' || current.options.length === count) return;
    const options =
      count === 2
        ? current.options.slice(0, 2)
        : [...current.options, ...blankOptions(4 - current.options.length)].slice(0, 4);
    this.formModel.set({ ...current, options });
    this.clearRequestError();
  }

  protected updateOptionText(index: number, event: Event): void {
    if (this.saving()) return;
    const value = (event.target as HTMLInputElement).value;
    this.formModel.update((current) => ({
      ...current,
      options: current.options.map((option, optionIndex) =>
        optionIndex === index ? { ...option, optionText: value } : option,
      ),
    }));
    this.clearRequestError();
  }

  protected selectSingleCorrect(index: number): void {
    if (this.saving()) return;
    this.formModel.update((current) => ({
      ...current,
      options: current.options.map((option, optionIndex) => ({
        ...option,
        isCorrect: optionIndex === index,
      })),
    }));
    this.clearRequestError();
  }

  protected toggleMultipleCorrect(index: number): void {
    if (this.saving()) return;
    const current = this.formModel();
    const option = current.options[index];
    if (!option || (!option.isCorrect && this.correctCount() >= 3)) return;
    this.formModel.set({
      ...current,
      options: current.options.map((item, optionIndex) =>
        optionIndex === index ? { ...item, isCorrect: !item.isCorrect } : item,
      ),
    });
    this.clearRequestError();
  }

  protected optionError(index: number): string | null {
    if (!this.submitted()) return null;
    const option = this.normalized().options[index];
    if (!option?.optionText) return 'Unesite tekst odgovora.';
    if (Array.from(option.optionText).length > 255) {
      return 'Tekst odgovora može imati najviše 255 znakova.';
    }
    if (this.duplicateOptions()) return 'Odgovori moraju imati različit tekst.';
    return null;
  }

  protected correctnessError(): string | null {
    if (!this.submitted() || this.optionsValid() || this.hasOptionTextError()) return null;
    const type = this.formModel().questionType;
    if (type === 'TRUE_FALSE') return 'Odaberite jedan tačan odgovor.';
    if (type === 'SINGLE_CHOICE') return 'Označite jedan tačan odgovor.';
    return 'Označite dva ili tri tačna odgovora.';
  }

  protected clearRequestError(): void {
    this.requestError.set(null);
  }

  protected limitQuestionText(event: Event): void {
    if (!(event.target instanceof HTMLTextAreaElement)) return;
    const characters = Array.from(event.target.value);
    if (this.legacyQuestionTextOverLimit()) {
      this.legacyQuestionTextLength.set(characters.length);
    }
    if (characters.length <= QUESTION_TEXT_MAX_LENGTH) {
      this.legacyQuestionTextOverLimit.set(false);
      return;
    }
    if (this.legacyQuestionTextOverLimit()) return;

    const limitedValue = characters.slice(0, QUESTION_TEXT_MAX_LENGTH).join('');
    event.target.value = limitedValue;
    this.formModel.update((current) => ({ ...current, questionText: limitedValue }));
  }

  protected restoreCanonical(): void {
    const question = this.questionsStore.detail();
    if (!question) return;
    const hasLegacyQuestionText =
      Array.from(question.questionText).length > QUESTION_TEXT_MAX_LENGTH;
    this.resetImageState();
    this.questionForm().reset({
      questionText: question.questionText,
      questionType: question.questionType,
      timeLimitSeconds: question.timeLimitSeconds,
      maxPoints: question.maxPoints,
      options: question.options.map(({ optionText, isCorrect }) => ({ optionText, isCorrect })),
    });
    this.legacyQuestionTextLength.set(Array.from(question.questionText).length);
    this.legacyQuestionTextOverLimit.set(hasLegacyQuestionText);
    if (hasLegacyQuestionText) this.questionForm.questionText().markAsTouched();
    this.submitted.set(false);
    this.requestError.set(null);
  }

  protected async submit(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    this.submitted.set(true);
    this.questionForm().markAsTouched();
    this.requestError.set(null);
    const quizId = this.quizId();
    if (
      !quizId ||
      !this.formValid() ||
      this.imageSelectionError() ||
      this.saving() ||
      (this.editMode() && !this.dirty())
    ) {
      return;
    }

    const editing = this.editMode();
    const valueSnapshot = this.normalized();
    const selectedImageSnapshot = this.selectedImage();
    const imageRemovedSnapshot = this.imageRemoved();
    const editQuestionId = editing ? this.questionId() : null;
    const canonicalQuestion = editing ? this.questionsStore.detail() : null;
    if (editing && (!editQuestionId || !canonicalQuestion)) return;

    const createRequest = editing ? null : this.createRequest(valueSnapshot, null);
    const updateRequest = canonicalQuestion
      ? this.updateRequest(valueSnapshot, canonicalQuestion, imageRemovedSnapshot)
      : null;

    this.saving.set(true);
    let uploadedImage: QuestionImageItem | null = null;
    let phase: 'upload' | 'mutation' = selectedImageSnapshot ? 'upload' : 'mutation';
    let mutationSucceeded = false;
    try {
      if (selectedImageSnapshot) {
        uploadedImage = (
          await firstValueFrom(this.questionImagesApi.upload(quizId, selectedImageSnapshot.file))
        ).image;
        if (this.destroyed) {
          await this.cleanupUploadedImage(quizId, uploadedImage.fileName);
          return;
        }
        phase = 'mutation';
      }

      if (editing) {
        if (!editQuestionId || !updateRequest) return;
        if (uploadedImage) updateRequest.imagePath = uploadedImage.path;
        await this.questionsStore.update(
          quizId,
          editQuestionId,
          updateRequest as UpdateQuestionRequest,
        );
        mutationSucceeded = true;
        this.notifications.success('Izmjene su sačuvane.');
      } else {
        if (!createRequest) return;
        if (uploadedImage) createRequest.imagePath = uploadedImage.path;
        await this.questionsStore.create(quizId, createRequest);
        mutationSucceeded = true;
        this.notifications.success('Pitanje je uspješno dodano.');
      }
      await this.router.navigate(['/app/quizzes', quizId], { queryParams: { tab: 'questions' } });
    } catch (error: unknown) {
      if (phase === 'mutation' && uploadedImage && !mutationSucceeded) {
        await this.cleanupUploadedImage(quizId, uploadedImage.fileName);
      }
      if (mutationSucceeded || this.destroyed) return;
      if (this.isQuizNotFound(error)) this.mutationQuizMissing.set(true);
      if (editing && this.isQuestionNotFound(error)) this.mutationQuestionMissing.set(true);
      const fallback = editing ? 'Nije moguće sačuvati izmjene.' : 'Nije moguće dodati pitanje.';
      const message =
        phase === 'upload'
          ? questionImageUploadErrorMessage(error)
          : questionMutationErrorMessage(error, fallback);
      this.requestError.set(message);
      if (isQuestionContentLockError(error)) this.notifications.error(message);
    } finally {
      this.saving.set(false);
    }
  }

  protected async retry(): Promise<void> {
    const quizId = this.quizId();
    if (!quizId) return;
    if (this.editMode() && this.questionId()) {
      await Promise.all([
        this.quizStore.load(quizId),
        this.questionsStore.loadQuestion(quizId, this.questionId()!),
      ]);
      this.restoreCanonical();
    } else {
      await this.quizStore.load(quizId);
    }
  }

  private updateRequest(
    value: QuestionFormModel,
    canonical: QuestionItem,
    imageRemoved: boolean,
  ): Partial<CreateQuestionRequest> {
    const changes: Partial<CreateQuestionRequest> = {};
    if (value.questionText !== canonical.questionText) changes.questionText = value.questionText;
    if (value.questionType !== canonical.questionType) changes.questionType = value.questionType;
    if (value.timeLimitSeconds !== canonical.timeLimitSeconds) {
      changes.timeLimitSeconds = value.timeLimitSeconds;
    }
    if (value.maxPoints !== canonical.maxPoints) changes.maxPoints = value.maxPoints;
    if (!this.optionsEqual(value.options, canonical.options)) changes.options = value.options;
    if (imageRemoved && canonical.imagePath !== null) changes.imagePath = null;
    return changes;
  }

  private createRequest(value: QuestionFormModel, imagePath: string | null): CreateQuestionRequest {
    return {
      questionText: value.questionText,
      questionType: value.questionType,
      imagePath,
      timeLimitSeconds: value.timeLimitSeconds,
      maxPoints: value.maxPoints,
      options: value.options,
    };
  }

  private optionsEqual(
    current: QuestionOptionInput[],
    canonical: Array<{ optionText: string; isCorrect: boolean }>,
  ): boolean {
    return (
      current.length === canonical.length &&
      current.every(
        (option, index) =>
          option.optionText === canonical[index]?.optionText &&
          option.isCorrect === canonical[index]?.isCorrect,
      )
    );
  }

  private hasOptionTextError(): boolean {
    return (
      this.normalized().options.some(
        ({ optionText }) => !optionText || Array.from(optionText).length > 255,
      ) || this.duplicateOptions()
    );
  }

  private validateImage(file: File): string | null {
    if (file.size === 0) return 'Odabrani fajl nije podržana slika.';
    if (file.size > QUESTION_IMAGE_MAX_BYTES) return 'Slika može imati najviše 5 MB.';

    const extension = file.name.includes('.') ? file.name.split('.').pop()?.toLowerCase() : null;
    if (
      !QUESTION_IMAGE_TYPES.has(file.type.toLowerCase()) ||
      !extension ||
      !QUESTION_IMAGE_EXTENSIONS.has(extension)
    ) {
      return 'Podržani formati su JPG, PNG i WebP.';
    }
    return null;
  }

  private resetImageState(): void {
    this.revokeSelectedPreview();
    this.selectedImage.set(null);
    this.imageRemoved.set(false);
    this.imagePreviewFailed.set(false);
    this.imageSelectionError.set(null);
  }

  private revokeSelectedPreview(): void {
    const previewUrl = this.selectedImage()?.previewUrl;
    if (previewUrl) URL.revokeObjectURL(previewUrl);
  }

  private async cleanupUploadedImage(quizId: number, fileName: string): Promise<void> {
    try {
      await firstValueFrom(this.questionImagesApi.cleanup(quizId, fileName));
    } catch {
      // Cleanup is deliberately best-effort; the Question mutation error remains authoritative.
    }
  }

  private fieldError(field: 'questionText' | 'timeLimitSeconds' | 'maxPoints'): string | null {
    if (!this.submitted() && !this.questionForm[field]().touched()) return null;
    return this.questionForm[field]().errors()[0]?.message ?? null;
  }

  private parseId(value: string | null): number | null {
    if (value === null || !/^[1-9]\d*$/.test(value)) return null;
    const id = Number(value);
    return Number.isSafeInteger(id) ? id : null;
  }

  private isQuizNotFound(error: unknown): boolean {
    return (
      error instanceof HttpErrorResponse &&
      error.status === 404 &&
      backendErrorMessage(error) === 'Quiz was not found.'
    );
  }

  private isQuestionNotFound(error: unknown): boolean {
    return (
      error instanceof HttpErrorResponse &&
      error.status === 404 &&
      backendErrorMessage(error) === 'Question was not found.'
    );
  }
}
