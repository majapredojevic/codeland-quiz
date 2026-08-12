import { HttpErrorResponse } from '@angular/common/http';
import { TestBed } from '@angular/core/testing';
import { Observable, of, Subject, throwError } from 'rxjs';

import { QuestionsApiService } from './questions-api.service';
import { QuestionItem, QuestionsListResponse } from './questions.models';
import { QuestionsStore } from './questions.store';

const question = (id: number, order: number): QuestionItem => ({
  id,
  quizId: 9,
  questionText: `Pitanje ${id}`,
  questionType: 'SINGLE_CHOICE',
  imagePath: null,
  timeLimitSeconds: 30,
  maxPoints: 1000,
  questionOrder: order,
  options: [
    { id: id * 10, optionText: 'A', isCorrect: true, optionOrder: 1 },
    { id: id * 10 + 1, optionText: 'B', isCorrect: false, optionOrder: 2 },
  ],
  createdAt: '',
  updatedAt: '',
});

describe('QuestionsStore', () => {
  let store: QuestionsStore;
  let list: ReturnType<typeof vi.fn>;
  let get: ReturnType<typeof vi.fn>;
  let create: ReturnType<typeof vi.fn>;
  let update: ReturnType<typeof vi.fn>;
  let remove: ReturnType<typeof vi.fn>;
  let reorder: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    list = vi.fn(() => of({ questions: [], questionCount: 0 }));
    get = vi.fn();
    create = vi.fn();
    update = vi.fn();
    remove = vi.fn();
    reorder = vi.fn();
    TestBed.configureTestingModule({
      providers: [
        {
          provide: QuestionsApiService,
          useValue: { list, get, create, update, delete: remove, reorder },
        },
      ],
    });
    store = TestBed.inject(QuestionsStore);
  });

  it('loads the canonical ordered list and count', async () => {
    list.mockReturnValue(of({ questions: [question(7, 1), question(11, 2)], questionCount: 2 }));
    await store.loadList(9);
    expect(store.questions().map(({ id }) => id)).toEqual([7, 11]);
    expect(store.questionCount()).toBe(2);
  });

  it('ignores stale list responses', async () => {
    const first = new Subject<QuestionsListResponse>();
    list
      .mockReturnValueOnce(first)
      .mockReturnValueOnce(of({ questions: [question(11, 1)], questionCount: 1 }));
    const oldRequest = store.loadList(9);
    await store.loadList(9);
    first.next({ questions: [question(7, 1)], questionCount: 1 });
    first.complete();
    await oldRequest;
    expect(store.questions().map(({ id }) => id)).toEqual([11]);
  });

  it('distinguishes missing quiz and missing question on direct detail load', async () => {
    get.mockReturnValueOnce(
      throwError(
        () => new HttpErrorResponse({ status: 404, error: { error: 'Question was not found.' } }),
      ),
    );
    await store.loadQuestion(9, 11);
    expect(store.detailError()).toBe('not-found');

    get.mockReturnValueOnce(
      throwError(
        () => new HttpErrorResponse({ status: 404, error: { error: 'Quiz was not found.' } }),
      ),
    );
    await store.loadQuestion(9, 11);
    expect(store.detailError()).toBe('quiz-not-found');
  });

  it('returns canonical mutation responses while delete remains bodyless', async () => {
    const canonical = question(11, 1);
    create.mockReturnValue(of({ question: canonical }));
    update.mockReturnValue(of({ question: { ...canonical, maxPoints: 2000 } }));
    remove.mockReturnValue(of(undefined));
    await expect(
      store.create(9, {
        questionText: 'Pitanje',
        questionType: 'SINGLE_CHOICE',
        imagePath: null,
        timeLimitSeconds: 30,
        maxPoints: 1000,
        options: [
          { optionText: 'A', isCorrect: true },
          { optionText: 'B', isCorrect: false },
        ],
      }),
    ).resolves.toEqual(canonical);
    await expect(store.update(9, 11, { maxPoints: 2000 })).resolves.toMatchObject({
      maxPoints: 2000,
    });
    expect(store.detail()?.maxPoints).toBe(2000);
    await expect(store.delete(9, 11)).resolves.toBeUndefined();
  });

  it('optimistically reorders the full list and replaces it with the canonical response', async () => {
    list.mockReturnValue(
      of({ questions: [question(7, 1), question(11, 2), question(20, 3)], questionCount: 3 }),
    );
    await store.loadList(9);
    reorder.mockReturnValue(
      of({ questions: [question(20, 1), question(7, 2), question(11, 3)], questionCount: 3 }),
    );
    await store.reorder(9, [20, 7, 11]);
    expect(reorder).toHaveBeenCalledWith(9, { questionIds: [20, 7, 11] });
    expect(store.questions().map(({ id }) => id)).toEqual([20, 7, 11]);
    expect(store.questions().map(({ questionOrder }) => questionOrder)).toEqual([1, 2, 3]);
  });

  it('restores and refetches canonical order after a failed reorder', async () => {
    const canonical = { questions: [question(7, 1), question(11, 2)], questionCount: 2 };
    list.mockReturnValue(of(canonical));
    await store.loadList(9);
    reorder.mockReturnValue(throwError(() => new HttpErrorResponse({ status: 409 })));
    await expect(store.reorder(9, [11, 7])).rejects.toBeInstanceOf(HttpErrorResponse);
    expect(list).toHaveBeenCalledTimes(2);
    expect(store.questions().map(({ id }) => id)).toEqual([7, 11]);
  });

  it('rejects incomplete and duplicate reorder ID arrays before HTTP', async () => {
    list.mockReturnValue(of({ questions: [question(7, 1), question(11, 2)], questionCount: 2 }));
    await store.loadList(9);
    await expect(store.reorder(9, [7, 7])).rejects.toBeInstanceOf(RangeError);
    await expect(store.reorder(9, [7])).rejects.toBeInstanceOf(RangeError);
    expect(reorder).not.toHaveBeenCalled();
  });
});
