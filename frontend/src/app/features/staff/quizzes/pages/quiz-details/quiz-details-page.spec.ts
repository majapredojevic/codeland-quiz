import { HttpErrorResponse } from '@angular/common/http';
import { CdkDragDrop } from '@angular/cdk/drag-drop';
import { OverlayContainer } from '@angular/cdk/overlay';
import { signal, WritableSignal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MatDialog } from '@angular/material/dialog';
import { ActivatedRoute, convertToParamMap, provideRouter, Router } from '@angular/router';
import { of } from 'rxjs';

import { ConfirmDialog } from '../../../../../shared/feedback/confirm-dialog/confirm-dialog';
import { NotificationService } from '../../../../../shared/feedback/notification.service';
import { QuizStore } from '../../data-access/quiz.store';
import { QuestionItem } from '../../data-access/questions.models';
import { QuestionsStore } from '../../data-access/questions.store';
import { QuizItem, TopicItem } from '../../data-access/quizzes.models';
import { TopicReferenceStore } from '../../data-access/topic-reference.store';
import { QuizDetailsPage } from './quiz-details-page';

const actor = { id: 1, name: 'Maja' };
const topics: TopicItem[] = [
  {
    id: 2,
    name: 'PHP',
    description: null,
    quizCount: 1,
    createdBy: actor,
    updatedBy: actor,
    createdAt: '',
    updatedAt: '',
  },
  {
    id: 5,
    name: 'Scratch',
    description: null,
    quizCount: 1,
    createdBy: actor,
    updatedBy: actor,
    createdAt: '',
    updatedAt: '',
  },
];
const baseQuiz: QuizItem = {
  id: 9,
  title: 'Petlje',
  version: 2,
  description: 'Opis',
  isActive: false,
  questionCount: 0,
  topic: { id: 5, name: 'Scratch' },
  createdBy: actor,
  updatedBy: actor,
  createdAt: '',
  updatedAt: '',
};
const baseQuestion: QuestionItem = {
  id: 11,
  quizId: 9,
  questionText: 'Koja naredba ispisuje tekst?',
  questionType: 'SINGLE_CHOICE',
  imagePath: null,
  timeLimitSeconds: 30,
  maxPoints: 1000,
  questionOrder: 1,
  options: [
    { id: 31, optionText: 'echo', isCorrect: true, optionOrder: 1 },
    { id: 32, optionText: 'read', isCorrect: false, optionOrder: 2 },
  ],
  createdAt: '',
  updatedAt: '',
};

