import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, convertToParamMap, provideRouter } from '@angular/router';
import { of } from 'rxjs';

import { ResultsApiService } from '../../data-access/results-api.service';
import { QuizStatistics } from '../../data-access/results.models';
import { QuizStatisticsPage } from './quiz-statistics-page';

describe('QuizStatisticsPage', () => {
  const statistics: QuizStatistics = {
    quiz: { id: 7, title: 'PHP osnove', version: 2 },
    summary: {
      finishedSessionCount: 3,
      participantEntryCount: 12,
      registeredParticipationCount: 9,
      guestParticipationCount: 3,
      uniqueRegisteredStudentCount: 5,
      averageParticipantsPerSession: 4,
      totalPossibleAnswerCount: 24,
      answerCount: 20,
      correctAnswerCount: 15,
      incorrectAnswerCount: 5,
      unansweredCount: 4,
      accuracyPercentage: 75,
      answerRatePercentage: 83.33,
      highestScore: 2000,
      averageScore: 1450,
    },
    questions: [
      {
        sourceQuestionId: 101,
        questionText: 'Istorijsko pitanje',
        questionType: 'SINGLE_CHOICE',
        latestQuestionOrder: 1,
        isCurrentlyDeleted: true,
        sessionCount: 3,
        participantOpportunityCount: 12,
        answerCount: 0,
        correctAnswerCount: 0,
        incorrectAnswerCount: 0,
        unansweredCount: 12,
        accuracyPercentage: null,
        answerRatePercentage: 0,
        averageResponseTimeMs: null,
        averagePointsAwarded: null,
      },
    ],
  };
  const getQuizStatistics = vi.fn(() => of(statistics));
  const listSessions = vi.fn(() =>
    of({
      sessions: [
        {
          id: 41,
          quizId: 7,
          quiz: { title: 'PHP osnove', version: 2 },
          host: { id: 2, name: 'Nastavnik' },
          gamePin: '123456',
          status: 'FINISHED' as const,
          questionCount: 8,
          participantCount: 4,
          removedParticipantCount: 0,
          startedAt: '2026-08-12T18:00:00+02:00',
          endedAt: '2026-08-12T18:10:00+02:00',
          createdAt: '2026-08-12T17:55:00+02:00',
        },
      ],
      pagination: { pageIndex: 0, pageSize: 5, totalItems: 1, totalPages: 1 },
    }),
  );

  beforeEach(async () => {
    vi.clearAllMocks();
    await TestBed.configureTestingModule({
      imports: [QuizStatisticsPage],
      providers: [
        provideRouter([]),
        {
          provide: ActivatedRoute,
          useValue: { snapshot: { paramMap: convertToParamMap({ id: '7' }) } },
        },
      ],
    })
      .overrideComponent(QuizStatisticsPage, {
        set: {
          providers: [
            { provide: ResultsApiService, useValue: { getQuizStatistics, listSessions } },
          ],
        },
      })
      .compileComponents();
  });

  it('loads one aggregate request, keeps deleted questions and links filtered history', async () => {
    const fixture = TestBed.createComponent(QuizStatisticsPage);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;

    expect(getQuizStatistics).toHaveBeenCalledOnce();
    expect(getQuizStatistics).toHaveBeenCalledWith(7);
    expect(listSessions).toHaveBeenCalledWith(
      expect.objectContaining({ status: 'FINISHED', quizId: 7, sort: 'RECENT' }),
    );
    expect(element.textContent).toContain('Obrisano iz trenutnog kviza');
    expect(element.querySelector('.question-performance-list')?.textContent).toContain('—');
    expect(element.querySelector<HTMLAnchorElement>('.row-link')?.getAttribute('href')).toBe(
      '/app/results/sessions/41',
    );
    fixture.destroy();
  });
});
