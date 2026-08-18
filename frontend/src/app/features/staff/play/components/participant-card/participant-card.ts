import { Component, input, output } from '@angular/core';

import { KodaAvatar } from '../../../../../shared/game/koda-avatar/koda-avatar';
import { SessionParticipant } from '../../data-access/play.models';

@Component({
  selector: 'clq-participant-card',
  imports: [KodaAvatar],
  templateUrl: './participant-card.html',
  styleUrl: './participant-card.scss',
})
export class ParticipantCard {
  readonly participant = input.required<SessionParticipant>();
  readonly removing = input(false);
  readonly removalAllowed = input(true);
  readonly remove = output<SessionParticipant>();
}
