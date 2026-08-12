import { Component, input, output } from '@angular/core';
import { MatMenu, MatMenuItem, MatMenuTrigger } from '@angular/material/menu';

import { TopicItem } from '../../data-access/quizzes.models';

@Component({
  selector: 'clq-topic-card',
  imports: [MatMenu, MatMenuItem, MatMenuTrigger],
  templateUrl: './topic-card.html',
  styleUrl: './topic-card.scss',
})
export class TopicCard {
  readonly topic = input.required<TopicItem>();
  readonly selected = input(false);
  readonly selectTopic = output<TopicItem>();
  readonly editTopic = output<TopicItem>();
  readonly deleteTopic = output<TopicItem>();

  protected quizCountLabel(count: number): string {
    const mod100 = count % 100;
    const mod10 = count % 10;
    if (mod100 === 11) return `${count} kvizova`;
    if (mod10 === 1) return `${count} kviz`;
    if (mod10 >= 2 && mod10 <= 4 && !(mod100 >= 12 && mod100 <= 14)) {
      return `${count} kviza`;
    }
    return `${count} kvizova`;
  }
}
