import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, convertToParamMap, ParamMap, Router } from '@angular/router';
import { BehaviorSubject } from 'rxjs';

import { AuthStore } from '../../../core/auth/auth.store';
import { StaffUser } from '../../../core/auth/auth.models';
import { NotificationService } from '../../../shared/feedback/notification.service';
import { PlayHubStore } from '../play/data-access/play-hub.store';
import { QuizLaunchService } from '../play/data-access/quiz-launch.service';
import { DashboardPage } from './dashboard-page';

describe('DashboardPage', () => {
  const user: StaffUser = {
    id: 11,
    name: 'Jovana Jović',
    email: 'jovana@example.com',
    role: 'ADMIN',
    mustChangePassword: false,
  };
  const topics = Array.from({ length: 8 }, (_, index) => ({
    id: index + 1,
    name: `Tema ${index + 1}`,
    description: null,
    quizCount: index + 1,
    createdBy: { id: 11, name: user.name },
    updatedBy: { id: 11, name: user.name },
    createdAt: '2026-01-01T10:00:00+00:00',
    updatedAt: '2026-01-01T10:00:00+00:00',
  }));
  const quiz = {
    id: 4,
    title: 'Petlje',
    description: 'Provjerite znanje kroz kratku igru.',
    questionCount: 8,
    topic: { id: 1, name: 'Programiranje' },
  };
  const queryParams = new BehaviorSubject<ParamMap>(convertToParamMap({}));
  const initialize = vi.fn().mockResolvedValue(undefined);
  const selectTopic = vi.fn().mockResolvedValue(undefined);
  const launch = vi.fn().mockResolvedValue(true);
  const navigate = vi.fn().mockResolvedValue(true);
  const error = vi.fn();
  const store = {
    topics: signal(topics),
    topicsLoading: signal(false),
    topicsError: signal(false),
    topicsExpanded: signal(false),
    selectedTopicId: signal<number | null>(null),
    visibleTopics: signal(topics),
    canToggleTopics: signal(false),
    quizzes: signal([quiz]),
    quizzesLoading: signal(false),
    quizzesLoadingMore: signal(false),
    quizzesError: signal(false),
    recentQuizzes: signal([{ ...quiz, lastPlayedAt: '2026-08-10T10:10:00+00:00' }]),
    recentLoading: signal(false),
    hasMoreQuizzes: signal(false),
    quizPagination: signal({ pageIndex: 0, pageSize: 12, totalItems: 1, totalPages: 1 }),
    initialize,
    selectTopic,
    toggleTopics: vi.fn(),
    loadTopics: vi.fn(),
    loadQuizzes: vi.fn(),
    loadRecentQuizzes: vi.fn(),
  };
  const launcher = { startingQuizId: signal<number | null>(null), launch };

  beforeEach(async () => {
    vi.clearAllMocks();
    queryParams.next(convertToParamMap({}));
    await TestBed.configureTestingModule({
      imports: [DashboardPage],
      providers: [
        { provide: AuthStore, useValue: { user: signal<StaffUser | null>(user) } },
        {
          provide: ActivatedRoute,
          useValue: { snapshot: { queryParamMap: queryParams.value }, queryParamMap: queryParams },
        },
        { provide: Router, useValue: { navigate } },
        { provide: NotificationService, useValue: { error } },
      ],
    })
      .overrideComponent(DashboardPage, {
        set: {
          providers: [
            { provide: PlayHubStore, useValue: store },
            { provide: QuizLaunchService, useValue: launcher },
          ],
        },
      })
      .compileComponents();
  });

  function render() {
    const fixture = TestBed.createComponent(DashboardPage);
    fixture.detectChanges();
    return { fixture, element: fixture.nativeElement as HTMLElement };
  }

  it('renders a colorful play hub with topics and playable quiz cards', () => {
    const { element } = render();
    expect(element.querySelector('h1')?.textContent).toContain('Pokrenite kviz i započnite igru');
    expect(element.textContent).toContain('Nedavno igrani');
    expect(element.querySelectorAll('.topic-chip:not(.topic-chip--all)')).toHaveLength(8);
    expect(element.textContent).not.toContain('Pretraži teme');
    expect(element.querySelector('clq-play-quiz-card')?.textContent).toContain('Petlje');
    expect(element.querySelector('clq-play-quiz-card button')?.textContent).toContain('Igraj');
  });

  it('keeps topic selection in query params and delegates server filtering', () => {
    const { element } = render();
    const topic = element.querySelectorAll<HTMLButtonElement>('.topic-chip')[1];
    topic.click();
    expect(navigate).toHaveBeenCalledWith(
      [],
      expect.objectContaining({ queryParams: { topicId: 1 } }),
    );
    expect(selectTopic).toHaveBeenCalledWith(1);
  });

  it('creates a session and navigates Play to the canonical lobby route', async () => {
    const { fixture, element } = render();
    element.querySelector<HTMLButtonElement>('clq-play-quiz-card button')?.click();
    await fixture.whenStable();
    expect(launch).toHaveBeenCalledWith(4);
  });
});
