import { HttpErrorResponse } from '@angular/common/http';
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { ActivatedRoute, convertToParamMap, provideRouter, Router } from '@angular/router';

import { NotificationService } from '../../../../../shared/feedback/notification.service';
import { QuizStore } from '../../data-access/quiz.store';
import { TopicItem } from '../../data-access/quizzes.models';
import { TopicReferenceStore } from '../../data-access/topic-reference.store';
import { QuizCreatePage } from './quiz-create-page';

const actor = { id: 1, name: 'Maja' };
const topics: TopicItem[] = [
  {
    id: 2,
    name: 'PHP',
    description: null,
    quizCount: 0,
    createdBy: actor,
    updatedBy: actor,
    createdAt: '',
    updatedAt: '',
  },
  {
    id: 5,
    name: 'Scratch',
    description: null,
    quizCount: 0,
    createdBy: actor,
    updatedBy: actor,
    createdAt: '',
    updatedAt: '',
  },
];

describe('QuizCreatePage', () => {
  let fixture: ComponentFixture<QuizCreatePage>;
  let create: ReturnType<typeof vi.fn>;
  let loadAll: ReturnType<typeof vi.fn>;
  let notifySuccess: ReturnType<typeof vi.fn>;
  let notifyInfo: ReturnType<typeof vi.fn>;
  let navigateByUrl: ReturnType<typeof vi.spyOn>;
  const topicState = signal(topics);
  const topicError = signal<string | null>(null);

  async function setup(
    topicId: string | null = null,
    availableTopics: TopicItem[] = topics,
  ): Promise<HTMLElement> {
    create = vi.fn();
    loadAll = vi.fn().mockResolvedValue(undefined);
    notifySuccess = vi.fn();
    notifyInfo = vi.fn();
    topicState.set(availableTopics);
    topicError.set(null);
    await TestBed.configureTestingModule({
      imports: [QuizCreatePage],
      providers: [
        provideRouter([]),
        {
          provide: ActivatedRoute,
          useValue: { snapshot: { queryParamMap: convertToParamMap(topicId ? { topicId } : {}) } },
        },
        { provide: QuizStore, useValue: { create } },
        {
          provide: TopicReferenceStore,
          useValue: {
            topics: topicState.asReadonly(),
            loading: signal(false).asReadonly(),
            error: topicError.asReadonly(),
            loadAll,
          },
        },
        {
          provide: NotificationService,
          useValue: { success: notifySuccess, error: vi.fn(), info: notifyInfo },
        },
      ],
    }).compileComponents();
    navigateByUrl = vi.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);
    fixture = TestBed.createComponent(QuizCreatePage);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  function setValue(element: HTMLElement, selector: string, value: string): void {
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

  function submit(element: HTMLElement): void {
    element
      .querySelector('form')!
      .dispatchEvent(new SubmitEvent('submit', { bubbles: true, cancelable: true }));
    fixture.detectChanges();
  }

  it('renders the deterministic back link, defaults version to 1, and shows topic names without raw IDs or search', async () => {
    const element = await setup();
    expect(element.querySelector<HTMLAnchorElement>('.back-link')?.getAttribute('href')).toBe(
      '/app/quizzes',
    );
    expect(element.querySelector<HTMLInputElement>('#new-quiz-version')?.value).toBe('1');
    expect(
      Array.from(element.querySelectorAll('#new-quiz-topic option')).map((option) =>
        option.textContent?.trim(),
      ),
    ).toEqual(['Odaberite temu', 'Bez teme', 'PHP', 'Scratch']);
    expect(element.textContent).not.toContain('topicId');
    expect(element.textContent).not.toContain('Pretraži teme');
  });

  it('validates required/max title, positive integer version, and description maximum', async () => {
    const element = await setup();
    setValue(element, '#new-quiz-title', ' ');
    setValue(element, '#new-quiz-version', '0');
    setValue(element, '#new-quiz-description', 'x'.repeat(5001));
    submit(element);
    expect(element.textContent).toContain('Unesite naziv kviza.');
    expect(element.textContent).toContain('Verzija mora biti cijeli broj');
    expect(element.textContent).toContain('Opis može sadržati najviše 5000 znakova.');
    expect(create).not.toHaveBeenCalled();

    setValue(element, '#new-quiz-version', '1.5');
    submit(element);
    expect(element.textContent).toContain('Verzija mora biti cijeli broj');

    setValue(element, '#new-quiz-title', 'x'.repeat(181));
    submit(element);
    expect(element.textContent).toContain('Naziv kviza može sadržati najviše 180 znakova.');
  });

  it('preselects a valid library topic by name and returns to its query-backed library context', async () => {
    const element = await setup('5');
    expect(element.querySelector<HTMLSelectElement>('#new-quiz-topic')?.value).toBe('5');
    expect(
      element.querySelector<HTMLSelectElement>('#new-quiz-topic')?.selectedOptions[0]?.textContent,
    ).toBe('Scratch');
    expect(element.querySelector<HTMLAnchorElement>('.back-link')?.getAttribute('href')).toBe(
      '/app/quizzes?topicId=5',
    );
  });

  it.each(['abc', '0', '-2', '44'])(
    'keeps the placeholder for invalid or missing topic context %s',
    async (topicId) => {
      const element = await setup(topicId);
      expect(element.querySelector<HTMLSelectElement>('#new-quiz-topic')?.value).toBe('');
      if (topicId === '44')
        expect(notifyInfo).toHaveBeenCalledWith('Odabrana tema više ne postoji.');
    },
  );

  it('normalizes create request, maps Bez teme to null, notifies, and navigates to canonical details', async () => {
    const element = await setup();
    create.mockResolvedValue({ id: 17 });
    setValue(element, '#new-quiz-title', '  Petlje  ');
    setValue(element, '#new-quiz-description', '   ');
    setValue(element, '#new-quiz-topic', 'none');
    submit(element);
    await fixture.whenStable();
    expect(create).toHaveBeenCalledWith({
      title: 'Petlje',
      version: 1,
      description: null,
      topicId: null,
    });
    expect(notifySuccess).toHaveBeenCalledWith('Kviz je uspješno kreiran.');
    expect(navigateByUrl).toHaveBeenCalledWith('/app/quizzes/17');
  });

  it('allows a topic beyond the first backend page to be selected by name and sends its internal ID', async () => {
    const allTopics = Array.from({ length: 21 }, (_, index) => ({
      ...topics[0]!,
      id: index + 1,
      name: `Tema ${String(index + 1).padStart(2, '0')}`,
    }));
    const element = await setup(null, allTopics);
    create.mockResolvedValue({ id: 21 });
    setValue(element, '#new-quiz-title', 'Kviz');
    setValue(element, '#new-quiz-topic', '21');
    submit(element);
    await fixture.whenStable();
    expect(
      element.querySelector<HTMLSelectElement>('#new-quiz-topic')?.selectedOptions[0]?.textContent,
    ).toBe('Tema 21');
    expect(create).toHaveBeenCalledWith(expect.objectContaining({ topicId: 21 }));
  });

  it('prevents duplicate submission and maps the compound uniqueness conflict', async () => {
    let reject!: (error: unknown) => void;
    const element = await setup();
    create.mockReturnValue(new Promise((_, rejection) => (reject = rejection)));
    setValue(element, '#new-quiz-title', 'Petlje');
    submit(element);
    submit(element);
    expect(create).toHaveBeenCalledOnce();
    reject(new HttpErrorResponse({ status: 409 }));
    await fixture.whenStable();
    fixture.detectChanges();
    expect(element.textContent).toContain('Kviz sa ovim nazivom i verzijom već postoji.');
  });
});
