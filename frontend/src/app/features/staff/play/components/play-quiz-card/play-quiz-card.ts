import { Component, input, output } from '@angular/core';

import { PlayableQuiz } from '../../data-access/play.models';

@Component({
  selector: 'clq-play-quiz-card',
  templateUrl: './play-quiz-card.html',
  styleUrl: './play-quiz-card.scss',
})
export class PlayQuizCard {
  readonly quiz = input.required<PlayableQuiz>();
  readonly lastPlayedLabel = input<string | null>(null);
  readonly starting = input(false);
  readonly disabled = input(false);
  readonly play = output<PlayableQuiz>();

  protected questionCountLabel(count: number): string {
    const mod100 = count % 100;
    const mod10 = count % 10;
    if (mod100 === 11) return `${count} pitanja`;
    if (mod10 === 1) return `${count} pitanje`;
    if (mod10 >= 2 && mod10 <= 4 && !(mod100 >= 12 && mod100 <= 14)) {
      return `${count} pitanja`;
    }
    return `${count} pitanja`;
  }
}
