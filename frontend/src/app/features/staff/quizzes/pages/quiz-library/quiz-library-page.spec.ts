import { HttpErrorResponse } from '@angular/common/http';
import { convertToParamMap, ActivatedRoute, Router } from '@angular/router';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MatDialog } from '@angular/material/dialog';
import { computed, signal, WritableSignal } from '@angular/core';
import { BehaviorSubject, of } from 'rxjs';

import { NotificationService } from '../../../../../shared/feedback/notification.service';
import { QuizLibraryStore } from '../../data-access/quiz-library.store';
import { QuizItem, TopicItem } from '../../data-access/quizzes.models';
import { QuizLibraryPage } from './quiz-library-page';

const actor = { id: 1, name: 'Maja' };
const scratch: TopicItem = {
  id: 4,
  name: 'Scratch',
  description: 'Osnove',
  quizCount: 8,
  createdBy: actor,
  updatedBy: actor,
  createdAt: '',
  updatedAt: '',
};
const topicItem = (id: number): TopicItem => ({
  ...scratch,
  id,
  name: `Tema ${id}`,
  quizCount: id,
});
const quizzes: QuizItem[] = [
  {
    id: 1,
    title: 'Petlje',
    version: 2,
    description: null,
    isActive: true,
    questionCount: 12,
    topic: { id: 4, name: 'Scratch' },
    createdBy: actor,
    updatedBy: actor,
    createdAt: '',
    updatedAt: '',
  },
  {
    id: 2,
    title: 'Logika',
    version: 1,
    description: null,
    isActive: false,
    questionCount: 7,
    topic: null,
    createdBy: actor,
    updatedBy: actor,
    createdAt: '',
    updatedAt: '',
  },
];