describe('QuizDetailsPage', () => {
  let fixture: ComponentFixture<QuizDetailsPage>;
  let detail: WritableSignal<QuizItem | null>;
  let load: ReturnType<typeof vi.fn>;
  let refreshQuiz: ReturnType<typeof vi.fn>;
  let update: ReturnType<typeof vi.fn>;
  let activate: ReturnType<typeof vi.fn>;
  let deactivate: ReturnType<typeof vi.fn>;
  let remove: ReturnType<typeof vi.fn>;
  let dialogOpen: ReturnType<typeof vi.fn>;
  let success: ReturnType<typeof vi.fn>;
  let notifyError: ReturnType<typeof vi.fn>;
  let navigateByUrl: ReturnType<typeof vi.spyOn>;
  let questions: WritableSignal<QuestionItem[]>;
  let loadQuestions: ReturnType<typeof vi.fn>;
  let questionCount: WritableSignal<number>;
  let reorderQuestions: ReturnType<typeof vi.fn>;
  let deleteQuestion: ReturnType<typeof vi.fn>;
  let overlayContainer: OverlayContainer;

  async function setup(
    quiz: QuizItem = baseQuiz,
    tab: string | null = null,
    initialQuestions: QuestionItem[] = [],
  ): Promise<HTMLElement> {
    detail = signal<QuizItem | null>(quiz);
    load = vi.fn().mockResolvedValue(undefined);
    refreshQuiz = vi.fn().mockResolvedValue(true);
    update = vi.fn(async (_id: number, _request: unknown) => detail());
    activate = vi.fn(async () => {
      const canonical = { ...detail()!, isActive: true };
      detail.set(canonical);
      return canonical;
    });
    deactivate = vi.fn(async () => {
      const canonical = { ...detail()!, isActive: false };
      detail.set(canonical);
      return canonical;
    });
    remove = vi.fn().mockResolvedValue(undefined);
    dialogOpen = vi.fn(() => ({ afterClosed: () => of(false) }));
    success = vi.fn();
    notifyError = vi.fn();
    questions = signal<QuestionItem[]>(initialQuestions);
    questionCount = signal(initialQuestions.length);
    loadQuestions = vi.fn().mockResolvedValue(undefined);
    reorderQuestions = vi.fn().mockResolvedValue(undefined);
    deleteQuestion = vi.fn().mockResolvedValue(undefined);
    await TestBed.configureTestingModule({
      imports: [QuizDetailsPage],
      providers: [
        provideRouter([]),
        {
          provide: ActivatedRoute,
          useValue: {
            snapshot: { paramMap: convertToParamMap({ id: '9' }) },
            queryParamMap: of(convertToParamMap(tab ? { tab } : {})),
          },
        },
        {
          provide: QuestionsStore,
          useValue: {
            questions: questions.asReadonly(),
            questionCount: questionCount.asReadonly(),
            listLoading: signal(false).asReadonly(),
            listError: signal(null).asReadonly(),
            reordering: signal(false).asReadonly(),
            loadList: loadQuestions,
            reorder: reorderQuestions,
            delete: deleteQuestion,
            clearList: vi.fn(),
          },
        },
        {
          provide: QuizStore,
          useValue: {
            detail: detail.asReadonly(),
            loading: signal(false).asReadonly(),
            error: signal(null).asReadonly(),
            load,
            refresh: refreshQuiz,
            update,
            activate,
            deactivate,
            delete: remove,
            clear: vi.fn(),
          },
        },
        {
          provide: TopicReferenceStore,
          useValue: {
            topics: signal(topics).asReadonly(),
            loading: signal(false).asReadonly(),
            error: signal(null).asReadonly(),
            loadAll: vi.fn().mockResolvedValue(undefined),
          },
        },
        { provide: MatDialog, useValue: { open: dialogOpen } },
        { provide: NotificationService, useValue: { success, error: notifyError, info: vi.fn() } },
      ],
    }).compileComponents();
    navigateByUrl = vi.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);
    fixture = TestBed.createComponent(QuizDetailsPage);
    overlayContainer = TestBed.inject(OverlayContainer);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  afterEach(() => {
    overlayContainer?.getContainerElement().replaceChildren();
  });

  function input(element: HTMLElement, selector: string, value: string): void {
    const control = element.querySelector<
      HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement
    >(selector)!;
    control.value = value;
    control.dispatchEvent(new Event('input', { bubbles: true }));
    if (control instanceof HTMLSelectElement) {
      control.dispatchEvent(new Event('change', { bubbles: true }));
    }
    fixture.detectChanges();
  }

  function page(): {
    save(event: SubmitEvent): Promise<void>;
    restoreForm(): void;
    activate(): Promise<void>;
    confirmDeactivation(): Promise<void>;
    confirmDelete(): Promise<void>;
    moveQuestion(questionId: number, direction: -1 | 1): Promise<void>;
    confirmQuestionDelete(question: QuestionItem): Promise<void>;
    dropQuestion(event: CdkDragDrop<QuestionItem[]>): Promise<void>;
  } {
    return fixture.componentInstance as unknown as {
      save(event: SubmitEvent): Promise<void>;
      restoreForm(): void;
      activate(): Promise<void>;
      confirmDeactivation(): Promise<void>;
      confirmDelete(): Promise<void>;
      moveQuestion(questionId: number, direction: -1 | 1): Promise<void>;
      confirmQuestionDelete(question: QuestionItem): Promise<void>;
      dropQuestion(event: CdkDragDrop<QuestionItem[]>): Promise<void>;
    };
  }

  it('loads canonical detail by route id and renders title, version, topic, description, questions and status', async () => {
    const element = await setup();
    expect(load).toHaveBeenCalledWith(9);
    expect(element.querySelector('h1')?.textContent).toContain('Petlje');
    expect(element.querySelector('.summary')?.textContent).toContain('Verzija v2');
    expect(element.querySelector('.summary')?.textContent).toContain('Scratch');
    expect(element.querySelector('.summary')?.textContent).toContain('0 pitanja');
    expect(element.querySelector<HTMLTextAreaElement>('#quiz-description')?.value).toBe('Opis');
    expect(element.textContent).toContain('Neaktivan');
    expect(
      Array.from(element.querySelectorAll('#quiz-topic option')).map((option) =>
        option.textContent?.trim(),
      ),
    ).toEqual(['Bez teme', 'PHP', 'Scratch']);
    expect(element.textContent).not.toContain('Pretraži teme');
    expect(element.textContent).not.toContain('Dodaj pitanje');
  });

  it('sends only normalized changed fields and updates the canonical header after save', async () => {
    const element = await setup();
    update.mockImplementation(async (_id: number, request: { title?: string }) => {
      const canonical = { ...detail()!, title: request.title ?? detail()!.title };
      detail.set(canonical);
      return canonical;
    });
    input(element, '#quiz-title', '  Nove petlje  ');
    await page().save(new SubmitEvent('submit'));
    fixture.detectChanges();
    expect(update).toHaveBeenCalledWith(9, { title: 'Nove petlje' });
    expect(element.querySelector('h1')?.textContent).toContain('Nove petlje');
    expect(success).toHaveBeenCalledWith('Izmjene su sačuvane.');
  });

  it('does not PATCH unchanged normalized data and Cancel restores canonical fields', async () => {
    const element = await setup();
    input(element, '#quiz-title', ' Petlje ');
    await page().save(new SubmitEvent('submit'));
    expect(update).not.toHaveBeenCalled();
    input(element, '#quiz-title', 'Drugo');
    page().restoreForm();
    fixture.detectChanges();
    expect(element.querySelector<HTMLInputElement>('#quiz-title')?.value).toBe('Petlje');
  });

  it('maps metadata uniqueness and missing-topic failures without replacing canonical data', async () => {
    const element = await setup();
    input(element, '#quiz-title', 'Drugi naslov');
    update.mockRejectedValueOnce(new HttpErrorResponse({ status: 409 }));
    await page().save(new SubmitEvent('submit'));
    fixture.detectChanges();
    expect(element.textContent).toContain('Kviz sa ovim nazivom i verzijom već postoji.');
    expect(detail()?.title).toBe('Petlje');

    input(element, '#quiz-topic', '2');
    update.mockRejectedValueOnce(
      new HttpErrorResponse({ status: 404, error: { error: 'Topic was not found.' } }),
    );
    await page().save(new SubmitEvent('submit'));
    fixture.detectChanges();
    expect(element.textContent).toContain('Odabrana tema više ne postoji. Odaberite drugu temu.');
  });

  it('renders and edits a canonical quiz without a topic', async () => {
    const element = await setup({ ...baseQuiz, topic: null, description: null });
    expect(element.querySelector<HTMLSelectElement>('#quiz-topic')?.value).toBe('none');
    expect(element.querySelector<HTMLTextAreaElement>('#quiz-description')?.value).toBe('');
    expect(element.querySelector('.summary')?.textContent).toContain('Bez teme');
  });

  it('shows canonical creator metadata without duplicating an identical initial update', async () => {
    const element = await setup({
      ...baseQuiz,
      createdAt: '2026-08-12T16:10:00Z',
      updatedAt: '2026-08-12T16:10:00+00:00',
    });
    const metadata = element.querySelector('.entity-audit-meta')!;
    expect(metadata.textContent).toContain('Kreirao: Maja');
    expect(metadata.textContent).not.toContain('Posljednja izmjena');
  });

  it('shows canonical updater metadata after a meaningful later quiz change', async () => {
    const element = await setup({
      ...baseQuiz,
      updatedBy: { id: 2, name: 'Marko' },
      createdAt: '2026-08-12T16:10:00Z',
      updatedAt: '2026-08-12T16:42:00Z',
    });
    const metadata = element.querySelector('.entity-audit-meta')!;
    expect(metadata.textContent).toContain('Kreirao: Maja');
    expect(metadata.textContent).toContain('Posljednja izmjena: Marko');
  });

  it('disables activation and sends no request when the quiz has zero questions', async () => {
    const element = await setup();
    const button = Array.from(element.querySelectorAll<HTMLButtonElement>('button')).find((item) =>
      item.textContent?.includes('Aktiviraj kviz'),
    )!;
    expect(button.disabled).toBe(true);
    expect(element.textContent).toContain('Dodajte najmanje jedno pitanje prije aktivacije kviza.');
    await page().activate();
    expect(activate).not.toHaveBeenCalled();
  });

  it('activates a quiz with questions using the canonical response and notification', async () => {
    const element = await setup({ ...baseQuiz, questionCount: 2 });
    await page().activate();
    fixture.detectChanges();
    expect(activate).toHaveBeenCalledWith(9);
    expect(element.textContent).toContain('Aktivan');
    expect(success).toHaveBeenCalledWith('Kviz je aktiviran.');
  });

  it('maps unsafe activation content and open-session failures without changing status', async () => {
    const element = await setup({ ...baseQuiz, questionCount: 2 });
    activate.mockRejectedValueOnce(
      new HttpErrorResponse({
        status: 409,
        error: { error: 'Quiz cannot be activated because question 91 is invalid.' },
      }),
    );
    await page().activate();
    fixture.detectChanges();
    expect(element.textContent).toContain(
      'Kviz se ne može aktivirati dok sva pitanja nisu ispravno podešena.',
    );
    expect(element.textContent).toContain('Neaktivan');

    activate.mockRejectedValueOnce(
      new HttpErrorResponse({
        status: 409,
        error: { error: 'Quiz status cannot be changed while it has an open session.' },
      }),
    );
    await page().activate();
    expect(notifyError).toHaveBeenLastCalledWith(
      'Status kviza se ne može mijenjati dok postoji otvorena sesija.',
    );
  });

  it('requires confirmation for deactivation and commits only after confirmation', async () => {
    await setup({ ...baseQuiz, isActive: true, questionCount: 2 });
    await page().confirmDeactivation();
    expect(deactivate).not.toHaveBeenCalled();
    dialogOpen.mockReturnValue({ afterClosed: () => of(true) });
    await page().confirmDeactivation();
    expect(dialogOpen).toHaveBeenLastCalledWith(
      ConfirmDialog,
      expect.objectContaining({ data: expect.objectContaining({ title: 'Deaktivirati kviz?' }) }),
    );
    expect(deactivate).toHaveBeenCalledWith(9);
    expect(success).toHaveBeenCalledWith('Kviz je deaktiviran.');
  });

  it('keeps active canonical status when deactivation is locked by an open session', async () => {
    const element = await setup({ ...baseQuiz, isActive: true, questionCount: 2 });
    dialogOpen.mockReturnValue({ afterClosed: () => of(true) });
    deactivate.mockRejectedValue(new HttpErrorResponse({ status: 409 }));
    await page().confirmDeactivation();
    fixture.detectChanges();
    expect(element.textContent).toContain('Aktivan');
    expect(notifyError).toHaveBeenCalledWith(
      'Status kviza se ne može mijenjati dok postoji otvorena sesija.',
    );
  });

  it('requires delete confirmation, handles 204 success, and maps open-session 409 safely', async () => {
    await setup();
    await page().confirmDelete();
    expect(remove).not.toHaveBeenCalled();
    dialogOpen.mockReturnValue({ afterClosed: () => of(true) });
    await page().confirmDelete();
    expect(remove).toHaveBeenCalledWith(9);
    expect(success).toHaveBeenCalledWith('Kviz je obrisan.');
    expect(navigateByUrl).toHaveBeenCalledWith('/app/quizzes');

    remove.mockRejectedValueOnce(new HttpErrorResponse({ status: 409 }));
    await page().confirmDelete();
    expect(notifyError).toHaveBeenLastCalledWith(
      'Kviz se ne može obrisati dok postoji otvorena sesija.',
    );
  });

  it('preserves URL tab state, loads canonical questions, and renders accessible non-enum cards', async () => {
    const element = await setup({ ...baseQuiz, questionCount: 1 }, 'questions', [baseQuestion]);
    expect(loadQuestions).toHaveBeenCalledWith(9);
    expect(element.querySelector('.details-tab.is-active')?.textContent).toContain('Pitanja');
    expect(element.querySelector('#questions-title')?.textContent).toContain('Pitanja (1)');
    expect(element.textContent).toContain('Koja naredba ispisuje tekst?');
    expect(element.textContent).toContain('Jedan odgovor');
    expect(element.textContent).not.toContain('SINGLE_CHOICE');
    expect(
      element.querySelector<HTMLAnchorElement>('.questions-panel-header .primary-button')?.href,
    ).toContain('/app/quizzes/9/questions/new');
    expect(element.querySelector<HTMLAnchorElement>('.question-link')?.href).toContain(
      '/app/quizzes/9/questions/11',
    );
    expect(element.querySelector('.question-menu-trigger')?.getAttribute('aria-label')).toBe(
      'Radnje za pitanje 1',
    );
  });

  it('shows restrained media thumbnails and keeps the card usable when an image fails', async () => {
    const imageQuestion = {
      ...baseQuestion,
      imagePath: '/media/question-images/9/a1b2c3.webp',
    };
    const element = await setup({ ...baseQuiz, questionCount: 1 }, 'questions', [imageQuestion]);
    const image = element.querySelector<HTMLImageElement>('.question-thumbnail img')!;
    expect(image.getAttribute('src')).toBe('/media/question-images/9/a1b2c3.webp');
    expect(image.alt).toBe('Slika uz pitanje');

    image.dispatchEvent(new Event('error'));
    fixture.detectChanges();

    expect(element.querySelector('.question-thumbnail')?.textContent).toContain(
      'Slika nije dostupna.',
    );
    expect(element.querySelector('.question-link')?.textContent).toContain(
      'Koja naredba ispisuje tekst?',
    );
  });

  it('falls back to Basic Data for an invalid tab and renders a friendly question empty state', async () => {
    const invalidTabElement = await setup(baseQuiz, 'invalid');
    expect(invalidTabElement.querySelector('.details-tab.is-active')?.textContent).toContain(
      'Osnovni podaci',
    );

    fixture.destroy();
    TestBed.resetTestingModule();
    const questionsElement = await setup(baseQuiz, 'questions');
    expect(questionsElement.textContent).toContain('Još nema pitanja.');
    expect(questionsElement.textContent).toContain(
      'Dodajte prvo pitanje kako biste mogli aktivirati kviz.',
    );
  });

  it('sends every question ID for keyboard reorder and ignores impossible boundary moves', async () => {
    const second = { ...baseQuestion, id: 20, questionOrder: 2, questionText: 'Drugo pitanje' };
    await setup({ ...baseQuiz, questionCount: 2 }, 'questions', [baseQuestion, second]);
    await page().moveQuestion(20, -1);
    expect(reorderQuestions).toHaveBeenCalledWith(9, [20, 11]);
    expect(refreshQuiz).toHaveBeenCalledWith(9);
    await page().moveQuestion(11, -1);
    expect(reorderQuestions).toHaveBeenCalledTimes(1);
  });

  it('does not report a successful reorder as failed when the Quiz metadata refresh fails', async () => {
    const second = { ...baseQuestion, id: 20, questionOrder: 2, questionText: 'Drugo pitanje' };
    await setup({ ...baseQuiz, questionCount: 2 }, 'questions', [baseQuestion, second]);
    const detailBeforeRefresh = detail();
    refreshQuiz.mockResolvedValueOnce(false);

    await page().moveQuestion(20, -1);

    expect(reorderQuestions).toHaveBeenCalledWith(9, [20, 11]);
    expect(refreshQuiz).toHaveBeenCalledWith(9);
    expect(detail()).toBe(detailBeforeRefresh);
    expect(notifyError).not.toHaveBeenCalled();
  });

  it('uses drag reorder and exposes first/last menu boundaries through real Material controls', async () => {
    const second = { ...baseQuestion, id: 20, questionOrder: 2, questionText: 'Drugo pitanje' };
    const element = await setup({ ...baseQuiz, questionCount: 2 }, 'questions', [
      baseQuestion,
      second,
    ]);
    await page().dropQuestion({ previousIndex: 1, currentIndex: 0 } as CdkDragDrop<QuestionItem[]>);
    expect(reorderQuestions).toHaveBeenCalledWith(9, [20, 11]);

    element.querySelector<HTMLButtonElement>('.question-menu-trigger')!.click();
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    const menuButtons = Array.from(
      overlayContainer.getContainerElement().querySelectorAll<HTMLButtonElement>('button'),
    );
    const moveUp = menuButtons.find((button) => button.textContent?.includes('Pomjeri gore'))!;
    const moveDown = menuButtons.find((button) => button.textContent?.includes('Pomjeri dolje'))!;
    expect(moveUp.disabled).toBe(true);
    expect(moveDown.disabled).toBe(false);

    overlayContainer
      .getContainerElement()
      .querySelector<HTMLElement>('.cdk-overlay-backdrop')
      ?.click();
    fixture.detectChanges();
    await fixture.whenStable();
    element.querySelectorAll<HTMLButtonElement>('.question-menu-trigger')[1]!.click();
    fixture.detectChanges();
    await fixture.whenStable();
    const menuContents = Array.from(
      overlayContainer.getContainerElement().querySelectorAll<HTMLElement>('.mat-mdc-menu-content'),
    );
    const lastMenuButtons = Array.from(
      menuContents.at(-1)!.querySelectorAll<HTMLButtonElement>('button'),
    );
    expect(
      lastMenuButtons.find((button) => button.textContent?.includes('Pomjeri dolje'))?.disabled,
    ).toBe(true);
  });

  it('explains and reflects automatic deactivation when deleting the final active question', async () => {
    const activeQuiz = { ...baseQuiz, isActive: true, questionCount: 1 };
    await setup(activeQuiz, 'questions', [baseQuestion]);
    dialogOpen.mockReturnValue({ afterClosed: () => of(true) });
    loadQuestions.mockImplementation(async () => {
      questions.set([]);
      questionCount.set(0);
    });
    load.mockImplementation(async () => {
      detail.set({ ...activeQuiz, isActive: false, questionCount: 0 });
    });
    await page().confirmQuestionDelete(baseQuestion);
    expect(dialogOpen).toHaveBeenLastCalledWith(
      ConfirmDialog,
      expect.objectContaining({
        data: expect.objectContaining({
          title: 'Obrisati pitanje?',
          message: expect.stringContaining(
            'Brisanjem posljednjeg pitanja kviz će biti automatski deaktiviran.',
          ),
        }),
      }),
    );
    expect(deleteQuestion).toHaveBeenCalledWith(9, 11);
    expect(loadQuestions).toHaveBeenCalledWith(9);
    expect(load).toHaveBeenCalledWith(9);
    expect(success).toHaveBeenCalledWith(
      'Pitanje je obrisano. Kviz je deaktiviran jer više nema pitanja.',
    );
  });

  it('keeps question state and uses the shared open-session message after delete or reorder locks', async () => {
    await setup({ ...baseQuiz, questionCount: 1 }, 'questions', [baseQuestion]);
    dialogOpen.mockReturnValue({ afterClosed: () => of(true) });
    const locked = new HttpErrorResponse({
      status: 409,
      error: { error: 'Quiz content cannot be changed while it has an open session.' },
    });
    deleteQuestion.mockRejectedValueOnce(locked);
    await page().confirmQuestionDelete(baseQuestion);
    expect(questions()).toEqual([baseQuestion]);
    expect(notifyError).toHaveBeenLastCalledWith(
      'Pitanja se ne mogu mijenjati dok postoji otvorena sesija.',
    );

    reorderQuestions.mockRejectedValueOnce(locked);
    const second = { ...baseQuestion, id: 20, questionOrder: 2 };
    questions.set([baseQuestion, second]);
    await page().moveQuestion(20, -1);
    expect(notifyError).toHaveBeenLastCalledWith(
      'Pitanja se ne mogu mijenjati dok postoji otvorena sesija.',
    );
  });
});
