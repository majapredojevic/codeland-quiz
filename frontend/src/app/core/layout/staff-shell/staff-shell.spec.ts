import { OverlayContainer } from '@angular/cdk/overlay';
import { Component, computed, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';

import { StaffUser } from '../../auth/auth.models';
import { AuthStore } from '../../auth/auth.store';
import { StaffShell } from './staff-shell';

@Component({ template: '' })
class EmptyPage {}

describe('StaffShell', () => {
  const admin: StaffUser = {
    id: 11,
    name: 'Jovana Jović',
    email: 'jovana@example.com',
    role: 'ADMIN',
    mustChangePassword: false,
  };
  const teacher: StaffUser = {
    ...admin,
    id: 12,
    name: 'Maja Predojević',
    email: 'maja@example.com',
    role: 'TEACHER',
  };

  const userState = signal<StaffUser | null>(admin);
  const isAdmin = computed(() => userState()?.role === 'ADMIN');
  let logout: ReturnType<typeof vi.fn>;
  let overlayContainer: OverlayContainer;

  beforeEach(async () => {
    userState.set(admin);
    logout = vi.fn().mockResolvedValue(undefined);

    await TestBed.configureTestingModule({
      imports: [StaffShell],
      providers: [
        provideRouter([
          { path: 'app/dashboard', component: EmptyPage },
          { path: 'app/students', component: EmptyPage },
          { path: 'app/quizzes', component: EmptyPage },
          { path: 'app/sessions/:sessionId', component: EmptyPage },
          { path: 'app/results/sessions/:sessionId', component: EmptyPage },
          { path: 'app/users', component: EmptyPage },
          { path: 'app/account/password', component: EmptyPage },
          { path: 'change-password', component: EmptyPage },
        ]),
        {
          provide: AuthStore,
          useValue: {
            user: userState.asReadonly(),
            isAdmin,
            logout,
          },
        },
      ],
    }).compileComponents();

    overlayContainer = TestBed.inject(OverlayContainer);
  });

  function createShell() {
    const fixture = TestBed.createComponent(StaffShell);
    fixture.detectChanges();

    return { fixture, element: fixture.nativeElement as HTMLElement };
  }

  function profileTrigger(element: HTMLElement): HTMLButtonElement {
    const trigger = element.querySelector<HTMLButtonElement>('.profile-trigger');

    if (!trigger) {
      throw new Error('Profile trigger was not rendered');
    }

    return trigger;
  }

  async function openAccountMenu(
    fixture: ComponentFixture<StaffShell>,
    element: HTMLElement,
  ): Promise<HTMLElement> {
    profileTrigger(element).click();
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();

    const menu = overlayContainer.getContainerElement().querySelector<HTMLElement>('[role="menu"]');

    if (!menu) {
      throw new Error('Account menu did not open');
    }

    return menu;
  }

  function menuLogoutButton(menu: HTMLElement): HTMLButtonElement {
    const button = Array.from(
      menu.querySelectorAll<HTMLButtonElement>('button[role="menuitem"]'),
    ).find((candidate) => candidate.textContent?.includes('Odjavi se'));

    if (!button) {
      throw new Error('Logout menu item was not rendered');
    }

    return button;
  }

  it('renders semantic common navigation with the Results feature enabled', async () => {
    const { fixture, element } = createShell();
    await TestBed.inject(Router).navigateByUrl('/app/dashboard');
    fixture.detectChanges();

    const navigation = element.querySelector('nav[aria-label="Glavna navigacija"]');
    const home = navigation?.querySelector<HTMLAnchorElement>('a');
    const disabledItems = Array.from(
      navigation?.querySelectorAll<HTMLElement>('[role="link"][aria-disabled="true"]') ?? [],
    );

    expect(home?.textContent).toContain('Početna');
    expect(home?.getAttribute('href')).toBe('/app/dashboard');
    expect(home?.getAttribute('aria-current')).toBe('page');
    const resultsLink = Array.from(navigation?.querySelectorAll<HTMLAnchorElement>('a') ?? []).find(
      (link) => link.textContent?.trim() === 'Rezultati',
    );
    expect(resultsLink?.getAttribute('href')).toBe('/app/results');
    expect(resultsLink?.textContent).not.toContain('Uskoro');
    expect(disabledItems).toHaveLength(0);
    expect(navigation?.textContent).not.toContain('Teme');
    expect(navigation?.textContent).not.toContain('Sesije');
  });

  it.each([admin, teacher])('shows an active Kvizovi link to $role users', async (staffUser) => {
    userState.set(staffUser);
    const { fixture, element } = createShell();
    const quizzesLink = Array.from(element.querySelectorAll<HTMLAnchorElement>('nav a')).find(
      (link) => link.textContent?.trim() === 'Kvizovi',
    );

    expect(quizzesLink?.getAttribute('href')).toBe('/app/quizzes');
    expect(quizzesLink?.textContent).not.toContain('Uskoro');
    await TestBed.inject(Router).navigateByUrl('/app/quizzes');
    fixture.detectChanges();
    expect(quizzesLink?.classList).toContain('is-active');
    expect(quizzesLink?.getAttribute('aria-current')).toBe('page');
  });

  it.each([admin, teacher])('shows an active Učenici link to $role users', async (staffUser) => {
    userState.set(staffUser);
    const { fixture, element } = createShell();
    const studentsLink = Array.from(element.querySelectorAll<HTMLAnchorElement>('nav a')).find(
      (link) => link.textContent?.trim() === 'Učenici',
    );

    expect(studentsLink?.getAttribute('href')).toBe('/app/students');
    expect(studentsLink?.getAttribute('aria-disabled')).toBeNull();
    expect(studentsLink?.textContent).not.toContain('Uskoro');

    await TestBed.inject(Router).navigateByUrl('/app/students');
    fixture.detectChanges();

    expect(studentsLink?.classList).toContain('is-active');
    expect(studentsLink?.getAttribute('aria-current')).toBe('page');
  });

  it('renders initials, name, and role inside one native horizontal profile trigger', () => {
    const { element } = createShell();
    const trigger = profileTrigger(element);
    const summary = trigger.querySelector('clq-staff-user-summary');
    const avatar = summary?.querySelector('.user-avatar');
    const userCopy = summary?.querySelector('.user-copy');

    expect(trigger.tagName).toBe('BUTTON');
    expect(trigger.getAttribute('aria-label')).toBe(`Otvori korisnički meni za ${admin.name}`);
    expect(summary?.firstElementChild).toBe(avatar);
    expect(avatar?.nextElementSibling).toBe(userCopy);
    expect(avatar?.textContent?.trim()).toBe('JJ');
    expect(summary?.querySelector('.user-name')?.textContent).toContain(admin.name);
    expect(summary?.querySelector('.user-role')?.textContent).toContain('ADMIN');
    expect(trigger.querySelector('.profile-chevron')).not.toBeNull();
  });

  it.each([admin, teacher])('opens both account actions for $role users', async (staffUser) => {
    userState.set(staffUser);
    const { fixture, element } = createShell();
    const menu = await openAccountMenu(fixture, element);
    const items = Array.from(menu.querySelectorAll<HTMLElement>('[role="menuitem"]'));
    const changePassword = items.find((item) => item.textContent?.includes('Promijeni lozinku'));

    expect(menu.classList).toContain('clq-account-menu');
    expect(items).toHaveLength(2);
    expect(changePassword?.tagName).toBe('A');
    expect(changePassword?.getAttribute('href')).toBe('/app/account/password');
    expect(
      items.every(
        (item) =>
          item.classList.contains('clq-account-menu__item') &&
          item.querySelector('.clq-account-menu__icon')?.getAttribute('aria-hidden') === 'true',
      ),
    ).toBe(true);
    expect(items.some((item) => item.textContent?.includes('Odjavi se'))).toBe(true);

    changePassword?.click();
    fixture.detectChanges();
    await fixture.whenStable();

    expect(TestBed.inject(Router).url).toBe('/app/account/password');
  });

  it('shows an active Korisnici link only to administrators', async () => {
    const { fixture, element } = createShell();
    const usersLink = Array.from(element.querySelectorAll<HTMLAnchorElement>('nav a')).find(
      (link) => link.textContent?.trim() === 'Korisnici',
    );

    expect(usersLink?.getAttribute('href')).toBe('/app/users');
    expect(usersLink?.getAttribute('aria-disabled')).toBeNull();
    expect(profileTrigger(element).textContent).toContain('ADMIN');

    await TestBed.inject(Router).navigateByUrl('/app/users');
    fixture.detectChanges();

    expect(usersLink?.classList).toContain('is-active');
    expect(usersLink?.getAttribute('aria-current')).toBe('page');

    userState.set(teacher);
    fixture.detectChanges();

    expect(
      Array.from(element.querySelectorAll<HTMLAnchorElement>('nav a')).some(
        (link) => link.textContent?.trim() === 'Korisnici',
      ),
    ).toBe(false);
    expect(profileTrigger(element).textContent).toContain('TEACHER');
    expect(profileTrigger(element).querySelector('.user-avatar')?.textContent?.trim()).toBe('MP');
  });

  it('keeps the sidebar navigation-only and logs out through the existing store flow', async () => {
    const { fixture, element } = createShell();
    const menu = await openAccountMenu(fixture, element);

    expect(element.querySelector('.sidebar-footer')).toBeNull();
    expect(element.querySelector('.sidebar')?.textContent).not.toContain('Odjavi se');

    menuLogoutButton(menu).click();
    fixture.detectChanges();
    await fixture.whenStable();

    expect(logout).toHaveBeenCalledOnce();
  });

  it('prevents duplicate logout requests', async () => {
    let resolveLogout!: () => void;
    logout.mockReturnValue(new Promise<void>((resolve) => (resolveLogout = resolve)));
    const { fixture, element } = createShell();
    const menu = await openAccountMenu(fixture, element);
    const button = menuLogoutButton(menu);

    button.click();
    button.click();

    expect(logout).toHaveBeenCalledOnce();
    resolveLogout();
    await fixture.whenStable();
  });

  it('restores focus to the profile trigger when the menu closes', async () => {
    const { fixture, element } = createShell();
    const trigger = profileTrigger(element);
    const menu = await openAccountMenu(fixture, element);

    menu.querySelector<HTMLElement>('[role="menuitem"]')?.focus();
    const escape = new KeyboardEvent('keydown', { key: 'Escape', bubbles: true });
    Object.defineProperty(escape, 'keyCode', { value: 27 });
    menu.dispatchEvent(escape);
    fixture.detectChanges();
    await fixture.whenStable();

    expect(document.activeElement).toBe(trigger);
  });

  it('moves focus into and back out of the narrow-screen navigation', async () => {
    const { fixture, element } = createShell();
    const menuButton = Array.from(element.querySelectorAll('button')).find(
      (button) => button.textContent?.trim() === 'Meni',
    );
    const closeButton = element.querySelector<HTMLButtonElement>(
      'button[aria-label="Zatvori navigaciju"]',
    );

    expect(menuButton?.getAttribute('aria-expanded')).toBe('false');
    menuButton?.click();
    fixture.detectChanges();
    await fixture.whenStable();
    expect(menuButton?.getAttribute('aria-expanded')).toBe('true');
    expect(document.activeElement).toBe(closeButton);
    expect(element.querySelector('header')?.hasAttribute('inert')).toBe(true);
    expect(element.querySelector('main')?.hasAttribute('inert')).toBe(true);

    closeButton?.click();
    fixture.detectChanges();
    await fixture.whenStable();
    expect(menuButton?.getAttribute('aria-expanded')).toBe('false');
    expect(document.activeElement).toBe(menuButton);
    expect(element.querySelector('header')?.hasAttribute('inert')).toBe(false);
    expect(element.querySelector('main')?.hasAttribute('inert')).toBe(false);
  });

  it('scopes the overlay navigation presentation mode to session routes', async () => {
    const { fixture, element } = createShell();
    const router = TestBed.inject(Router);

    await router.navigateByUrl('/app/sessions/41');
    fixture.detectChanges();
    expect(element.querySelector('.staff-shell')?.classList).toContain('is-presentation');
    expect(element.querySelector('.sidebar')?.classList).not.toContain('is-open');
    expect(element.querySelector('.profile-trigger')).not.toBeNull();

    const menuButton = element.querySelector<HTMLButtonElement>('.menu-toggle');
    menuButton?.click();
    fixture.detectChanges();
    await fixture.whenStable();
    expect(element.querySelector('.sidebar')?.classList).toContain('is-open');
    expect(element.querySelector('.navigation-backdrop')).not.toBeNull();
    expect(element.querySelector('main')?.hasAttribute('inert')).toBe(true);

    element.querySelector<HTMLButtonElement>('.navigation-backdrop')?.click();
    fixture.detectChanges();
    await fixture.whenStable();
    expect(element.querySelector('.sidebar')?.classList).not.toContain('is-open');
    expect(element.querySelector('.navigation-backdrop')).toBeNull();

    menuButton?.click();
    fixture.detectChanges();
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    fixture.detectChanges();
    await fixture.whenStable();
    expect(element.querySelector('.sidebar')?.classList).not.toContain('is-open');

    await router.navigateByUrl('/app/dashboard');
    fixture.detectChanges();
    expect(element.querySelector('.staff-shell')?.classList).not.toContain('is-presentation');

    await router.navigateByUrl('/app/results/sessions/41');
    fixture.detectChanges();
    expect(element.querySelector('.staff-shell')?.classList).not.toContain('is-presentation');
  });

  it('keeps the account visible and reports a safe logout failure in the workspace', async () => {
    logout.mockRejectedValue(new Error('server unavailable'));
    const { fixture, element } = createShell();
    const menu = await openAccountMenu(fixture, element);

    menuLogoutButton(menu).click();
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();

    expect(element.querySelector('.workspace > [role="alert"]')?.textContent).toContain(
      'Odjava trenutno nije moguća. Pokušajte ponovo.',
    );
    expect(profileTrigger(element).textContent).toContain(admin.name);
    expect(element.querySelector('.sidebar [role="alert"]')).toBeNull();
  });
});
