import { Component, computed, input } from '@angular/core';

@Component({
  selector: 'clq-koda-avatar',
  template: `
    <span class="koda-avatar" [attr.data-avatar-key]="avatarKey()" aria-hidden="true">
      <svg viewBox="0 0 48 48" focusable="false">
        <path
          d="M14 18.5c0-6.1 4.5-10.5 10-10.5s10 4.4 10 10.5v7c0 7-4.1 12.5-10 12.5s-10-5.5-10-12.5Z"
        />
        <circle cx="20" cy="23" r="1.7" />
        <circle cx="28" cy="23" r="1.7" />
        <path d="M20 29c2.4 1.8 5.6 1.8 8 0" />
      </svg>
      <span>{{ initials() }}</span>
    </span>
  `,
  styleUrl: './koda-avatar.scss',
})
export class KodaAvatar {
  readonly nickname = input.required<string>();
  readonly avatarKey = input<string>('koda-purple');
  protected readonly initials = computed(() =>
    Array.from(this.nickname().trim()).slice(0, 2).join('').toLocaleUpperCase('bs'),
  );
}
