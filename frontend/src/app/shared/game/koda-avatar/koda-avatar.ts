import { NgOptimizedImage } from '@angular/common';
import { Component, computed, input } from '@angular/core';

const AVATAR_IMAGE_BY_KEY: Readonly<Record<string, string>> = {
  'koda-blue': '/avatars/Koda1.png',
  'koda-green': '/avatars/Koda2.png',
  'koda-orange': '/avatars/Koda3.png',
  'koda-pink': '/avatars/Koda4.png',
  'koda-purple': '/avatars/Koda5.png',
  'koda-red': '/avatars/Koda6.png',
  'koda-turquoise': '/avatars/Koda7.png',
  'koda-yellow': '/avatars/Koda8.png',
};

@Component({
  selector: 'clq-koda-avatar',
  imports: [NgOptimizedImage],
  template: `
    <span class="koda-frame">
      <img [ngSrc]="source()" fill [alt]="alt()" [priority]="priority()" />
    </span>
  `,
  styleUrl: './koda-avatar.scss',
})
export class KodaAvatar {
  readonly avatarKey = input.required<string>();
  readonly alt = input('');
  readonly priority = input(false);

  protected readonly source = computed(
    () => AVATAR_IMAGE_BY_KEY[this.avatarKey()] ?? '/avatars/Koda1.png',
  );
}
