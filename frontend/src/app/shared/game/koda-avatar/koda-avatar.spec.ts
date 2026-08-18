import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';

import { KodaAvatar } from './koda-avatar';

@Component({
  imports: [KodaAvatar],
  template: `
    <div class="avatar-host">
      <clq-koda-avatar avatarKey="koda-blue" alt="Plavi Koda" />
    </div>
  `,
  styles: `
    .avatar-host {
      width: 8rem;
      height: 8rem;
    }
  `,
})
class TestHost {}

describe('KodaAvatar', () => {
  it('fills its reserved frame without declaring a false square image ratio', async () => {
    await TestBed.configureTestingModule({ imports: [TestHost] }).compileComponents();
    const fixture = TestBed.createComponent(TestHost);
    fixture.detectChanges();

    const element = fixture.nativeElement as HTMLElement;
    const frame = element.querySelector<HTMLElement>('.koda-frame')!;
    const image = frame.querySelector<HTMLImageElement>('img')!;

    expect(image.getAttribute('src')).toContain('/avatars/Koda1.png');
    expect(image.hasAttribute('width')).toBe(false);
    expect(image.hasAttribute('height')).toBe(false);
    expect(getComputedStyle(frame).position).toBe('relative');
    expect(getComputedStyle(image).objectFit).toBe('contain');
  });
});
