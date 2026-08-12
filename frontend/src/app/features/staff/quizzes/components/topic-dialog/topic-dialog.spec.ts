import { HttpErrorResponse } from '@angular/common/http';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MAT_DIALOG_DATA, MatDialogRef } from '@angular/material/dialog';

import { QuizLibraryStore } from '../../data-access/quiz-library.store';
import { TopicDialog, TopicDialogData } from './topic-dialog';

describe('TopicDialog', () => {
  let fixture: ComponentFixture<TopicDialog>;
  let createTopic: ReturnType<typeof vi.fn>;
  let close: ReturnType<typeof vi.fn>;

  beforeEach(async () => {
    createTopic = vi.fn();
    close = vi.fn();
    await TestBed.configureTestingModule({
      imports: [TopicDialog],
      providers: [
        { provide: MAT_DIALOG_DATA, useValue: { mode: 'create' } satisfies TopicDialogData },
        { provide: MatDialogRef, useValue: { close } },
        { provide: QuizLibraryStore, useValue: { createTopic, updateTopic: vi.fn() } },
      ],
    }).compileComponents();
    fixture = TestBed.createComponent(TopicDialog);
    fixture.detectChanges();
  });

  async function submit(name: string, description = ''): Promise<void> {
    const element = fixture.nativeElement as HTMLElement;
    const nameInput = element.querySelector<HTMLInputElement>('#topic-name')!;
    const descriptionInput = element.querySelector<HTMLTextAreaElement>('#topic-description')!;
    nameInput.value = name;
    nameInput.dispatchEvent(new Event('input', { bubbles: true }));
    descriptionInput.value = description;
    descriptionInput.dispatchEvent(new Event('input', { bubbles: true }));
    element
      .querySelector<HTMLFormElement>('form')!
      .dispatchEvent(new SubmitEvent('submit', { bubbles: true, cancelable: true }));
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  it('normalizes fields, creates a topic, and closes only with canonical data', async () => {
    const created = { id: 8, name: 'Scratch', description: null };
    createTopic.mockResolvedValue(created);
    await submit('  Scratch  ', '   ');
    expect(createTopic).toHaveBeenCalledWith({ name: 'Scratch', description: null });
    expect(close).toHaveBeenCalledWith(created);
  });

  it('maps duplicate HTTP 409 to the topic name field in Croatian', async () => {
    createTopic.mockRejectedValue(new HttpErrorResponse({ status: 409 }));
    await submit('Scratch');
    expect(
      (fixture.nativeElement as HTMLElement).querySelector('#topic-name-error')?.textContent,
    ).toContain('Tema sa ovim nazivom već postoji.');
    expect(close).not.toHaveBeenCalled();
  });

  it('shows required validation without sending a request', async () => {
    await submit('   ');
    expect(createTopic).not.toHaveBeenCalled();
    expect((fixture.nativeElement as HTMLElement).textContent).toContain('Unesite naziv teme.');
  });
});
