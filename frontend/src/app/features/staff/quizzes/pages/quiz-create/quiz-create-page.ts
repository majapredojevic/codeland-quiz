import { HttpErrorResponse } from '@angular/common/http';
import { Component, computed, inject, OnInit, signal } from '@angular/core';
import { FormField, form, validate } from '@angular/forms/signals';
import { ActivatedRoute, Params, Router, RouterLink } from '@angular/router';

import { NotificationService } from '../../../../../shared/feedback/notification.service';
import { isMissingTopicError } from '../../data-access/quiz-error.utils';
import { QuizStore } from '../../data-access/quiz.store';
import { TopicReferenceStore } from '../../data-access/topic-reference.store';

interface QuizFormModel {
  title: string;
  version: number;
  topicSelection: string;
  description: string;
}

@Component({
  selector: 'clq-quiz-create-page',
  imports: [FormField, RouterLink],
  templateUrl: './quiz-create-page.html',
})
export class QuizCreatePage implements OnInit {
  protected readonly topics = inject(TopicReferenceStore);
  private readonly quizStore = inject(QuizStore);
  private readonly notifications = inject(NotificationService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);

  protected readonly contextTopicId = signal<number | null>(null);
  protected readonly formModel = signal<QuizFormModel>({
    title: '',
    version: 1,
    topicSelection: '',
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
  protected readonly submitting = signal(false);
  protected readonly compoundConflict = signal(false);
  protected readonly requestError = signal<string | null>(null);
  protected readonly backQueryParams = computed<Params | null>(() =>
    this.contextTopicId() === null ? null : { topicId: this.contextTopicId() },
  );
  protected readonly titleError = computed(() => this.fieldError('title'));
  protected readonly versionError = computed(() => this.fieldError('version'));
  protected readonly descriptionError = computed(() => this.fieldError('description'));

  async ngOnInit(): Promise<void> {
    const rawTopicId = this.route.snapshot.queryParamMap.get('topicId');
    const requestedId = this.parsePositiveId(rawTopicId);
    await this.topics.loadAll();

    if (requestedId === null) return;
    const selected = this.topics.topics().find((topic) => topic.id === requestedId);
    if (selected) {
      this.contextTopicId.set(requestedId);
      this.formModel.update((value) => ({ ...value, topicSelection: String(requestedId) }));
    } else if (!this.topics.error()) {
      this.notifications.info('Odabrana tema više ne postoji.');
    }
  }

  protected clearRequestErrors(): void {
    this.compoundConflict.set(false);
    this.requestError.set(null);
  }

  protected async submit(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    if (this.submitting()) return;
    this.submitted.set(true);
    this.quizForm().markAsTouched();
    this.requestError.set(null);
    if (!this.quizForm().valid() || this.compoundConflict()) return;

    const value = this.formModel();
    this.submitting.set(true);
    try {
      const quiz = await this.quizStore.create({
        title: value.title.trim(),
        version: value.version,
        description: value.description.trim() || null,
        topicId: this.selectionToTopicId(value.topicSelection),
      });
      this.notifications.success('Kviz je uspješno kreiran.');
      await this.router.navigateByUrl(`/app/quizzes/${quiz.id}?tab=questions`);
    } catch (error: unknown) {
      if (error instanceof HttpErrorResponse && error.status === 409) {
        this.compoundConflict.set(true);
      } else if (isMissingTopicError(error)) {
        this.requestError.set('Odabrana tema više ne postoji. Odaberite drugu temu.');
        await this.topics.loadAll(true);
      } else {
        this.requestError.set('Nije moguće kreirati kviz.');
      }
    } finally {
      this.submitting.set(false);
    }
  }

  private fieldError(field: 'title' | 'version' | 'description'): string | null {
    if (!this.submitted() && !this.quizForm[field]().touched()) return null;
    return this.quizForm[field]().errors()[0]?.message ?? null;
  }

  private selectionToTopicId(selection: string): number | null {
    if (selection === '' || selection === 'none') return null;
    const id = Number(selection);
    return Number.isSafeInteger(id) && id > 0 ? id : null;
  }

  private parsePositiveId(value: string | null): number | null {
    if (value === null || !/^[1-9]\d*$/.test(value)) return null;
    const id = Number(value);
    return Number.isSafeInteger(id) ? id : null;
  }
}
