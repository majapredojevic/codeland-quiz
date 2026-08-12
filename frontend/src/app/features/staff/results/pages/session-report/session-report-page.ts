import { HttpErrorResponse } from '@angular/common/http';
import { Component, inject, OnInit, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { firstValueFrom } from 'rxjs';

import { KodaAvatar } from '../../components/koda-avatar/koda-avatar';
import { ResultsApiService } from '../../data-access/results-api.service';
import { SessionReport, SessionReportParticipantAnswer } from '../../data-access/results.models';
import {
  formatPercentage,
  formatResponseTime,
  formatScore,
  formatStaffDate,
} from '../../utils/results-formatters';

type ReportTab = 'leaderboard' | 'questions' | 'participants';
type ReportError = 'invalid' | 'not-found' | 'unavailable' | 'generic' | null;

@Component({
  selector: 'clq-session-report-page',
  imports: [KodaAvatar, RouterLink],
  providers: [ResultsApiService],
  templateUrl: './session-report-page.html',
  styleUrl: './session-report-page.scss',
})
export class SessionReportPage implements OnInit {
  private readonly route = inject(ActivatedRoute);
  private readonly api = inject(ResultsApiService);

  protected readonly report = signal<SessionReport | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal<ReportError>(null);
  protected readonly activeTab = signal<ReportTab>('leaderboard');
  protected readonly expandedQuestions = signal<ReadonlySet<number>>(new Set());
  protected readonly expandedParticipants = signal<ReadonlySet<number>>(new Set());
  protected readonly imageErrors = signal<ReadonlySet<number>>(new Set());
  protected readonly formatPercentage = formatPercentage;
  protected readonly formatResponseTime = formatResponseTime;
  protected readonly formatScore = formatScore;
  protected readonly formatDate = formatStaffDate;
  protected sessionId: number | null = null;

  ngOnInit(): void {
    const rawId = this.route.snapshot.paramMap.get('id');
    const id = rawId && /^\d+$/.test(rawId) ? Number(rawId) : NaN;
    if (!Number.isSafeInteger(id) || id < 1) {
      this.loading.set(false);
      this.error.set('invalid');
      return;
    }
    this.sessionId = id;
    void this.load();
  }

  protected selectTab(tab: ReportTab): void {
    this.activeTab.set(tab);
  }

  protected retry(): void {
    void this.load();
  }

  protected sessionAccuracy(report: SessionReport): string {
    return report.summary.totalAnswerCount > 0
      ? formatPercentage(
          (report.summary.totalCorrectAnswerCount / report.summary.totalAnswerCount) * 100,
        )
      : '—';
  }

  protected toggleQuestion(id: number): void {
    this.expandedQuestions.update((current) => this.toggledSet(current, id));
  }

  protected toggleParticipant(id: number): void {
    this.expandedParticipants.update((current) => this.toggledSet(current, id));
  }

  protected markImageError(id: number): void {
    this.imageErrors.update((current) => new Set([...current, id]));
  }

  protected selectedAnswerText(
    answer: SessionReportParticipantAnswer,
    report: SessionReport,
  ): string {
    if (!answer.answered) return 'Nije odgovorio';
    const question = report.questions.find(({ id }) => id === answer.sessionQuestionId);
    const labels = (question?.options ?? [])
      .filter(({ id }) => answer.selectedOptionIds.includes(id))
      .sort((left, right) => left.optionOrder - right.optionOrder)
      .map(({ optionText }) => optionText);
    return labels.length > 0 ? labels.join(', ') : 'Odgovorio';
  }

  private async load(): Promise<void> {
    if (this.sessionId === null) return;
    this.loading.set(true);
    this.error.set(null);
    try {
      this.report.set(await firstValueFrom(this.api.getSessionReport(this.sessionId)));
    } catch (error: unknown) {
      this.report.set(null);
      this.error.set(
        error instanceof HttpErrorResponse
          ? error.status === 404
            ? 'not-found'
            : error.status === 409
              ? 'unavailable'
              : 'generic'
          : 'generic',
      );
    } finally {
      this.loading.set(false);
    }
  }

  private toggledSet(current: ReadonlySet<number>, id: number): ReadonlySet<number> {
    const next = new Set(current);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    return next;
  }
}
