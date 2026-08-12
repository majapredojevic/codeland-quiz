import { HttpErrorResponse } from '@angular/common/http';
import { signal, WritableSignal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { ActivatedRoute, convertToParamMap, provideRouter, Router } from '@angular/router';
import { of, Subject, throwError } from 'rxjs';

import { NotificationService } from '../../../../../shared/feedback/notification.service';
import { QuestionImagesApiService } from '../../data-access/question-images-api.service';
import { QuizStore } from '../../data-access/quiz.store';
import { QuizItem } from '../../data-access/quizzes.models';
import {
  QuestionItem,
  QuestionOptionInput,
  QuestionType,
} from '../../data-access/questions.models';
import { QuestionsStore } from '../../data-access/questions.store';
import { QuestionEditorPage } from './question-editor-page';

interface FormModel {
  questionText: string;
  questionType: QuestionType;
  timeLimitSeconds: number;
  maxPoints: number;
  options: QuestionOptionInput[];
}

interface PageAccess {
  formModel: WritableSignal<FormModel>;
  formValid(): boolean;
  saving(): boolean;
  selectedImage(): { file: File; previewUrl: string } | null;
  imageSelectionError(): string | null;
  previewSource(): string | null;
  requestError(): string | null;
  selectType(type: QuestionType): void;
  setSingleOptionCount(count: 2 | 4): void;
  selectSingleCorrect(index: number): void;
  toggleMultipleCorrect(index: number): void;
  selectImage(event: Event): void;
  removeImage(): void;
  submit(event: SubmitEvent): Promise<void>;
}

const actor = { id: 1, name: 'Maja' };
const quiz: QuizItem = {
  id: 9,
  title: 'PHP osnove',
  version: 1,
  description: null,
  isActive: false,
  questionCount: 1,
  topic: null,
  createdBy: actor,
  updatedBy: actor,
  createdAt: '',
  updatedAt: '',
};
const canonicalQuestion: QuestionItem = {
  id: 11,
  quizId: 9,
  questionText: 'Koja naredba ispisuje tekst?',
  questionType: 'SINGLE_CHOICE',
  imagePath: '/media/question-images/9/existing.png',
  timeLimitSeconds: 45,
  maxPoints: 1500,
  questionOrder: 2,
  options: [
    { id: 31, optionText: 'echo', isCorrect: true, optionOrder: 1 },
    { id: 32, optionText: 'read', isCorrect: false, optionOrder: 2 },
  ],
  createdAt: '',
  updatedAt: '',
};

describe('QuestionEditorPage', () => {
  let fixture: ComponentFixture<QuestionEditorPage>;
  let questionDetail: WritableSignal<QuestionItem | null>;
  let create: ReturnType<typeof vi.fn>;
  let update: ReturnType<typeof vi.fn>;
  let loadQuestion: ReturnType<typeof vi.fn>;
  let success: ReturnType<typeof vi.fn>;
  let notifyError: ReturnType<typeof vi.fn>;
  let navigate: ReturnType<typeof vi.spyOn>;
  let navigateByUrl: ReturnType<typeof vi.spyOn>;
  let uploadImage: ReturnType<typeof vi.fn>;
  let cleanupImage: ReturnType<typeof vi.fn>;
  let createObjectUrl: ReturnType<typeof vi.fn>;
  let revokeObjectUrl: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    let previewNumber = 0;
    createObjectUrl = vi.fn(() => `blob:question-preview-${++previewNumber}`);
    revokeObjectUrl = vi.fn();
    Object.defineProperty(URL, 'createObjectURL', {
      configurable: true,
      value: createObjectUrl,
    });
    Object.defineProperty(URL, 'revokeObjectURL', {
      configurable: true,
      value: revokeObjectUrl,
    });
  });

  async function setup(edit = false): Promise<HTMLElement> {
    questionDetail = signal<QuestionItem | null>(edit ? canonicalQuestion : null);
    create = vi.fn().mockResolvedValue(canonicalQuestion);
    update = vi.fn().mockResolvedValue(canonicalQuestion);
    loadQuestion = vi.fn().mockResolvedValue(undefined);
    success = vi.fn();
    notifyError = vi.fn();
    uploadImage = vi.fn(() =>
      of({
        image: {
          fileName: 'new-image.webp',
          path: '/media/question-images/9/new-image.webp',
        },
      }),
    );
    cleanupImage = vi.fn(() => of(undefined));
    const routeParams = edit ? { quizId: '9', questionId: '11' } : { quizId: '9' };

    await TestBed.configureTestingModule({
      imports: [QuestionEditorPage],
      providers: [
        provideRouter([]),
        {
          provide: ActivatedRoute,
          useValue: { snapshot: { paramMap: convertToParamMap(routeParams) } },
        },
        {
          provide: QuizStore,
          useValue: {
            detail: signal<QuizItem | null>(quiz).asReadonly(),
            loading: signal(false).asReadonly(),
            error: signal(null).asReadonly(),
            load: vi.fn().mockResolvedValue(undefined),
            clear: vi.fn(),
          },
        },
        {
          provide: QuestionsStore,
          useValue: {
            detail: questionDetail.asReadonly(),
            detailLoading: signal(false).asReadonly(),
            detailError: signal(null).asReadonly(),
            loadQuestion,
            create,
            update,
            clearDetail: vi.fn(),
          },
        },
        {
          provide: QuestionImagesApiService,
          useValue: { upload: uploadImage, cleanup: cleanupImage },
        },
        { provide: NotificationService, useValue: { success, error: notifyError } },
      ],
    }).compileComponents();
    const router = TestBed.inject(Router);
    navigate = vi.spyOn(router, 'navigate').mockResolvedValue(true);
    navigateByUrl = vi.spyOn(router, 'navigateByUrl').mockResolvedValue(true);
    fixture = TestBed.createComponent(QuestionEditorPage);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  function page(): PageAccess {
    return fixture.componentInstance as unknown as PageAccess;
  }

  function validSingleModel(): FormModel {
    return {
      questionText: '  Koja naredba ispisuje tekst?  ',
      questionType: 'SINGLE_CHOICE',
      timeLimitSeconds: 30,
      maxPoints: 1000,
      options: [
        { optionText: '  echo  ', isCorrect: true },
        { optionText: 'read', isCorrect: false },
      ],
    };
  }

  function imageSelection(file: File): Event {
    return {
      target: {
        files: { item: () => file },
        value: 'selected-image',
      },
    } as unknown as Event;
  }

  it('renders create defaults, deterministic back link, and an accessible optional image field', async () => {
    const element = await setup();
    expect(element.querySelector('h1')?.textContent).toContain('Novo pitanje');
    expect(element.querySelector('.back-link')?.getAttribute('href')).toContain('/app/quizzes/9');
    expect(page().formModel()).toMatchObject({
      questionText: '',
      questionType: 'SINGLE_CHOICE',
      timeLimitSeconds: 30,
      maxPoints: 1000,
    });
    expect(
      page()
        .formModel()
        .options.map(({ optionText, isCorrect }) => ({ optionText, isCorrect })),
    ).toEqual([
      { optionText: '', isCorrect: false },
      { optionText: '', isCorrect: false },
      { optionText: '', isCorrect: false },
      { optionText: '', isCorrect: false },
    ]);
    expect(element.textContent).not.toContain('imagePath');
    expect(element.textContent).not.toContain('Putanja slike');
    expect(element.textContent).toContain('Slika pitanja');
    expect(element.textContent).toContain('JPG, PNG ili WebP · najviše 5 MB');
    const fileInput = element.querySelector<HTMLInputElement>('#question-image')!;
    expect(fileInput.type).toBe('file');
    expect(fileInput.accept).toContain('image/jpeg');
    expect(
      element.querySelector<HTMLLabelElement>('label[for="question-image"]')?.textContent,
    ).toContain('Odaberite sliku pitanja');
  });

  it('accepts a supported non-empty file, creates a local preview, and does not upload on selection', async () => {
    const element = await setup();
    const file = new File(['webp bytes'], 'lekcija.webp', { type: 'image/webp' });

    page().selectImage(imageSelection(file));
    fixture.detectChanges();

    expect(page().selectedImage()?.file).toBe(file);
    expect(createObjectUrl).toHaveBeenCalledWith(file);
    expect(element.querySelector<HTMLImageElement>('.question-image-frame img')?.src).toContain(
      'blob:question-preview-1',
    );
    expect(element.querySelector<HTMLImageElement>('.question-image-frame img')?.alt).toBe(
      'Slika uz pitanje',
    );
    expect(element.textContent).toContain('lekcija.webp');
    expect(uploadImage).not.toHaveBeenCalled();
  });

  it('rejects oversized, unsupported, and empty files before upload', async () => {
    await setup();

    page().selectImage(
      imageSelection(
        new File([new Uint8Array(5 * 1024 * 1024 + 1)], 'velika.png', { type: 'image/png' }),
      ),
    );
    expect(page().imageSelectionError()).toBe('Slika može imati najviše 5 MB.');

    page().selectImage(imageSelection(new File(['gif'], 'slika.gif', { type: 'image/gif' })));
    expect(page().imageSelectionError()).toBe('Podržani formati su JPG, PNG i WebP.');

    page().selectImage(imageSelection(new File([], 'prazna.jpg', { type: 'image/jpeg' })));
    expect(page().imageSelectionError()).toBe('Odabrani fajl nije podržana slika.');
    expect(createObjectUrl).not.toHaveBeenCalled();
    expect(uploadImage).not.toHaveBeenCalled();
  });

  it('revokes object URLs when the selection changes, is removed, and the page is destroyed', async () => {
    await setup();
    page().selectImage(imageSelection(new File(['first'], 'prva.jpg', { type: 'image/jpeg' })));
    page().selectImage(imageSelection(new File(['second'], 'druga.png', { type: 'image/png' })));
    expect(revokeObjectUrl).toHaveBeenCalledWith('blob:question-preview-1');

    page().removeImage();
    expect(revokeObjectUrl).toHaveBeenCalledWith('blob:question-preview-2');

    page().selectImage(imageSelection(new File(['third'], 'treca.webp', { type: 'image/webp' })));
    fixture.destroy();
    expect(revokeObjectUrl).toHaveBeenCalledWith('blob:question-preview-3');
  });

  it('switches TRUE_FALSE to exactly two fixed labels and requires one explicit answer', async () => {
    const element = await setup();
    page().selectType('TRUE_FALSE');
    fixture.detectChanges();
    expect(
      page()
        .formModel()
        .options.map(({ optionText, isCorrect }) => ({ optionText, isCorrect })),
    ).toEqual([
      { optionText: 'Tačno', isCorrect: false },
      { optionText: 'Netačno', isCorrect: false },
    ]);
    expect(element.querySelectorAll('.answer-input')).toHaveLength(0);
    page().selectSingleCorrect(1);
    expect(
      page()
        .formModel()
        .options.map(({ isCorrect }) => isCorrect),
    ).toEqual([false, true]);
  });

  it('submits TRUE_FALSE labels in the exact required order', async () => {
    await setup();
    page().selectType('TRUE_FALSE');
    page().formModel.update((value) => ({
      ...value,
      questionText: 'PHP je programski jezik?',
    }));
    page().selectSingleCorrect(0);
    await page().submit(new SubmitEvent('submit'));
    expect(create.mock.calls[0][1].options).toEqual([
      { optionText: 'Tačno', isCorrect: true },
      { optionText: 'Netačno', isCorrect: false },
    ]);
  });

  it('preserves and truncates single-choice options across 4 to 2 to 4 changes', async () => {
    await setup();
    page().formModel.update((value) => ({
      ...value,
      options: [
        { optionText: 'A', isCorrect: false },
        { optionText: 'B', isCorrect: false },
        { optionText: 'C', isCorrect: true },
        { optionText: 'D', isCorrect: false },
      ],
    }));
    page().setSingleOptionCount(2);
    expect(page().formModel().options).toEqual([
      { optionText: 'A', isCorrect: false },
      { optionText: 'B', isCorrect: false },
    ]);
    page().setSingleOptionCount(4);
    expect(
      page()
        .formModel()
        .options.map(({ optionText }) => optionText),
    ).toEqual(['A', 'B', '', '']);
  });

  it('preserves texts when switching to multiple choice and prevents a fourth correct answer', async () => {
    await setup();
    page().formModel.set({
      ...validSingleModel(),
      options: [
        { optionText: 'A', isCorrect: true },
        { optionText: 'B', isCorrect: false },
        { optionText: 'C', isCorrect: false },
        { optionText: 'D', isCorrect: false },
      ],
    });
    page().selectType('MULTIPLE_CHOICE');
    expect(
      page()
        .formModel()
        .options.map(({ optionText }) => optionText),
    ).toEqual(['A', 'B', 'C', 'D']);
    page().toggleMultipleCorrect(0);
    page().toggleMultipleCorrect(1);
    page().toggleMultipleCorrect(2);
    page().toggleMultipleCorrect(3);
    expect(
      page()
        .formModel()
        .options.filter(({ isCorrect }) => isCorrect),
    ).toHaveLength(3);
  });

  it('rejects duplicate normalized option texts and invalid integer ranges', async () => {
    await setup();
    page().formModel.set({
      questionText: 'Pitanje',
      questionType: 'SINGLE_CHOICE',
      timeLimitSeconds: 30.5,
      maxPoints: 10001,
      options: [
        { optionText: 'PHP', isCorrect: true },
        { optionText: ' php ', isCorrect: false },
      ],
    });
    fixture.detectChanges();
    expect(page().formValid()).toBe(false);
  });

  it('enforces required and maximum text lengths and every type-specific correctness count', async () => {
    await setup();
    expect(page().formValid()).toBe(false);

    page().formModel.set({ ...validSingleModel(), questionText: 'x'.repeat(1001) });
    expect(page().formValid()).toBe(false);
    page().formModel.set({
      ...validSingleModel(),
      options: [
        { optionText: 'x'.repeat(256), isCorrect: true },
        { optionText: 'B', isCorrect: false },
      ],
    });
    expect(page().formValid()).toBe(false);

    page().formModel.set(validSingleModel());
    expect(page().formValid()).toBe(true);
    page().formModel.update((value) => ({
      ...value,
      options: value.options.map((option) => ({ ...option, isCorrect: false })),
    }));
    expect(page().formValid()).toBe(false);

    page().selectType('MULTIPLE_CHOICE');
    page().formModel.update((value) => ({
      ...value,
      questionText: 'Pitanje',
      options: ['A', 'B', 'C', 'D'].map((optionText) => ({
        optionText,
        isCorrect: false,
      })),
    }));
    page().toggleMultipleCorrect(0);
    expect(page().formValid()).toBe(false);
    page().toggleMultipleCorrect(1);
    expect(page().formValid()).toBe(true);
    page().toggleMultipleCorrect(2);
    expect(page().formValid()).toBe(true);
  });

  it('normalizes create, sends imagePath null without order or IDs, prevents duplicate submit, and returns to Questions', async () => {
    await setup();
    page().formModel.set(validSingleModel());
    fixture.detectChanges();
    let resolveCreate!: (question: QuestionItem) => void;
    create.mockReturnValueOnce(new Promise<QuestionItem>((resolve) => (resolveCreate = resolve)));
    const first = page().submit(new SubmitEvent('submit'));
    await page().submit(new SubmitEvent('submit'));
    expect(create).toHaveBeenCalledTimes(1);
    expect(create).toHaveBeenCalledWith(9, {
      questionText: 'Koja naredba ispisuje tekst?',
      questionType: 'SINGLE_CHOICE',
      imagePath: null,
      timeLimitSeconds: 30,
      maxPoints: 1000,
      options: [
        { optionText: 'echo', isCorrect: true },
        { optionText: 'read', isCorrect: false },
      ],
    });
    const request = create.mock.calls[0][1];
    expect(request).not.toHaveProperty('questionOrder');
    expect(request.options[0]).not.toHaveProperty('id');
    resolveCreate(canonicalQuestion);
    await first;
    expect(success).toHaveBeenCalledWith('Pitanje je uspješno dodano.');
    expect(navigate).toHaveBeenCalledWith(['/app/quizzes', 9], {
      queryParams: { tab: 'questions' },
    });
  });

  it('uploads a selected image only on submit, then creates with the returned managed path', async () => {
    await setup();
    page().formModel.set(validSingleModel());
    const file = new File(['jpeg bytes'], 'pitanje.jpg', { type: 'image/jpeg' });
    page().selectImage(imageSelection(file));

    expect(uploadImage).not.toHaveBeenCalled();
    expect(create).not.toHaveBeenCalled();

    await page().submit(new SubmitEvent('submit'));

    expect(uploadImage).toHaveBeenCalledWith(9, file);
    expect(create).toHaveBeenCalledWith(
      9,
      expect.objectContaining({ imagePath: '/media/question-images/9/new-image.webp' }),
    );
    expect(uploadImage.mock.invocationCallOrder[0]).toBeLessThan(
      create.mock.invocationCallOrder[0],
    );
    expect(cleanupImage).not.toHaveBeenCalled();
  });

  it('freezes the validated payload and disables every editor interaction while upload is pending', async () => {
    const element = await setup();
    const original = validSingleModel();
    page().formModel.set(original);
    const file = new File(['jpeg bytes'], 'pitanje.jpg', { type: 'image/jpeg' });
    page().selectImage(imageSelection(file));
    const pendingUpload = new Subject<{
      image: { fileName: string; path: string };
    }>();
    uploadImage.mockReturnValueOnce(pendingUpload.asObservable());

    const submission = page().submit(new SubmitEvent('submit'));
    fixture.detectChanges();

    expect(page().saving()).toBe(true);
    const controls = Array.from(
      element.querySelectorAll<HTMLButtonElement | HTMLInputElement | HTMLTextAreaElement>(
        'form button, form input, form textarea',
      ),
    );
    expect(controls.length).toBeGreaterThan(0);
    expect(controls.every(({ disabled }) => disabled)).toBe(true);

    const back = element.querySelector<HTMLAnchorElement>('.back-link')!;
    const cancel = element.querySelector<HTMLAnchorElement>('.form-actions .secondary-button')!;
    for (const link of [back, cancel]) {
      expect(link.getAttribute('href')).toBeNull();
      expect(link.getAttribute('aria-disabled')).toBe('true');
      expect(link.tabIndex).toBe(-1);
      link.click();
    }
    expect(navigateByUrl).not.toHaveBeenCalled();

    page().formModel.set({
      questionText: '',
      questionType: 'MULTIPLE_CHOICE',
      timeLimitSeconds: 1,
      maxPoints: 0,
      options: [],
    });
    page().removeImage();
    expect(page().selectedImage()?.file).toBe(file);

    pendingUpload.next({
      image: {
        fileName: 'new-image.webp',
        path: '/media/question-images/9/new-image.webp',
      },
    });
    pendingUpload.complete();
    await submission;

    expect(create).toHaveBeenCalledWith(9, {
      questionText: 'Koja naredba ispisuje tekst?',
      questionType: 'SINGLE_CHOICE',
      imagePath: '/media/question-images/9/new-image.webp',
      timeLimitSeconds: 30,
      maxPoints: 1000,
      options: [
        { optionText: 'echo', isCorrect: true },
        { optionText: 'read', isCorrect: false },
      ],
    });
  });

  it('does not create after external navigation destroys the editor during upload', async () => {
    await setup();
    page().formModel.set(validSingleModel());
    page().selectImage(
      imageSelection(new File(['webp bytes'], 'pitanje.webp', { type: 'image/webp' })),
    );
    const pendingUpload = new Subject<{
      image: { fileName: string; path: string };
    }>();
    uploadImage.mockReturnValueOnce(pendingUpload.asObservable());

    const submission = page().submit(new SubmitEvent('submit'));
    fixture.destroy();
    pendingUpload.next({
      image: {
        fileName: 'new-image.webp',
        path: '/media/question-images/9/new-image.webp',
      },
    });
    pendingUpload.complete();
    await submission;

    expect(create).not.toHaveBeenCalled();
    expect(cleanupImage).toHaveBeenCalledWith(9, 'new-image.webp');
    expect(navigate).not.toHaveBeenCalled();
  });

  it('best-effort cleans a new orphan when Question creation fails', async () => {
    await setup();
    page().formModel.set(validSingleModel());
    page().selectImage(
      imageSelection(new File(['png bytes'], 'pitanje.png', { type: 'image/png' })),
    );
    create.mockRejectedValue(new HttpErrorResponse({ status: 500 }));

    await page().submit(new SubmitEvent('submit'));

    expect(cleanupImage).toHaveBeenCalledWith(9, 'new-image.webp');
    expect(page().requestError()).toBe('Nije moguće dodati pitanje.');
    expect(navigate).not.toHaveBeenCalled();
  });

  it('preserves the original Question save error when orphan cleanup also fails', async () => {
    await setup();
    page().formModel.set(validSingleModel());
    page().selectImage(
      imageSelection(new File(['webp bytes'], 'pitanje.webp', { type: 'image/webp' })),
    );
    create.mockRejectedValue(
      new HttpErrorResponse({
        status: 400,
        error: { error: 'Question text must not exceed 1000 characters.' },
      }),
    );
    cleanupImage.mockReturnValue(throwError(() => new HttpErrorResponse({ status: 500 })));

    await page().submit(new SubmitEvent('submit'));

    expect(cleanupImage).toHaveBeenCalledWith(9, 'new-image.webp');
    expect(page().requestError()).toBe('Tekst pitanja može imati najviše 1000 znakova.');
  });

  it('loads canonical edit data directly and omits unchanged imagePath from a partial PATCH', async () => {
    const element = await setup(true);
    expect(loadQuestion).toHaveBeenCalledWith(9, 11);
    expect(element.querySelector('h1')?.textContent).toContain('Uredi pitanje');
    expect(element.querySelector('.page-header')?.textContent).toContain('Pitanje 2 · PHP osnove');
    page().formModel.update((value) => ({ ...value, maxPoints: 2000 }));
    fixture.detectChanges();
    await page().submit(new SubmitEvent('submit'));
    expect(update).toHaveBeenCalledWith(9, 11, { maxPoints: 2000 });
    expect(update.mock.calls[0][2]).not.toHaveProperty('imagePath');
    expect(update.mock.calls[0][2]).not.toHaveProperty('options');
    expect(success).toHaveBeenCalledWith('Izmjene su sačuvane.');
  });

  it('renders the saved media preview and a subtle fallback when it cannot load', async () => {
    const element = await setup(true);
    const image = element.querySelector<HTMLImageElement>('.question-image-frame img')!;
    expect(image.getAttribute('src')).toBe('/media/question-images/9/existing.png');
    expect(image.alt).toBe('Slika uz pitanje');

    image.dispatchEvent(new Event('error'));
    fixture.detectChanges();

    expect(element.querySelector('.question-image-fallback')?.textContent).toContain(
      'Slika nije dostupna.',
    );
    expect(element.textContent).toContain('Promijeni sliku');
    expect(element.textContent).toContain('Ukloni sliku');
  });

  it('removes an existing image by PATCHing null without deleting the old asset', async () => {
    await setup(true);
    page().removeImage();

    await page().submit(new SubmitEvent('submit'));

    expect(update).toHaveBeenCalledWith(9, 11, { imagePath: null });
    expect(uploadImage).not.toHaveBeenCalled();
    expect(cleanupImage).not.toHaveBeenCalled();
  });

  it('replaces an existing image with a new upload and never deletes the old image', async () => {
    await setup(true);
    const replacement = new File(['replacement'], 'nova.png', { type: 'image/png' });
    page().selectImage(imageSelection(replacement));

    await page().submit(new SubmitEvent('submit'));

    expect(uploadImage).toHaveBeenCalledWith(9, replacement);
    expect(update).toHaveBeenCalledWith(9, 11, {
      imagePath: '/media/question-images/9/new-image.webp',
    });
    expect(uploadImage.mock.invocationCallOrder[0]).toBeLessThan(
      update.mock.invocationCallOrder[0],
    );
    expect(cleanupImage).not.toHaveBeenCalled();
  });

  it('cleans only the new replacement when PATCH fails, leaving the old image untouched', async () => {
    await setup(true);
    page().selectImage(
      imageSelection(new File(['replacement'], 'nova.webp', { type: 'image/webp' })),
    );
    update.mockRejectedValue(new HttpErrorResponse({ status: 500 }));

    await page().submit(new SubmitEvent('submit'));

    expect(cleanupImage).toHaveBeenCalledTimes(1);
    expect(cleanupImage).toHaveBeenCalledWith(9, 'new-image.webp');
    expect(JSON.stringify(cleanupImage.mock.calls)).not.toContain('existing.png');
  });

  it('does not clean a referenced upload if navigation fails after a successful mutation', async () => {
    await setup();
    page().formModel.set(validSingleModel());
    page().selectImage(
      imageSelection(new File(['replacement'], 'nova.webp', { type: 'image/webp' })),
    );
    navigate.mockRejectedValueOnce(new Error('Navigation failed'));

    await page().submit(new SubmitEvent('submit'));

    expect(create).toHaveBeenCalledTimes(1);
    expect(cleanupImage).not.toHaveBeenCalled();
    expect(page().requestError()).toBeNull();
  });

  it('does not PATCH an unchanged canonical edit form', async () => {
    await setup(true);
    await page().submit(new SubmitEvent('submit'));
    expect(update).not.toHaveBeenCalled();
  });

  it('sends complete option inputs when answers change and never sends option IDs', async () => {
    await setup(true);
    page().formModel.update((value) => ({
      ...value,
      options: value.options.map((option, index) =>
        index === 1 ? { ...option, optionText: 'print' } : option,
      ),
    }));
    fixture.detectChanges();
    await page().submit(new SubmitEvent('submit'));
    const request = update.mock.calls[0][2];
    expect(request.options).toEqual([
      { optionText: 'echo', isCorrect: true },
      { optionText: 'print', isCorrect: false },
    ]);
    expect(request.options[0]).not.toHaveProperty('id');
    expect(request.options[0]).not.toHaveProperty('optionOrder');
  });

  it('maps create and update open-session locks to one consistent notification', async () => {
    await setup();
    page().formModel.set(validSingleModel());
    create.mockRejectedValue(
      new HttpErrorResponse({
        status: 409,
        error: { error: 'Quiz content cannot be changed while it has an open session.' },
      }),
    );
    await page().submit(new SubmitEvent('submit'));
    expect(notifyError).toHaveBeenCalledWith(
      'Pitanja se ne mogu mijenjati dok postoji otvorena sesija.',
    );
  });

  it('maps upload size, invalid-content, and open-session errors before any Question mutation', async () => {
    await setup();
    page().formModel.set(validSingleModel());
    page().selectImage(imageSelection(new File(['valid'], 'slika.jpg', { type: 'image/jpeg' })));
    uploadImage.mockReturnValueOnce(
      throwError(() => new HttpErrorResponse({ status: 413, error: { error: 'Too large.' } })),
    );

    await page().submit(new SubmitEvent('submit'));
    expect(page().requestError()).toBe('Slika može imati najviše 5 MB.');
    expect(create).not.toHaveBeenCalled();
    expect(cleanupImage).not.toHaveBeenCalled();

    uploadImage.mockReturnValueOnce(
      throwError(
        () =>
          new HttpErrorResponse({
            status: 400,
            error: { error: 'Uploaded file is not a supported image.' },
          }),
      ),
    );
    await page().submit(new SubmitEvent('submit'));
    expect(page().requestError()).toBe('Odabrani fajl nije podržana slika.');

    uploadImage.mockReturnValueOnce(
      throwError(
        () =>
          new HttpErrorResponse({
            status: 409,
            error: { error: 'Quiz content cannot be changed while it has an open session.' },
          }),
      ),
    );
    await page().submit(new SubmitEvent('submit'));
    expect(page().requestError()).toBe('Pitanja se ne mogu mijenjati dok postoji otvorena sesija.');
    expect(notifyError).toHaveBeenCalledWith(
      'Pitanja se ne mogu mijenjati dok postoji otvorena sesija.',
    );
    expect(create).not.toHaveBeenCalled();
  });

  it('maps the same open-session lock for edit without changing canonical question state', async () => {
    await setup(true);
    page().formModel.update((value) => ({ ...value, maxPoints: 2000 }));
    update.mockRejectedValue(
      new HttpErrorResponse({
        status: 409,
        error: { error: 'Quiz content cannot be changed while it has an open session.' },
      }),
    );
    await page().submit(new SubmitEvent('submit'));
    expect(questionDetail()).toEqual(canonicalQuestion);
    expect(notifyError).toHaveBeenCalledWith(
      'Pitanja se ne mogu mijenjati dok postoji otvorena sesija.',
    );
  });
});
