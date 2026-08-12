import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';

import { AuthStore } from '../../../core/auth/auth.store';
import { StaffUser } from '../../../core/auth/auth.models';
import { DashboardPage } from './dashboard-page';

describe('DashboardPage', () => {
  const user: StaffUser = {
    id: 11,
    name: 'Jovana Jović',
    email: 'jovana@example.com',
    role: 'ADMIN',
    mustChangePassword: false,
  };

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [DashboardPage],
      providers: [
        {
          provide: AuthStore,
          useValue: {
            user: signal<StaffUser | null>(user),
          },
        },
      ],
    }).compileComponents();
  });

  function createPage() {
    const fixture = TestBed.createComponent(DashboardPage);
    fixture.detectChanges();

    return { element: fixture.nativeElement as HTMLElement };
  }

  it('renders the page title and a restrained personalized greeting', () => {
    const { element } = createPage();

    expect(element.querySelector('h1')?.textContent?.trim()).toBe('Početna');
    expect(element.querySelector('.greeting')?.textContent).toContain('Dobro došli, Jovana Jović');
  });

  it('renders both quick actions as clearly unavailable non-links', () => {
    const { element } = createPage();
    const actions = Array.from(element.querySelectorAll<HTMLButtonElement>('.quick-action'));

    expect(actions).toHaveLength(2);
    expect(actions[0]?.textContent).toContain('Pokreni kviz');
    expect(actions[1]?.textContent).toContain('Novi kviz');
    expect(actions.every((action) => action.disabled)).toBe(true);
    expect(actions.every((action) => action.getAttribute('aria-disabled') === 'true')).toBe(true);
    expect(element.querySelector('.quick-actions a')).toBeNull();
    expect(element.querySelectorAll('.quick-action__status')[0]?.textContent).toContain(
      'Uskoro dostupno',
    );
  });

  it('renders meaningful empty states for recent content', () => {
    const { element } = createPage();

    expect(element.textContent).toContain('Nedavni kvizovi');
    expect(element.textContent).toContain('Još nema učitanih kvizova.');
    expect(element.textContent).toContain('Nedavni rezultati');
    expect(element.textContent).toContain('Rezultati odigranih kvizova biće prikazani ovdje.');
  });

  it('does not render fabricated numeric statistics', () => {
    const { element } = createPage();

    expect(element.querySelector('[data-statistic], .summary-card')).toBeNull();
    expect(element.textContent).not.toMatch(/\b(?:24|32|86)\b/);
  });
});
