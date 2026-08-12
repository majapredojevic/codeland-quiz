import { HttpErrorResponse } from '@angular/common/http';
import { TestBed } from '@angular/core/testing';
import { of, Subject, throwError } from 'rxjs';

import { QuizStore } from './quiz.store';
import { QuizzesApiService } from './quizzes-api.service';
import { QuizItem } from './quizzes.models';

const quiz: QuizItem = {
  id: 9,
  title: 'Petlje',
  version: 1,
  description: null,
  isActive: false,
  questionCount: 0,
  topic: null,
  createdBy: { id: 1, name: 'Maja' },
  updatedBy: { id: 1, name: 'Maja' },
  createdAt: '',
  updatedAt: '',
};

describe('QuizStore', () => {
  let api: Record<string, ReturnType<typeof vi.fn>>;
  let store: QuizStore;

  beforeEach(() => {
    api = {
      get: vi.fn(),
      create: vi.fn(),
      update: vi.fn(),
      activate: vi.fn(),
      deactivate: vi.fn(),
      delete: vi.fn(),
    };
    TestBed.configureTestingModule({ providers: [{ provide: QuizzesApiService, useValue: api }] });
    store = TestBed.inject(QuizStore);
  });

  it('loads canonical detail and identifies a backend 404 as not found', async () => {
    api['get']!.mockReturnValueOnce(of({ quiz }));
    await store.load(9);
    expect(store.detail()).toEqual(quiz);
    api['get']!.mockReturnValueOnce(throwError(() => new HttpErrorResponse({ status: 404 })));
    await store.load(10);
    expect(store.detail()).toBeNull();
    expect(store.error()).toBe('not-found');
  });

  it('returns create data without treating the new quiz as canonical detail', async () => {
    api['create']!.mockReturnValue(of({ quiz }));
    await expect(
      store.create({ title: 'Petlje', version: 1, description: null, topicId: null }),
    ).resolves.toEqual(quiz);
    expect(store.detail()).toBeNull();
  });

  it('refreshes canonical metadata without clearing the current detail', async () => {
    api['get']!.mockReturnValueOnce(of({ quiz }));
    await store.load(9);
    const refreshed = {
      ...quiz,
      updatedBy: { id: 2, name: 'Marko' },
      updatedAt: '2026-08-12T18:30:00Z',
    };
    api['get']!.mockReturnValueOnce(of({ quiz: refreshed }));

    await expect(store.refresh(9)).resolves.toBe(true);

    expect(store.detail()).toEqual(refreshed);
    expect(store.loading()).toBe(false);
    expect(store.error()).toBeNull();
  });

  it('retains detail, loading, and error state when a background refresh fails', async () => {
    api['get']!.mockReturnValueOnce(of({ quiz }));
    await store.load(9);
    const canonicalBeforeRefresh = store.detail();
    api['get']!.mockReturnValueOnce(throwError(() => new HttpErrorResponse({ status: 503 })));

    await expect(store.refresh(9)).resolves.toBe(false);

    expect(store.detail()).toBe(canonicalBeforeRefresh);
    expect(store.loading()).toBe(false);
    expect(store.error()).toBeNull();
  });

  it('does not let an older background refresh overwrite a newer canonical mutation', async () => {
    api['get']!.mockReturnValueOnce(of({ quiz }));
    await store.load(9);
    const pendingRefresh = new Subject<{ quiz: QuizItem }>();
    api['get']!.mockReturnValueOnce(pendingRefresh.asObservable());
    const refresh = store.refresh(9);
    const updated = { ...quiz, title: 'Nova verzija' };
    api['update']!.mockReturnValueOnce(of({ quiz: updated }));

    await store.update(9, { title: updated.title });
    pendingRefresh.next({ quiz: { ...quiz, updatedAt: '2026-08-12T18:30:00Z' } });
    pendingRefresh.complete();

    await expect(refresh).resolves.toBe(false);
    expect(store.detail()).toEqual(updated);
  });

  it('commits canonical update and lifecycle responses', async () => {
    api['update']!.mockReturnValue(of({ quiz: { ...quiz, title: 'Novo' } }));
    await store.update(9, { title: 'Novo' });
    expect(store.detail()?.title).toBe('Novo');
    api['activate']!.mockReturnValue(of({ quiz: { ...quiz, isActive: true } }));
    await store.activate(9);
    expect(store.detail()?.isActive).toBe(true);
    api['deactivate']!.mockReturnValue(of({ quiz }));
    await store.deactivate(9);
    expect(store.detail()?.isActive).toBe(false);
  });

  it('delegates delete without clearing canonical state before success', async () => {
    api['get']!.mockReturnValue(of({ quiz }));
    api['delete']!.mockReturnValue(of(undefined));
    await store.load(9);
    await store.delete(9);
    expect(api['delete']).toHaveBeenCalledWith(9);
    expect(store.detail()).toEqual(quiz);
  });
});
