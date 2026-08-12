import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, convertToParamMap, provideRouter } from '@angular/router';
import { ReplaySubject, of } from 'rxjs';

import { QuizzesApiService } from '../../../quizzes/data-access/quizzes-api.service';
import { StudentsApiService } from '../../../students/data-access/students-api.service';
import { ResultsApiService } from '../../data-access/results-api.service';
import { SessionHistoryItem, SessionHistoryQuery } from '../../data-access/results.models';
import { ResultsHomePage } from './results-home-page';

describe('ResultsHomePage', () => {
  const queryParamMap = new ReplaySubject<ReturnType<typeof convertToParamMap>>(1);
  const sessionItems: SessionHistoryItem[] = Array.from({ length: 15 }, (_, index) => ({
    id: index + 1,
    quizId: 7,
    quiz: { title: `Kviz ${index + 1}`, version: 1 },
    host: { id: 3, name: 'Nastavnik' },
    gamePin: String(100000 + index),
    status: 'FINISHED',
    questionCount: 5,
    participantCount: 8,
    removedParticipantCount: 0,
    startedAt: '2026-08-12T18:00:00+02:00',
    endedAt: '2026-08-12T18:10:00+02:00',
    createdAt: '2026-08-12T17:55:00+02:00',
  }));
  const listSessions = vi.fn((query: SessionHistoryQuery) => {
    const firstItem = query.pageIndex * query.pageSize;
    return of({
      sessions: sessionItems.slice(firstItem, firstItem + query.pageSize),
      pagination: {
        pageIndex: query.pageIndex,
        pageSize: query.pageSize,
        totalItems: sessionItems.length,
        totalPages: Math.ceil(sessionItems.length / query.pageSize),
      },
    });
  });
  const getQuizStatistics = vi.fn();
  const getStudentStatistics = vi.fn();
  const listQuizzes = vi.fn(() =>
    of({
      quizzes: [],
      pagination: { pageIndex: 0, pageSize: 10, totalItems: 0, totalPages: 0 },
    }),
  );
  const listStudents = vi.fn(() =>
    of({
      students: [],
      pagination: { pageIndex: 0, pageSize: 10, totalItems: 0, totalPages: 0 },
    }),
  );

  beforeEach(async () => {
    vi.clearAllMocks();
    await TestBed.configureTestingModule({
      imports: [ResultsHomePage],
      providers: [provideRouter([]), { provide: ActivatedRoute, useValue: { queryParamMap } }],
    })
      .overrideComponent(ResultsHomePage, {
        set: {
          providers: [
            {
              provide: ResultsApiService,
              useValue: { listSessions, getQuizStatistics, getStudentStatistics },
            },
            { provide: QuizzesApiService, useValue: { list: listQuizzes } },
            { provide: StudentsApiService, useValue: { list: listStudents } },
          ],
        },
      })
      .compileComponents();
  });

  function render(tab?: string) {
    queryParamMap.next(convertToParamMap(tab === undefined ? {} : { tab }));
    const fixture = TestBed.createComponent(ResultsHomePage);
    fixture.detectChanges();
    return { fixture, element: fixture.nativeElement as HTMLElement };
  }

  function selectedTab(element: HTMLElement): string | undefined {
    return Array.from(element.querySelectorAll<HTMLElement>('[role="tab"]'))
      .find((tab) => tab.getAttribute('aria-selected') === 'true')
      ?.textContent?.trim();
  }

  function sessionsPageSizeSelect(element: HTMLElement): HTMLSelectElement {
    const select = element.querySelector<HTMLSelectElement>(
      '.results-pagination[aria-label="Stranice sesija"] select',
    );
    if (!select) throw new Error('Sessions page-size selector was not rendered');
    return select;
  }

  it('defaults missing and invalid URL tabs safely to Sessions', () => {
    const missing = render();
    expect(selectedTab(missing.element)).toBe('Sesije');
    expect(listSessions).toHaveBeenCalledWith(
      expect.objectContaining({ pageIndex: 0, pageSize: 10, status: 'FINISHED', sort: 'RECENT' }),
    );
    missing.fixture.destroy();

    const invalid = render('unknown');
    expect(selectedTab(invalid.element)).toBe('Sesije');
    invalid.fixture.destroy();
  });

  it('restores Quiz and Student tabs from URL state without N+1 statistics calls', () => {
    const rendered = render('quizzes');
    expect(selectedTab(rendered.element)).toBe('Kvizovi');
    expect(listQuizzes).toHaveBeenCalledOnce();
    expect(getQuizStatistics).not.toHaveBeenCalled();

    queryParamMap.next(convertToParamMap({ tab: 'students' }));
    rendered.fixture.detectChanges();
    expect(selectedTab(rendered.element)).toBe('Učenici');
    expect(listStudents).toHaveBeenCalledOnce();
    expect(getStudentStatistics).not.toHaveBeenCalled();
    rendered.fixture.destroy();
  });

  it('maps the compact Session sort to the backend enum', () => {
    const rendered = render('sessions');
    const select = rendered.element.querySelector<HTMLSelectElement>('.compact-select select');
    if (!select) throw new Error('Session sort was not rendered');
    select.value = 'QUIZ_TITLE_DESC';
    select.dispatchEvent(new Event('change'));
    rendered.fixture.detectChanges();

    expect(listSessions).toHaveBeenLastCalledWith(
      expect.objectContaining({ status: 'FINISHED', sort: 'QUIZ_TITLE_DESC' }),
    );
    rendered.fixture.destroy();
  });

  it('uses the application-wide default 10 for both the selector and first request', async () => {
    const rendered = render('sessions');
    await rendered.fixture.whenStable();
    rendered.fixture.detectChanges();

    expect(sessionsPageSizeSelect(rendered.element).value).toBe('10');
    expect(listSessions).toHaveBeenCalledWith(
      expect.objectContaining({ pageIndex: 0, pageSize: 10 }),
    );
    expect(rendered.element.querySelectorAll('.sessions-table tbody tr')).toHaveLength(10);
    rendered.fixture.destroy();
  });

  it('keeps selector, server rows and metadata synchronized at pageSize 5', async () => {
    const rendered = render('sessions');
    await rendered.fixture.whenStable();
    rendered.fixture.detectChanges();
    const select = sessionsPageSizeSelect(rendered.element);

    select.value = '5';
    select.dispatchEvent(new Event('change'));
    rendered.fixture.detectChanges();
    await rendered.fixture.whenStable();
    rendered.fixture.detectChanges();

    expect(listSessions).toHaveBeenLastCalledWith(
      expect.objectContaining({ pageIndex: 0, pageSize: 5 }),
    );
    expect(select.value).toBe('5');
    expect(rendered.element.querySelectorAll('.sessions-table tbody tr')).toHaveLength(5);
    expect(rendered.element.querySelector('.results-pagination')?.textContent).toContain(
      '1–5 od 15',
    );
    expect(rendered.element.querySelector('.results-pagination')?.textContent).toContain('1 / 3');
    rendered.fixture.destroy();
  });

  it('resets pageIndex to zero before requesting a changed page size', async () => {
    const rendered = render('sessions');
    await rendered.fixture.whenStable();
    rendered.fixture.detectChanges();
    let select = sessionsPageSizeSelect(rendered.element);

    select.value = '5';
    select.dispatchEvent(new Event('change'));
    await rendered.fixture.whenStable();
    rendered.fixture.detectChanges();
    rendered.element
      .querySelector<HTMLButtonElement>('button[aria-label="Sljedeća stranica"]')
      ?.click();
    await rendered.fixture.whenStable();
    rendered.fixture.detectChanges();
    expect(listSessions).toHaveBeenLastCalledWith(
      expect.objectContaining({ pageIndex: 1, pageSize: 5 }),
    );
    expect(rendered.element.querySelector('.results-pagination')?.textContent).toContain(
      '6–10 od 15',
    );

    select = sessionsPageSizeSelect(rendered.element);
    select.value = '10';
    select.dispatchEvent(new Event('change'));
    rendered.fixture.detectChanges();
    await rendered.fixture.whenStable();
    rendered.fixture.detectChanges();

    expect(listSessions).toHaveBeenLastCalledWith(
      expect.objectContaining({ pageIndex: 0, pageSize: 10 }),
    );
    expect(sessionsPageSizeSelect(rendered.element).value).toBe('10');
    expect(rendered.element.querySelectorAll('.sessions-table tbody tr')).toHaveLength(10);
    expect(rendered.element.querySelector('.results-pagination')?.textContent).toContain(
      '1–10 od 15',
    );
    rendered.fixture.destroy();
  });
});
