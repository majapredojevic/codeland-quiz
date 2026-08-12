import { TestBed } from '@angular/core/testing';
import { of, Subject } from 'rxjs';

import { QuizLibraryStore, TOPICS_PAGE_SIZE } from './quiz-library.store';
import { QuizzesApiService } from './quizzes-api.service';
import {
  QuizListQuery,
  QuizzesListResponse,
  TopicItem,
  TopicsListResponse,
} from './quizzes.models';
import { TopicsApiService } from './topics-api.service';

const actor = { id: 1, name: 'Maja' };
const topic = (id: number, name = `Tema ${id}`, quizCount = 0): TopicItem => ({
  id,
  name,
  description: null,
  quizCount,
  createdBy: actor,
  updatedBy: actor,
  createdAt: '2026-01-01T00:00:00+00:00',
  updatedAt: '2026-01-01T00:00:00+00:00',
});
const quizResponse = (title: string, pageIndex = 0): QuizzesListResponse => ({
  quizzes: [
    {
      id: pageIndex + 1,
      title,
      version: 1,
      description: null,
      isActive: true,
      questionCount: 12,
      topic: null,
      createdBy: actor,
      updatedBy: actor,
      createdAt: '',
      updatedAt: '',
    },
  ],
  pagination: { pageIndex, pageSize: 10, totalItems: 1, totalPages: 1 },
});

describe('QuizLibraryStore', () => {
  let quizList: ReturnType<typeof vi.fn>;
  let topicList: ReturnType<typeof vi.fn>;
  let topicsApi: {
    list: ReturnType<typeof vi.fn>;
    get: ReturnType<typeof vi.fn>;
    create: ReturnType<typeof vi.fn>;
    update: ReturnType<typeof vi.fn>;
    delete: ReturnType<typeof vi.fn>;
  };
  let store: QuizLibraryStore;

  beforeEach(() => {
    quizList = vi.fn();
    topicList = vi.fn();
    topicsApi = {
      list: topicList,
      get: vi.fn(),
      create: vi.fn(),
      update: vi.fn(),
      delete: vi.fn(),
    };
    TestBed.configureTestingModule({
      providers: [
        { provide: QuizzesApiService, useValue: { list: quizList } },
        { provide: TopicsApiService, useValue: topicsApi },
      ],
    });
    store = TestBed.inject(QuizLibraryStore);
  });

  it('starts with the exact quiz defaults and preserves filters across each transition', () => {
    quizList.mockImplementation((query: QuizListQuery) =>
      of({
        ...quizResponse('Kviz', query.pageIndex),
        pagination: {
          pageIndex: query.pageIndex,
          pageSize: query.pageSize,
          totalItems: 50,
          totalPages: Math.ceil(50 / query.pageSize),
        },
      }),
    );
    store.loadQuizzes();
    store.setTopicId(4);
    store.setStatus('active');
    store.setSort('titleDesc');
    store.setSearch('  Scratch  ');
    store.setPageSize(20);
    store.setPage(2);

    const calls = quizList.mock.calls.map(([query]) => query as QuizListQuery);
    expect(calls[0]).toEqual({
      pageIndex: 0,
      pageSize: 10,
      search: '',
      topicId: null,
      status: 'all',
      sort: 'recent',
    });
    expect(calls.at(-1)).toEqual({
      pageIndex: 2,
      pageSize: 20,
      search: 'Scratch',
      topicId: 4,
      status: 'active',
      sort: 'titleDesc',
    });
    expect(calls.slice(1, -1).every((query) => query.pageIndex === 0)).toBe(true);
  });

  it('does not request again when the effective trimmed search is unchanged', () => {
    quizList.mockReturnValue(of(quizResponse('Kviz')));
    store.setSearch('Scratch');
    store.setSearch('  Scratch ');
    expect(quizList).toHaveBeenCalledOnce();
  });

  it('cancels an older quiz request so it cannot overwrite newer state', () => {
    const older = new Subject<QuizzesListResponse>();
    const newer = new Subject<QuizzesListResponse>();
    quizList.mockReturnValueOnce(older).mockReturnValueOnce(newer);
    store.loadQuizzes();
    store.setStatus('inactive');
    expect(older.observed).toBe(false);
    newer.next(quizResponse('Novi rezultat'));
    newer.complete();
    older.next(quizResponse('Zastarjeli rezultat'));
    expect(store.quizzes()[0]?.title).toBe('Novi rezultat');
  });

  it('loads topic pages progressively with pageSize 20 and appends unique canonical topics', async () => {
    const first: TopicsListResponse = {
      topics: [topic(1)],
      pagination: { pageIndex: 0, pageSize: 20, totalItems: 2, totalPages: 2 },
    };
    const second: TopicsListResponse = {
      topics: [topic(1, 'Tema 1 nova'), topic(2)],
      pagination: { pageIndex: 1, pageSize: 20, totalItems: 2, totalPages: 2 },
    };
    topicList.mockReturnValueOnce(of(first)).mockReturnValueOnce(of(second));
    await store.loadTopics(true);
    await store.loadTopics(false);
    expect(topicList).toHaveBeenNthCalledWith(1, 0, TOPICS_PAGE_SIZE, 'nameAsc');
    expect(topicList).toHaveBeenNthCalledWith(2, 1, TOPICS_PAGE_SIZE, 'nameAsc');
    expect(store.topics().map(({ id }) => id)).toEqual([1, 2]);
    expect(store.topics()[0]?.name).toBe('Tema 1 nova');
  });

  it('resolves an unloaded selected topic once and distinguishes a missing topic', async () => {
    topicsApi.get.mockReturnValueOnce(of({ topic: topic(44) }));
    await expect(store.resolveTopic(44)).resolves.toEqual({ kind: 'found', topic: topic(44) });
    expect(topicsApi.get).toHaveBeenCalledOnce();
  });

  it('commits create, update and delete topic results without touching quiz filters', async () => {
    const created = topic(3, 'Nova');
    const updated = topic(3, 'Nova verzija');
    topicsApi.create.mockReturnValue(of({ topic: created }));
    topicsApi.update.mockReturnValue(of({ topic: updated }));
    topicsApi.delete.mockReturnValue(of(undefined));
    await store.createTopic({ name: 'Nova', description: null });
    await store.updateTopic(3, { name: 'Nova verzija' });
    expect(store.topics()).toEqual([updated]);
    await store.deleteTopic(3);
    expect(store.topics()).toEqual([]);
    expect(store.status()).toBe('all');
  });
});
