import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';

import { QuizzesApiService } from '../../quizzes/data-access/quizzes-api.service';
import { QuizItem, TopicItem } from '../../quizzes/data-access/quizzes.models';
import { TopicsApiService } from '../../quizzes/data-access/topics-api.service';
import { PlayHubStore } from './play-hub.store';
import { QuizSessionsApiService } from './quiz-sessions-api.service';

describe('PlayHubStore', () => {
  const actor = { id: 1, name: 'Nastavnik' };
  const topics: TopicItem[] = Array.from({ length: 10 }, (_, index) => ({
    id: index + 1,
    name: `Tema ${String(index + 1).padStart(2, '0')}`,
    description: null,
    quizCount: index + 1,
    createdBy: actor,
    updatedBy: actor,
    createdAt: '2026-01-01T10:00:00+00:00',
    updatedAt: '2026-01-01T10:00:00+00:00',
  }));
  const quiz: QuizItem = {
    id: 4,
    title: 'Petlje',
    version: 1,
    description: 'Vježba petlji',
    isActive: true,
    questionCount: 8,
    topic: { id: 10, name: 'Tema 10' },
    createdBy: actor,
    updatedBy: actor,
    createdAt: '2026-01-01T10:00:00+00:00',
    updatedAt: '2026-01-01T10:00:00+00:00',
  };
  const list = vi.fn();
  const get = vi.fn();
  const topicsList = vi.fn();
  const recent = vi.fn();
  const create = vi.fn();

  beforeEach(() => {
    vi.clearAllMocks();
    topicsList.mockReturnValue(
      of({
        topics,
        pagination: { pageIndex: 0, pageSize: 20, totalItems: 10, totalPages: 1 },
      }),
    );
    list.mockReturnValue(
      of({
        quizzes: [quiz],
        pagination: { pageIndex: 0, pageSize: 12, totalItems: 1, totalPages: 1 },
      }),
    );
    recent.mockReturnValue(
      of({
        sessions: [],
        pagination: { pageIndex: 0, pageSize: 20, totalItems: 0, totalPages: 0 },
      }),
    );
    TestBed.configureTestingModule({
      providers: [
        PlayHubStore,
        { provide: QuizzesApiService, useValue: { list, get } },
        { provide: TopicsApiService, useValue: { list: topicsList } },
        { provide: QuizSessionsApiService, useValue: { listRecentFinished: recent, create } },
      ],
    });
  });

  it('shows eight real topics by default and keeps a selected later topic visible', async () => {
    const store = TestBed.inject(PlayHubStore);
    await store.initialize(null);
    expect(store.visibleTopics()).toHaveLength(8);
    expect(store.canToggleTopics()).toBe(true);

    await store.selectTopic(10);
    expect(store.visibleTopics()).toHaveLength(8);
    expect(store.visibleTopics().some(({ id }) => id === 10)).toBe(true);
    expect(list).toHaveBeenLastCalledWith(
      expect.objectContaining({ topicId: 10, status: 'active' }),
    );

    store.toggleTopics();
    expect(store.visibleTopics()).toHaveLength(10);
  });

  it('derives at most four playable recent quizzes from finished session history', async () => {
    recent.mockReturnValue(
      of({
        sessions: [
          {
            id: 20,
            quizId: 4,
            quiz: { title: 'Petlje', version: 1 },
            host: actor,
            gamePin: '123456',
            status: 'FINISHED',
            questionCount: 8,
            participantCount: 4,
            removedParticipantCount: 0,
            startedAt: '2026-08-10T10:00:00+00:00',
            endedAt: '2026-08-10T10:10:00+00:00',
            createdAt: '2026-08-10T09:59:00+00:00',
          },
        ],
        pagination: { pageIndex: 0, pageSize: 20, totalItems: 1, totalPages: 1 },
      }),
    );
    get.mockReturnValue(of({ quiz }));
    const store = TestBed.inject(PlayHubStore);
    await store.initialize(null);

    expect(store.recentQuizzes()).toEqual([
      expect.objectContaining({
        id: 4,
        title: 'Petlje',
        lastPlayedAt: '2026-08-10T10:10:00+00:00',
      }),
    ]);
  });

  it('prevents duplicate session creation and returns the canonical session id', async () => {
    create.mockReturnValue(of({ session: { id: 77 } }));
    const store = TestBed.inject(PlayHubStore);
    await expect(store.createSession(4)).resolves.toBe(77);
    expect(create).toHaveBeenCalledWith(4);
    expect(store.startingQuizId()).toBeNull();
  });
});
