import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, convertToParamMap, provideRouter } from '@angular/router';
import { of } from 'rxjs';

import { ResultsApiService } from '../../data-access/results-api.service';
import { SessionReport } from '../../data-access/results.models';
import { SessionReportPage } from './session-report-page';

describe('SessionReportPage', () => {
  const report: SessionReport = {
    session: {
      id: 41,
      quizId: 7,
      quiz: { title: 'PHP osnove', version: 2 },
      host: { id: 3, name: 'Nastavnik' },
      gamePin: '123456',
      status: 'FINISHED',
      questionCount: 1,
      participantCount: 2,
      removedParticipantCount: 1,
      startedAt: '2026-08-12T18:00:00+02:00',
      endedAt: '2026-08-12T18:10:00+02:00',
      createdAt: '2026-08-12T17:55:00+02:00',
      currentQuestionOrder: 1,
      currentQuestionStartedAt: null,
      currentQuestionDeadline: null,
      currentQuestionClosedAt: null,
      joinDeadline: null,
    },
    summary: {
      participantCount: 2,
      removedParticipantCount: 1,
      totalAnswerCount: 4,
      totalCorrectAnswerCount: 3,
      highestScore: 1000,
      averageScore: 750,
    },
    leaderboard: [
      {
        rank: 1,
        participantId: 11,
        participantType: 'REGISTERED',
        nickname: 'Lana',
        avatarKey: 'koda-pink',
        totalScore: 1000,
        answerCount: 1,
        correctAnswerCount: 1,
      },
    ],
    questions: [
      {
        id: 101,
        questionText: 'Koja naredba ispisuje tekst?',
        questionType: 'SINGLE_CHOICE',
        imagePath: null,
        timeLimitSeconds: 20,
        maxPoints: 1000,
        questionOrder: 1,
        options: [
          { id: 1001, optionText: 'echo', isCorrect: true, optionOrder: 1 },
          { id: 1002, optionText: 'read', isCorrect: false, optionOrder: 2 },
        ],
        stats: {
          participantCount: 2,
          answerCount: 1,
          correctAnswerCount: 1,
          incorrectAnswerCount: 0,
          unansweredCount: 1,
          averageResponseTimeMs: 1250,
        },
      },
    ],
    participants: [
      {
        participantId: 11,
        participantType: 'REGISTERED',
        student: {
          id: 9,
          firstName: 'Lana',
          lastName: 'Lanić',
          username: 'lana',
        },
        nickname: 'Lana',
        avatarKey: 'koda-pink',
        totalScore: 1000,
        isRemoved: false,
        removedAt: null,
        finalRank: 1,
        answerCount: 1,
        correctAnswerCount: 1,
        answers: [
          {
            sessionQuestionId: 101,
            questionOrder: 1,
            answered: true,
            selectedOptionIds: [1001],
            isCorrect: true,
            responseTimeMs: 1250,
            pointsAwarded: 1000,
            answeredAt: '2026-08-12T18:01:00+02:00',
          },
        ],
      },
      {
        participantId: 12,
        participantType: 'GUEST',
        student: null,
        nickname: 'Gost',
        avatarKey: 'koda-green',
        totalScore: 0,
        isRemoved: true,
        removedAt: '2026-08-12T18:02:00+02:00',
        finalRank: null,
        answerCount: 0,
        correctAnswerCount: 0,
        answers: [],
      },
    ],
  };
  let currentReport = report;
  const getSessionReport = vi.fn(() => of(currentReport));

  beforeEach(async () => {
    vi.clearAllMocks();
    currentReport = report;
    await TestBed.configureTestingModule({
      imports: [SessionReportPage],
      providers: [
        provideRouter([]),
        {
          provide: ActivatedRoute,
          useValue: { snapshot: { paramMap: convertToParamMap({ id: '41' }) } },
        },
      ],
    })
      .overrideComponent(SessionReportPage, {
        set: { providers: [{ provide: ResultsApiService, useValue: { getSessionReport } }] },
      })
      .compileComponents();
  });

  async function render() {
    const fixture = TestBed.createComponent(SessionReportPage);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    return { fixture, element: fixture.nativeElement as HTMLElement };
  }

  it('renders canonical summary, leaderboard, questions and rich participant data locally', async () => {
    const { fixture, element } = await render();
    expect(getSessionReport).toHaveBeenCalledOnce();
    expect(element.querySelector('.metric-grid')?.textContent).toContain('75%');
    expect(element.querySelector('.report-podium')?.textContent).toContain('Lana');
    expect(element.textContent).toContain('Uklonjeno tokom igre: 1');

    const tabs = Array.from(element.querySelectorAll<HTMLButtonElement>('[role="tab"]'));
    tabs.find((tab) => tab.textContent?.includes('Pitanja'))?.click();
    fixture.detectChanges();
    element.querySelector<HTMLButtonElement>('.expandable-summary')?.click();
    fixture.detectChanges();
    expect(element.querySelector('.answer-options .is-correct')?.textContent).toContain('echo');
    expect(element.querySelector('.answer-options .is-correct')?.textContent).toContain('Tačan');
    expect(element.textContent).toContain('1,25 s');

    tabs.find((tab) => tab.textContent?.includes('Učesnici'))?.click();
    fixture.detectChanges();
    expect(element.textContent).toContain('Lana Lanić');
    expect(element.textContent).toContain('Gost');
    expect(element.textContent).toContain('Uklonjen');
    expect(
      element.querySelector<HTMLAnchorElement>('.student-result-link')?.getAttribute('href'),
    ).toBe('/app/results/students/9');
    element.querySelector<HTMLButtonElement>('.expand-row')?.click();
    fixture.detectChanges();
    expect(element.querySelector('.participant-answers')?.textContent).toContain('echo');
    expect(getSessionReport).toHaveBeenCalledOnce();
    fixture.destroy();
  });

  it('shows a dash instead of dividing by zero when no answers exist', async () => {
    currentReport = {
      ...report,
      summary: { ...report.summary, totalAnswerCount: 0, totalCorrectAnswerCount: 0 },
    };
    const { fixture, element } = await render();
    const accuracyCard = Array.from(element.querySelectorAll('.metric-card')).find((card) =>
      card.textContent?.includes('Tačnost'),
    );
    expect(accuracyCard?.textContent).toContain('—');
    expect(accuracyCard?.textContent).not.toContain('NaN');
    fixture.destroy();
  });
});
