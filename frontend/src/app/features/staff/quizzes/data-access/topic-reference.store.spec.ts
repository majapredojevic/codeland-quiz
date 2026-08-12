import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';

import { TopicItem } from './quizzes.models';
import { TopicReferenceStore } from './topic-reference.store';
import { TopicsApiService } from './topics-api.service';

const actor = { id: 1, name: 'Maja' };
const topic = (id: number, name: string): TopicItem => ({
  id,
  name,
  description: null,
  quizCount: 0,
  createdBy: actor,
  updatedBy: actor,
  createdAt: '',
  updatedAt: '',
});

describe('TopicReferenceStore', () => {
  it('loads every backend page sequentially, de-duplicates, and exposes name-sorted options', async () => {
    const list = vi
      .fn()
      .mockReturnValueOnce(
        of({
          topics: [topic(2, 'Scratch'), topic(1, 'Angular')],
          pagination: { pageIndex: 0, pageSize: 20, totalItems: 3, totalPages: 2 },
        }),
      )
      .mockReturnValueOnce(
        of({
          topics: [topic(2, 'Scratch'), topic(3, 'PHP')],
          pagination: { pageIndex: 1, pageSize: 20, totalItems: 3, totalPages: 2 },
        }),
      );
    TestBed.configureTestingModule({
      providers: [{ provide: TopicsApiService, useValue: { list } }],
    });
    const store = TestBed.inject(TopicReferenceStore);

    await store.loadAll();

    expect(list).toHaveBeenNthCalledWith(1, 0, 20, 'nameAsc');
    expect(list).toHaveBeenNthCalledWith(2, 1, 20, 'nameAsc');
    expect(store.topics().map(({ name }) => name)).toEqual(['Angular', 'PHP', 'Scratch']);
  });

  it('reuses loaded reference data unless a forced refresh is requested', async () => {
    const list = vi.fn().mockReturnValue(
      of({
        topics: [],
        pagination: { pageIndex: 0, pageSize: 20, totalItems: 0, totalPages: 0 },
      }),
    );
    TestBed.configureTestingModule({
      providers: [{ provide: TopicsApiService, useValue: { list } }],
    });
    const store = TestBed.inject(TopicReferenceStore);
    await store.loadAll();
    await store.loadAll();
    await store.loadAll(true);
    expect(list).toHaveBeenCalledTimes(2);
  });
});
