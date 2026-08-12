import { Component, input } from '@angular/core';

@Component({
  selector: 'clq-user-status-badge',
  templateUrl: './user-status-badge.html',
  styleUrl: './user-status-badge.scss',
})
export class UserStatusBadge {
  readonly isActive = input.required<boolean>();
}
