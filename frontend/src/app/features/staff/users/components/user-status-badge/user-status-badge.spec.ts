import { TestBed } from '@angular/core/testing';

import { UserStatusBadge } from './user-status-badge';

describe('UserStatusBadge', () => {
  beforeEach(async () => {
    await TestBed.configureTestingModule({ imports: [UserStatusBadge] }).compileComponents();
  });

  function render(isActive: boolean): HTMLElement {
    const fixture = TestBed.createComponent(UserStatusBadge);
    fixture.componentRef.setInput('isActive', isActive);
    fixture.detectChanges();

    return fixture.nativeElement as HTMLElement;
  }

  it('shows active status with visible text and a decorative indicator', () => {
    const element = render(true);
    const badge = element.querySelector('.status-badge');

    expect(badge?.textContent?.trim()).toBe('Aktivan');
    expect(badge?.classList).toContain('status-badge--active');
    expect(badge?.querySelector('.status-badge__dot')?.getAttribute('aria-hidden')).toBe('true');
  });

  it('shows inactive status with visible text', () => {
    const element = render(false);
    const badge = element.querySelector('.status-badge');

    expect(badge?.textContent?.trim()).toBe('Neaktivan');
    expect(badge?.classList).not.toContain('status-badge--active');
  });
});
