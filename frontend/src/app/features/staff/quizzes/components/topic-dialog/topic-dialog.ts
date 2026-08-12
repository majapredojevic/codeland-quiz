import { HttpErrorResponse } from '@angular/common/http';
import { Component, computed, inject, signal } from '@angular/core';
import { FormField, form, validate } from '@angular/forms/signals';
import {
  MAT_DIALOG_DATA,
  MatDialogActions,
  MatDialogContent,
  MatDialogRef,
  MatDialogTitle,
} from '@angular/material/dialog';

import { QuizLibraryStore } from '../../data-access/quiz-library.store';
import { TopicItem } from '../../data-access/quizzes.models';

export type TopicDialogData = { mode: 'create' } | { mode: 'edit'; topic: TopicItem };

interface TopicFormModel {
  name: string;
  description: string;
}

@Component({
  selector: 'clq-topic-dialog',
  imports: [FormField, MatDialogActions, MatDialogContent, MatDialogTitle],
  templateUrl: './topic-dialog.html',
  styleUrl: './topic-dialog.scss',
})
export class TopicDialog {
  private readonly data = inject<TopicDialogData>(MAT_DIALOG_DATA);
  private readonly dialogRef = inject<MatDialogRef<TopicDialog, TopicItem>>(MatDialogRef);
  private readonly store = inject(QuizLibraryStore);
  private readonly editedTopic = this.data.mode === 'edit' ? this.data.topic : null;

  protected readonly isEdit = this.editedTopic !== null;
  protected readonly title = this.isEdit ? 'Uredi temu' : 'Nova tema';
  protected readonly submitLabel = this.isEdit ? 'Sačuvaj izmjene' : 'Dodaj temu';
  protected readonly formModel = signal<TopicFormModel>({
    name: this.editedTopic?.name ?? '',
    description: this.editedTopic?.description ?? '',
  });
  protected readonly topicForm = form(this.formModel, (topic) => {
    validate(topic.name, ({ value }) => {
      const name = value().trim();
      if (!name) return { kind: 'required', message: 'Unesite naziv teme.' };
      if (Array.from(name).length > 120) {
        return { kind: 'maxLength', message: 'Naziv teme može sadržati najviše 120 znakova.' };
      }
      return undefined;
    });
    validate(topic.description, ({ value }) =>
      Array.from(value().trim()).length > 255
        ? { kind: 'maxLength', message: 'Opis može sadržati najviše 255 znakova.' }
        : undefined,
    );
  });
  protected readonly submitted = signal(false);
  protected readonly submitting = signal(false);
  protected readonly duplicateName = signal(false);
  protected readonly requestError = signal<string | null>(null);
  protected readonly nameError = computed(() => {
    if (!this.submitted() && !this.topicForm.name().touched() && !this.duplicateName()) return null;
    return this.duplicateName()
      ? 'Tema sa ovim nazivom već postoji.'
      : (this.topicForm.name().errors()[0]?.message ?? null);
  });
  protected readonly descriptionError = computed(() => {
    if (!this.submitted() && !this.topicForm.description().touched()) return null;
    return this.topicForm.description().errors()[0]?.message ?? null;
  });

  protected clearNameError(): void {
    this.duplicateName.set(false);
    this.requestError.set(null);
  }

  protected clearRequestError(): void {
    this.requestError.set(null);
  }

  protected close(): void {
    this.dialogRef.close();
  }

  protected async submit(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    if (this.submitting()) return;

    this.submitted.set(true);
    this.topicForm().markAsTouched();
    this.requestError.set(null);
    if (!this.topicForm().valid() || this.duplicateName()) return;

    const value = this.formModel();
    const request = {
      name: value.name.trim(),
      description: value.description.trim() || null,
    };
    this.submitting.set(true);
    try {
      const topic = this.editedTopic
        ? await this.store.updateTopic(this.editedTopic.id, request)
        : await this.store.createTopic(request);
      this.dialogRef.close(topic);
    } catch (error: unknown) {
      if (error instanceof HttpErrorResponse && error.status === 409) {
        this.duplicateName.set(true);
        this.topicForm.name().markAsTouched();
      } else {
        this.requestError.set(
          this.isEdit ? 'Nije moguće sačuvati izmjene.' : 'Nije moguće kreirati temu.',
        );
      }
    } finally {
      this.submitting.set(false);
    }
  }
}