describe('QuizLibraryPage', () => {
  let fixture: ComponentFixture<QuizLibraryPage>;
  let store: Record<string, unknown>;
  let navigate: ReturnType<typeof vi.fn>;
  let loadQuizzes: ReturnType<typeof vi.fn>;
  let resolveTopic: ReturnType<typeof vi.fn>;
  let routeParams: BehaviorSubject<ReturnType<typeof convertToParamMap>>;
  let dialogOpen: ReturnType<typeof vi.fn>;
  let notificationSuccess: ReturnType<typeof vi.fn>;
  let notificationError: ReturnType<typeof vi.fn>;
  let quizState: WritableSignal<QuizItem[]>;
  let searchState: WritableSignal<string>;
  let selectedId: WritableSignal<number | null>;
  let statusState: WritableSignal<'all' | 'active' | 'inactive'>;
  let paginationState: WritableSignal<{
    pageIndex: number;
    pageSize: number;
    totalItems: number;
    totalPages: number;
  }>;
  let topicsState: WritableSignal<TopicItem[]>;
  let selectedTopicState: WritableSignal<TopicItem | null>;
  let topicPaginationState: WritableSignal<{
    pageIndex: number;
    pageSize: number;
    totalItems: number;
    totalPages: number;
  }>;

  beforeEach(async () => {
    routeParams = new BehaviorSubject(convertToParamMap({}));
    loadQuizzes = vi.fn();
    resolveTopic = vi.fn().mockResolvedValue({ kind: 'found', topic: scratch });
    navigate = vi.fn().mockResolvedValue(true);
    dialogOpen = vi.fn(() => ({ afterClosed: () => of(undefined) }));
    notificationSuccess = vi.fn();
    notificationError = vi.fn();
    topicsState = signal([scratch]);
    selectedId = signal<number | null>(null);
    selectedTopicState = signal<TopicItem | null>(null);
    quizState = signal(quizzes);
    searchState = signal('');
    statusState = signal<'all' | 'active' | 'inactive'>('all');
    paginationState = signal({ pageIndex: 0, pageSize: 10, totalItems: 2, totalPages: 1 });
    topicPaginationState = signal({
      pageIndex: 0,
      pageSize: 20,
      totalItems: 1,
      totalPages: 1,
    });
    store = {
      quizzes: quizState.asReadonly(),
      quizPagination: paginationState.asReadonly(),
      quizLoading: signal(false).asReadonly(),
      quizError: signal<string | null>(null).asReadonly(),
      search: searchState.asReadonly(),
      selectedTopicId: selectedId.asReadonly(),
      selectedTopic: selectedTopicState.asReadonly(),
      status: statusState.asReadonly(),
      sort: signal('recent').asReadonly(),
      pageIndex: signal(0).asReadonly(),
      pageSize: signal(10).asReadonly(),
      topics: topicsState.asReadonly(),
      topicPagination: topicPaginationState.asReadonly(),
      topicsLoading: signal(false).asReadonly(),
      topicsLoadingMore: signal(false).asReadonly(),
      topicsError: signal<string | null>(null).asReadonly(),
      hasMoreTopics: computed(
        () => topicPaginationState().pageIndex + 1 < topicPaginationState().totalPages,
      ),
      loadTopics: vi.fn().mockResolvedValue(undefined),
      loadQuizzes,
      setSearch: vi.fn(),
      setStatus: vi.fn(),
      setSort: vi.fn(),
      setPage: vi.fn(),
      setPageSize: vi.fn(),
      createTopic: vi.fn(),
      updateTopic: vi.fn(),
      deleteTopic: vi.fn(),
      resolveTopic,
      setTopicId: vi.fn((id: number | null) => {
        selectedId.set(id);
        selectedTopicState.set(topicsState().find((topic) => topic.id === id) ?? null);
        (loadQuizzes as () => void)();
      }),
      setResolvedTopic: vi.fn((topic: TopicItem) => selectedTopicState.set(topic)),
    };
    await TestBed.configureTestingModule({
      imports: [QuizLibraryPage],
      providers: [
        { provide: QuizLibraryStore, useValue: store },
        { provide: ActivatedRoute, useValue: { queryParamMap: routeParams } },
        { provide: Router, useValue: { navigate } },
        {
          provide: MatDialog,
          useValue: { open: dialogOpen },
        },
        {
          provide: NotificationService,
          useValue: { success: notificationSuccess, error: notificationError },
        },
      ],
    }).compileComponents();
  });

  async function render(): Promise<HTMLElement> {
    fixture = TestBed.createComponent(QuizLibraryPage);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  it('renders backend topic counts and quiz list fields without quiz navigation or actions', async () => {
    const element = await render();
    expect(element.textContent).toContain('8 kvizova');
    expect(element.textContent).toContain('Petlje');
    expect(element.textContent).toContain('Scratch');
    expect(element.textContent).toContain('12');
    expect(element.textContent).toContain('v2');
    expect(element.textContent).toContain('Bez teme');
    expect(element.querySelector('tbody a')).toBeNull();
    expect(element.querySelector('th:last-child')?.textContent).toContain('Status');
  });

  it('places Nova tema in the Topics header and quiz filters inside the Quiz section', async () => {
    const element = await render();
    const topicsSection = element.querySelector('.topics-section');
    const quizzesSection = element.querySelector('.quizzes-section');
    const createTopic = topicsSection?.querySelector<HTMLButtonElement>('.section-heading button');
    const toolbar = quizzesSection?.querySelector<HTMLElement>('.toolbar');

    expect(createTopic?.textContent).toContain('Nova tema');
    expect(toolbar?.querySelector('#quiz-search')).not.toBeNull();
    expect(toolbar?.textContent).toContain('Status');
    expect(toolbar?.textContent).toContain('Sortiranje');
    expect(element.querySelector('.page-header + .toolbar')).toBeNull();
  });

  it('adds no dead Create Quiz control while preserving query-backed selectedTopicId state', async () => {
    const element = await render();
    expect(element.querySelector('a[href="/app/quizzes/new"]')).toBeNull();
    expect(
      Array.from(element.querySelectorAll('button')).some((button) =>
        button.textContent?.includes('Novi kviz'),
      ),
    ).toBe(false);

    routeParams.next(convertToParamMap({ topicId: '4' }));
    await fixture.whenStable();
    expect(store['setTopicId']).toHaveBeenCalledWith(4);
    expect(selectedId()).toBe(4);
  });

  it('keeps table headers and renders a semantic five-column empty row', async () => {
    quizState.set([]);
    paginationState.set({ pageIndex: 0, pageSize: 10, totalItems: 0, totalPages: 0 });
    const element = await render();
    const table = element.querySelector<HTMLTableElement>('.quizzes-section table');
    const headers = table?.querySelectorAll('thead th');
    const emptyCell = table?.querySelector<HTMLTableCellElement>('tbody .empty-row td');

    expect(headers).toHaveLength(5);
    expect(Array.from(headers ?? []).map((header) => header.textContent?.trim())).toEqual([
      'Naziv',
      'Tema',
      'Pitanja',
      'Verzija',
      'Status',
    ]);
    expect(emptyCell?.colSpan).toBe(5);
    expect(emptyCell?.textContent).toContain('Nema kvizova za prikaz.');
    expect(element.querySelector('.quizzes-section .table-state')).toBeNull();
    expect(element.querySelector('.pagination-actions span')?.textContent).toContain('0 / 1');
  });

  it('uses the selected-topic and status contextual message in the empty table row', async () => {
    quizState.set([]);
    const element = await render();
    selectedId.set(4);
    statusState.set('inactive');
    fixture.detectChanges();
    expect(element.querySelector('.empty-row td')?.textContent).toContain(
      'Nema kvizova u ovoj temi sa odabranim statusom.',
    );
  });

  it('does not render the redundant selected-topic label pill', async () => {
    const element = await render();
    selectedId.set(4);
    fixture.detectChanges();
    expect(element.querySelector('.active-filter')).toBeNull();
    expect(element.textContent).not.toContain('Odabrano:');
    expect(
      element.querySelector(
        '.topic-select[aria-pressed="true"], .all-topics-card[aria-pressed="true"]',
      ),
    ).not.toBeNull();
  });

  it('shows at most eight real topics by default and does not count Svi kvizovi', async () => {
    topicsState.set(Array.from({ length: 10 }, (_, index) => topicItem(index + 1)));
    topicPaginationState.set({ pageIndex: 0, pageSize: 20, totalItems: 10, totalPages: 1 });
    const element = await render();

    expect(element.querySelectorAll('clq-topic-card')).toHaveLength(8);
    expect(element.querySelector('.all-topics-card')).not.toBeNull();
    expect(element.textContent).toContain('Prikaži sve teme');
    expect(element.textContent).not.toContain('Pretraži teme');
  });

  it('shows every real topic without an expand control when total topics is eight or fewer', async () => {
    topicsState.set(Array.from({ length: 8 }, (_, index) => topicItem(index + 1)));
    topicPaginationState.set({ pageIndex: 0, pageSize: 20, totalItems: 8, totalPages: 1 });
    const element = await render();

    expect(element.querySelectorAll('clq-topic-card')).toHaveLength(8);
    expect(element.textContent).not.toContain('Prikaži sve teme');
    expect(element.textContent).not.toContain('Prikaži manje');
  });

  it('expands all loaded topics and collapses them again', async () => {
    topicsState.set(Array.from({ length: 10 }, (_, index) => topicItem(index + 1)));
    topicPaginationState.set({ pageIndex: 0, pageSize: 20, totalItems: 10, totalPages: 1 });
    const element = await render();
    const expand = Array.from(element.querySelectorAll<HTMLButtonElement>('button')).find(
      (button) => button.textContent?.includes('Prikaži sve teme'),
    )!;

    expect(expand.getAttribute('aria-expanded')).toBe('false');
    expand.click();
    fixture.detectChanges();
    expect(element.querySelectorAll('clq-topic-card')).toHaveLength(10);
    expect(expand.textContent).toContain('Prikaži manje');
    expect(expand.getAttribute('aria-expanded')).toBe('true');

    expand.click();
    fixture.detectChanges();
    expect(element.querySelectorAll('clq-topic-card')).toHaveLength(8);
  });

  it('keeps a selected topic outside the first eight visible while collapsed', async () => {
    const topics = Array.from({ length: 10 }, (_, index) => topicItem(index + 1));
    topicsState.set(topics);
    topicPaginationState.set({ pageIndex: 0, pageSize: 20, totalItems: 10, totalPages: 1 });
    const element = await render();
    selectedId.set(10);
    selectedTopicState.set(topics[9]!);
    fixture.detectChanges();

    const cards = Array.from(element.querySelectorAll('clq-topic-card'));
    expect(cards).toHaveLength(8);
    expect(cards.some((card) => card.textContent?.includes('Tema 10'))).toBe(true);
    expect(cards.some((card) => card.textContent?.includes('Tema 8'))).toBe(false);
    expect(
      element.querySelector('clq-topic-card .topic-select[aria-pressed="true"]')?.textContent,
    ).toContain('Tema 10');
  });

  it('offers the next backend topic page only while expanded', async () => {
    topicsState.set(Array.from({ length: 20 }, (_, index) => topicItem(index + 1)));
    topicPaginationState.set({ pageIndex: 0, pageSize: 20, totalItems: 25, totalPages: 2 });
    const element = await render();
    expect(element.textContent).not.toContain('Prikaži još');

    Array.from(element.querySelectorAll<HTMLButtonElement>('button'))
      .find((button) => button.textContent?.includes('Prikaži sve teme'))!
      .click();
    fixture.detectChanges();
    const loadMore = Array.from(element.querySelectorAll<HTMLButtonElement>('button')).find(
      (button) => button.textContent?.trim() === 'Prikaži još',
    )!;
    expect(loadMore).toBeDefined();
    loadMore.click();
    expect(store['loadTopics']).toHaveBeenLastCalledWith(false);
  });

  it('starts the independent topic catalog and the initial default quiz request', async () => {
    await render();
    expect(store['loadTopics']).toHaveBeenCalledWith(true);
    expect(loadQuizzes).toHaveBeenCalledOnce();
  });

  it('restores a valid topic from URL and resolves it once when it is outside loaded folders', async () => {
    const external = { ...scratch, id: 44, name: 'Robotika' };
    resolveTopic.mockResolvedValue({ kind: 'found', topic: external });
    await render();
    routeParams.next(convertToParamMap({ topicId: '44' }));
    await fixture.whenStable();
    expect(store['setTopicId']).toHaveBeenCalledWith(44);
    expect(resolveTopic).toHaveBeenCalledWith(44);
    expect(store['setResolvedTopic']).toHaveBeenCalledWith(external);
  });

  it('clears a positive URL topic that the detail endpoint reports as missing', async () => {
    resolveTopic.mockResolvedValue({ kind: 'not-found' });
    await render();
    routeParams.next(convertToParamMap({ topicId: '44' }));
    await fixture.whenStable();
    expect(resolveTopic).toHaveBeenCalledWith(44);
    expect(navigate).toHaveBeenCalledWith(
      [],
      expect.objectContaining({ queryParams: { topicId: null }, replaceUrl: true }),
    );
  });

  it.each(['0', '-2', 'abc', '4.0'])('clears an invalid topicId value %s safely', async (value) => {
    await render();
    routeParams.next(convertToParamMap({ topicId: value }));
    await fixture.whenStable();
    expect(navigate).toHaveBeenCalledWith(
      [],
      expect.objectContaining({ queryParams: { topicId: null }, replaceUrl: true }),
    );
    expect(resolveTopic).not.toHaveBeenCalled();
  });

  it('debounces server search by 300ms', async () => {
    vi.useFakeTimers();
    try {
      const element = await render();
      const input = element.querySelector<HTMLInputElement>('#quiz-search')!;
      input.value = 'Scratch';
      input.dispatchEvent(new Event('input', { bubbles: true }));
      fixture.detectChanges();
      await vi.advanceTimersByTimeAsync(299);
      expect(store['setSearch']).not.toHaveBeenCalled();
      await vi.advanceTimersByTimeAsync(1);
      expect(store['setSearch']).toHaveBeenCalledWith('Scratch');
    } finally {
      vi.useRealTimers();
    }
  });

  it('reports exact create and edit success notifications from the reusable topic dialog', async () => {
    dialogOpen.mockReturnValue({ afterClosed: () => of(scratch) });
    await render();
    const page = fixture.componentInstance as unknown as {
      openCreateTopic(): Promise<void>;
      openEditTopic(topic: TopicItem): Promise<void>;
    };
    await page.openCreateTopic();
    await page.openEditTopic(scratch);
    expect(dialogOpen).toHaveBeenNthCalledWith(
      1,
      expect.any(Function),
      expect.objectContaining({ data: { mode: 'create' } }),
    );
    expect(dialogOpen).toHaveBeenNthCalledWith(
      2,
      expect.any(Function),
      expect.objectContaining({ data: { mode: 'edit', topic: scratch } }),
    );
    expect(notificationSuccess).toHaveBeenNthCalledWith(1, 'Tema je uspješno kreirana.');
    expect(notificationSuccess).toHaveBeenNthCalledWith(2, 'Izmjene su sačuvane.');
  });

  it('never opens confirmation or sends delete for a topic known to contain quizzes', async () => {
    await render();
    const page = fixture.componentInstance as unknown as {
      confirmDeleteTopic(topic: TopicItem): Promise<void>;
    };
    await page.confirmDeleteTopic(scratch);
    expect(dialogOpen).not.toHaveBeenCalled();
    expect(store['deleteTopic']).not.toHaveBeenCalled();
  });

  it('deletes an empty topic after confirmation and reports success', async () => {
    dialogOpen.mockReturnValue({ afterClosed: () => of(true) });
    (store['deleteTopic'] as ReturnType<typeof vi.fn>).mockResolvedValue(undefined);
    await render();
    const page = fixture.componentInstance as unknown as {
      confirmDeleteTopic(topic: TopicItem): Promise<void>;
    };
    await page.confirmDeleteTopic({ ...scratch, quizCount: 0 });
    expect(store['deleteTopic']).toHaveBeenCalledWith(4);
    expect(notificationSuccess).toHaveBeenCalledWith('Tema je obrisana.');
  });

  it('clears the selected topic state and URL when that empty topic is deleted', async () => {
    dialogOpen.mockReturnValue({ afterClosed: () => of(true) });
    (store['deleteTopic'] as ReturnType<typeof vi.fn>).mockResolvedValue(undefined);
    await render();
    (store['setTopicId'] as (id: number | null) => void)(4);
    const page = fixture.componentInstance as unknown as {
      confirmDeleteTopic(topic: TopicItem): Promise<void>;
    };
    await page.confirmDeleteTopic({ ...scratch, quizCount: 0 });
    expect(store['setTopicId']).toHaveBeenLastCalledWith(null);
    expect(navigate).toHaveBeenCalledWith(
      [],
      expect.objectContaining({ queryParams: { topicId: null } }),
    );
  });

  it('handles a defensive delete 409, reports the race, and refreshes topics', async () => {
    dialogOpen.mockReturnValue({ afterClosed: () => of(true) });
    (store['deleteTopic'] as ReturnType<typeof vi.fn>).mockRejectedValue(
      new HttpErrorResponse({ status: 409 }),
    );
    await render();
    const page = fixture.componentInstance as unknown as {
      confirmDeleteTopic(topic: TopicItem): Promise<void>;
    };
    await page.confirmDeleteTopic({ ...scratch, quizCount: 0 });
    expect(notificationError).toHaveBeenCalledWith('Tema se ne može obrisati dok sadrži kvizove.');
    expect(store['loadTopics']).toHaveBeenLastCalledWith(true);
  });
});
