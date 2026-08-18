import { TestBed } from '@angular/core/testing';
import { Router } from '@angular/router';
import { Subject, throwError } from 'rxjs';

import { NotificationService } from '../../../../shared/feedback/notification.service';
import { QuizLaunchService } from './quiz-launch.service';
import { QuizSessionsApiService } from './quiz-sessions-api.service';

describe('QuizLaunchService', () => {
  const create = vi.fn();
  const navigate = vi.fn().mockResolvedValue(true);
  const notifyError = vi.fn();

  beforeEach(() => {
    vi.clearAllMocks();
    TestBed.configureTestingModule({
      providers: [
        QuizLaunchService,
        { provide: QuizSessionsApiService, useValue: { create } },
        { provide: Router, useValue: { navigate } },
        { provide: NotificationService, useValue: { error: notifyError } },
      ],
    });
  });

  it('creates one WAITING session and navigates once during rapid repeated launch', async () => {
    const response = new Subject<{ session: { id: number } }>();
    create.mockReturnValue(response);
    const service = TestBed.inject(QuizLaunchService);

    const firstLaunch = service.launch(42);
    const repeatedLaunch = service.launch(42);

    expect(service.startingQuizId()).toBe(42);
    expect(create).toHaveBeenCalledOnce();
    await expect(repeatedLaunch).resolves.toBe(false);

    response.next({ session: { id: 77 } });
    response.complete();
    await expect(firstLaunch).resolves.toBe(true);
    expect(navigate).toHaveBeenCalledOnce();
    expect(navigate).toHaveBeenCalledWith(['/app/sessions', 77]);
    expect(service.startingQuizId()).toBeNull();
  });

  it('stays on the current page and reports the safe error when creation fails', async () => {
    create.mockReturnValue(throwError(() => new Error('backend failure')));
    const service = TestBed.inject(QuizLaunchService);

    await expect(service.launch(42)).resolves.toBe(false);

    expect(navigate).not.toHaveBeenCalled();
    expect(notifyError).toHaveBeenCalledWith(
      'Nije moguće pokrenuti kviz. Provjerite da li je aktivan i ima važeća pitanja.',
    );
    expect(service.startingQuizId()).toBeNull();
  });
});
