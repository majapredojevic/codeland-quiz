import { Component, input, output } from '@angular/core';

import { SessionParticipant } from '../../data-access/play.models';

@Component({
  selector: 'clq-participant-card',
  templateUrl: './participant-card.html',
  styleUrl: './participant-card.scss',
})
export class ParticipantCard {
  readonly participant = input.required<SessionParticipant>();
  readonly removing = input(false);
  readonly removalAllowed = input(true);
  readonly remove = output<SessionParticipant>();

  protected initials(nickname: string): string {
    const characters = Array.from(nickname.trim());
    return characters.slice(0, 2).join('').toLocaleUpperCase('bs');
  }
}
