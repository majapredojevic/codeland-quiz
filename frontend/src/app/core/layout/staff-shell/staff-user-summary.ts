import { Component, computed, input } from '@angular/core';

import { StaffUser } from '../../auth/auth.models';

@Component({
  selector: 'clq-staff-user-summary',
  template: `
    <span class="user-avatar" aria-hidden="true">{{ initials() }}</span>
    <span class="user-copy">
      <span class="user-name">{{ user().name }}</span>
      <span class="user-role">{{ user().role }}</span>
    </span>
  `,
  styles: `
    :host {
      display: flex;
      overflow: hidden;
      min-width: 0;
      align-items: center;
      flex: 1 1 auto;
      gap: var(--clq-space-2);
    }

    .user-avatar {
      display: grid;
      width: 2.375rem;
      height: 2.375rem;
      border-radius: 50%;
      background: var(--clq-color-primary);
      color: var(--clq-color-surface-raised);
      flex: 0 0 auto;
      font-size: 0.8125rem;
      font-weight: 600;
      place-items: center;
    }

    .user-copy {
      display: grid;
      min-width: 0;
      gap: 0.1rem;
      text-align: left;
    }

    .user-name,
    .user-role {
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .user-name {
      font-weight: 500;
    }

    .user-role {
      color: var(--clq-color-text-muted);
      font-size: var(--clq-font-size-small);
      font-weight: 400;
    }
  `,
})
export class StaffUserSummary {
  readonly user = input.required<StaffUser>();

  protected readonly initials = computed(() => {
    const nameParts = this.user().name.trim().split(/\s+/).filter(Boolean);
    const firstName = nameParts[0];

    if (!firstName) {
      return '';
    }

    const initialParts =
      nameParts.length === 1 ? [firstName] : [firstName, nameParts.at(-1) ?? firstName];

    return initialParts
      .map((part) => Array.from(part)[0] ?? '')
      .join('')
      .toUpperCase();
  });
}
