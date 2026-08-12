import { Component, input } from '@angular/core';

@Component({
  selector: 'clq-active-status-badge',
  templateUrl: './active-status-badge.html',
  styleUrl: './active-status-badge.scss',
})
export class ActiveStatusBadge {
  readonly isActive = input.required<boolean>();
}
