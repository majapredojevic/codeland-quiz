import { NgOptimizedImage } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { Component, ElementRef, OnInit, computed, effect, inject, signal, viewChild } from '@angular/core';
import { FormControl, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { firstValueFrom } from 'rxjs';

import { KodaAvatar } from '../../../shared/game/koda-avatar/koda-avatar';
import { ParticipantSessionStore } from './data-access/participant-session.store';
import { PlayerApiService } from './data-access/player-api.service';
import { PlayerGameStore } from './data-access/player-game.store';
import {
  GamePreviewResponse,
  JoinGameRequest,
  ParticipantType,
  PlayerQuestionOption,
} from './data-access/player.models';

type JoinStep = 'identity' | 'details' | 'avatar';
type JoinErrorKind = 'nickname' | 'username' | 'already-joined' | 'closed' | 'generic' | null;

@Component({
  selector: 'clq-player-page',
  imports: [KodaAvatar, NgOptimizedImage, ReactiveFormsModule, RouterLink],
  providers: [ParticipantSessionStore, PlayerApiService, PlayerGameStore],
  templateUrl: './player-page.html',
  styleUrl: './player-page.scss',
})
export class PlayerPage implements OnInit {
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly api = inject(PlayerApiService);
  private readonly stepHeading = viewChild<ElementRef<HTMLElement>>('stepHeading');
  private lastQuestionId: number | null = null;

  protected readonly store = inject(PlayerGameStore);
  protected readonly preview = signal<GamePreviewResponse | null>(null);
  protected readonly previewLoading = signal(false);
  protected readonly previewError = signal<string | null>(null);
  protected readonly step = signal<JoinStep>('identity');
  protected readonly participantType = signal<ParticipantType | null>(null);
  protected readonly selectedAvatarKey = signal<string | null>(null);
  protected readonly joining = signal(false);
  protected readonly joinErrorKind = signal<JoinErrorKind>(null);
  protected readonly joinError = signal<string | null>(null);
  protected readonly imageUnavailable = signal(false);
  protected readonly usernameControl = new FormControl('', {
    nonNullable: true,
    validators: [
      Validators.required,
      Validators.minLength(3),
      Validators.maxLength(80),
      Validators.pattern(/^[a-z0-9](?:[a-z0-9._-]{1,78}[a-z0-9])$/),
    ],
  });
  protected readonly nicknameControl = new FormControl('', {
    nonNullable: true,
    validators: [Validators.required, Validators.minLength(2), Validators.maxLength(30)],
  });
  protected readonly showsQuestion = computed(() => {
    const phase = this.store.phase();
    return (
      this.store.question() !== null &&
      (phase === 'QUESTION_OPEN' ||
        phase === 'ANSWER_SUBMITTED' ||
        phase === 'QUESTION_RESULT' ||
        phase === 'BETWEEN_QUESTIONS' ||
        phase === 'RECONNECTING')
    );
  });

  protected gamePin = '';

  constructor() {
    effect(() => {
      const questionId = this.store.question()?.id ?? null;
      if (questionId !== this.lastQuestionId) {
        this.lastQuestionId = questionId;
        this.imageUnavailable.set(false);
      }
    });
  }

  ngOnInit(): void {
    const rawPin = this.route.snapshot.paramMap.get('gamePin') ?? '';
    this.gamePin = rawPin.replace(/\D/g, '').slice(0, 6);

    if (!/^\d{6}$/.test(this.gamePin)) {
      this.previewError.set('PIN igre mora imati 6 cifara.');
      return;
    }

    if (this.store.resume(this.gamePin)) return;
    void this.loadPreview();
  }

  protected chooseParticipantType(type: ParticipantType): void {
    this.participantType.set(type);
    if (type === 'GUEST') this.usernameControl.setValue('');
    this.clearJoinError();
    this.goToStep('details');
  }

  protected continueToAvatars(): void {
    this.nicknameControl.setValue(this.normalizedNickname(this.nicknameControl.value));
    this.nicknameControl.markAsTouched();

    if (this.participantType() === 'REGISTERED') {
      this.usernameControl.setValue(this.usernameControl.value.trim().toLowerCase());
      this.usernameControl.markAsTouched();
      if (this.usernameControl.invalid) return;
    }

    if (this.nicknameControl.invalid) return;
    this.clearJoinError();
    this.goToStep('avatar');
  }

  protected selectAvatar(avatarKey: string): void {
    this.selectedAvatarKey.set(avatarKey);
    this.clearJoinError();
  }

  protected async joinGame(): Promise<void> {
    const participantType = this.participantType();
    const avatarKey = this.selectedAvatarKey();
    if (participantType === null || avatarKey === null || this.joining()) return;

    const nickname = this.normalizedNickname(this.nicknameControl.value);
    const request: JoinGameRequest =
      participantType === 'REGISTERED'
        ? {
            participantType,
            gamePin: this.gamePin,
            username: this.usernameControl.value.trim().toLowerCase(),
            nickname,
            avatarKey,
          }
        : {
            participantType,
            gamePin: this.gamePin,
            nickname,
            avatarKey,
          };

    this.joining.set(true);
    this.clearJoinError();
    try {
      const response = await firstValueFrom(this.api.join(request));
      this.store.begin(response);
    } catch (error: unknown) {
      this.applyJoinError(error);
    } finally {
      this.joining.set(false);
    }
  }

  protected goBack(): void {
    if (this.step() === 'avatar') this.goToStep('details');
    else if (this.step() === 'details') this.goToStep('identity');
  }

  protected useGuestInstead(): void {
    this.participantType.set('GUEST');
    this.usernameControl.setValue('');
    this.clearJoinError();
  }

  protected answerOption(option: PlayerQuestionOption): void {
    if (this.store.isMultipleChoice()) this.store.toggleMultipleOption(option.id);
    else this.store.submitSingleOption(option.id);
  }

  protected isSelected(optionId: number): boolean {
    return this.store.selectedOptionIds().includes(optionId);
  }

  protected isCorrectOption(optionId: number): boolean {
    return this.store.correctOptionIds().includes(optionId);
  }

  protected isWrongSelection(optionId: number): boolean {
    return (
      this.store.answerResult() !== null &&
      this.isSelected(optionId) &&
      !this.isCorrectOption(optionId)
    );
  }

  protected optionLabel(index: number): string {
    return String.fromCharCode(65 + index);
  }

  protected feedbackTitle(): string {
    const result = this.store.answerResult();
    if (result === null || !result.answered) return 'Vrijeme je isteklo.';
    return result.isCorrect ? 'Tačno! 🎉' : 'Nije tačno — idemo dalje! 💪';
  }

  protected finalHeading(): string {
    const rank = this.store.finalResult()?.rank;
    if (rank === 1) return 'BRAVOOOO!';
    if (rank === 2) return 'Sjajna igra!';
    if (rank === 3) return 'Bravo!';
    return 'Odlično odigrano! 🎉';
  }

  protected finalLabel(): string {
    const rank = this.store.finalResult()?.rank;
    if (rank === 1) return 'ŠAMPION KVIZA';
    if (rank !== undefined && rank <= 3) return `${rank}. MJESTO`;
    return 'Tvoje mjesto';
  }

  protected formatScore(score: number): string {
    return new Intl.NumberFormat('bs-BA').format(score);
  }

  protected avatarAlt(index: number): string {
    return `Koda avatar ${index + 1}`;
  }

  protected markImageUnavailable(): void {
    this.imageUnavailable.set(true);
  }

  protected startOver(): void {
    this.store.clearParticipantSession();
    void this.router.navigate(['/'], { replaceUrl: true });
  }

  private async loadPreview(): Promise<void> {
    this.previewLoading.set(true);
    this.previewError.set(null);
    try {
      const preview = await firstValueFrom(this.api.preview(this.gamePin));
      if (!preview.session.canJoin) {
        this.previewError.set('Igra je već počela i više se nije moguće pridružiti.');
        return;
      }
      this.preview.set(preview);
    } catch {
      this.previewError.set('Ne možemo pronaći igru sa ovim PIN-om.');
    } finally {
      this.previewLoading.set(false);
    }
  }

  private applyJoinError(error: unknown): void {
    const backendMessage = this.backendErrorMessage(error);

    if (backendMessage.includes('nickname')) {
      this.joinErrorKind.set('nickname');
      this.joinError.set('Ovaj nadimak je već zauzet. Izaberi drugi.');
      this.goToStep('details');
      return;
    }

    if (error instanceof HttpErrorResponse && error.status === 404) {
      this.joinErrorKind.set('username');
      this.joinError.set('Korisničko ime nije pronađeno.');
      this.goToStep('details');
      return;
    }

    if (backendMessage.includes('already joined')) {
      this.joinErrorKind.set('already-joined');
      this.joinError.set('Već si pridružen/a ovoj igri.');
      return;
    }

    if (error instanceof HttpErrorResponse && error.status === 409) {
      this.joinErrorKind.set('closed');
      this.joinError.set('Igra je već počela i više se nije moguće pridružiti.');
      return;
    }

    this.joinErrorKind.set('generic');
    this.joinError.set('Trenutno se nije moguće pridružiti. Pokušaj ponovo.');
  }

  private backendErrorMessage(error: unknown): string {
    if (!(error instanceof HttpErrorResponse) || !this.isRecord(error.error)) return '';
    const message = error.error['error'];
    return typeof message === 'string' ? message.toLowerCase() : '';
  }

  private normalizedNickname(value: string): string {
    return value.trim().replace(/\s+/gu, ' ');
  }

  private clearJoinError(): void {
    this.joinErrorKind.set(null);
    this.joinError.set(null);
  }

  private goToStep(step: JoinStep): void {
    this.step.set(step);
    setTimeout(() => this.stepHeading()?.nativeElement.focus());
  }

  private isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
  }
}
