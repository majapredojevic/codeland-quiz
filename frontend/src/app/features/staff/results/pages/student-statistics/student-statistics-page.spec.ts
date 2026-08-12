import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, convertToParamMap, provideRouter } from '@angular/router';
import { of } from 'rxjs';

import { ResultsApiService } from '../../data-access/results-api.service';
import { StudentStatistics } from '../../data-access/results.models';
import { StudentStatisticsPage } from './student-statistics-page';

describe('StudentStatisticsPage', () => {
  const statistics: StudentStatistics = {
    student: {
      id: 9,
      firstName: 'Lana',
      lastName: 'Lanić',
      username: 'lana',
      isActive: false,
    },
    summary: {
      finishedSessionCount: 2,
      distinctQuizCount: 1,
      totalPossibleAnswerCount: 10,
      answerCount: 8,
      correctAnswerCount: 6,
      incorrectAnswerCount: 2,
      unansweredCount: 2,
      accuracyPercentage: 75,
      answerRatePercentage: 80,
      totalScore: 7450,
      averageScore: 3725,
      averageScorePercentage: 74.5,
      highestScore: 4200,
      highestScorePercentage: 84,
      averageResponseTimeMs: 1250,
      topThreeCount: 1,
      firstPlaceCount: 0,
    },
    quizzes: [
      {
        quizId: 7,
        quizTitle: 'PHP osnove',
        quizVersion: 2,
        finishedSessionCount: 2,
        totalPossibleAnswerCount: 10,
        answerCount: 8,
        correctAnswerCount: 6,
        incorrectAnswerCount: 2,
        unansweredCount: 2,
        accuracyPercentage: 75,
        answerRatePercentage: 80,
        totalScore: 7450,
        averageScore: 3725,
        averageScorePercentage: 74.5,
        highestScore: 4200,
        highestScorePercentage: 84,
        averageResponseTimeMs: 1250,
        topThreeCount: 1,
        firstPlaceCount: 0,
      },
    ],
  };
  const getStudentStatistics = vi.fn(() => of(statistics));
  const listStudentSessions = vi.fn(() =>
    of({
      sessions: [
        {
          sessionId: 41,
          quiz: { id: 7, title: 'PHP osnove', version: 2 },
          startedAt: '2026-08-12T18:00:00+02:00',
          endedAt: '2026-08-12T18:10:00+02:00',
          questionCount: 5,
          maxPossibleScore: 5000,
          totalScore: 4200,
          scorePercentage: 84,
          answerCount: 5,
          correctAnswerCount: 4,
          incorrectAnswerCount: 1,
          unansweredCount: 0,
          accuracyPercentage: 80,
          answerRatePercentage: 100,
          averageResponseTimeMs: 1250,
          participantCount: 14,
          finalRank: 3,
        },
      ],
      pagination: { pageIndex: 0, pageSize: 5, totalItems: 1, totalPages: 1 },
    }),
  );

  beforeEach(async () => {
    vi.clearAllMocks();
    await TestBed.configureTestingModule({
      imports: [StudentStatisticsPage],
      providers: [
        provideRouter([]),
        {
          provide: ActivatedRoute,
          useValue: { snapshot: { paramMap: convertToParamMap({ id: '9' }) } },
        },
      ],
    })
      .overrideComponent(StudentStatisticsPage, {
        set: {
          providers: [
            {
              provide: ResultsApiService,
              useValue: { getStudentStatistics, listStudentSessions },
            },
          ],
        },
      })
      .compileComponents();
  });

  it('loads canonical aggregates, derives the quiz filter and links related reports', async () => {
    const fixture = TestBed.createComponent(StudentStatisticsPage);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;

    expect(getStudentStatistics).toHaveBeenCalledOnce();
    expect(element.textContent).toContain('Neaktivan');
    expect(
      element.querySelector<HTMLAnchorElement>('.student-quiz-grid a')?.getAttribute('href'),
    ).toBe('/app/results/quizzes/7');
    expect(
      element.querySelector<HTMLAnchorElement>('.table-title-link')?.getAttribute('href'),
    ).toBe('/app/results/sessions/41');
    const filter = element.querySelector<HTMLSelectElement>('.section-heading select');
    expect(Array.from(filter?.options ?? []).map(({ textContent }) => textContent?.trim())).toEqual(
      ['Svi kvizovi', 'PHP osnove v2'],
    );

    if (!filter) throw new Error('Quiz filter was not rendered');
    filter.value = '7';
    filter.dispatchEvent(new Event('change'));
    fixture.detectChanges();
    expect(listStudentSessions).toHaveBeenLastCalledWith(9, 0, 5, 7);
    fixture.destroy();
  });
});
