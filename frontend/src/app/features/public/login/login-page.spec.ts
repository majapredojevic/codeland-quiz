import { HttpErrorResponse } from '@angular/common/http';
import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';

import { AuthStore } from '../../../core/auth/auth.store';
import { StaffUser } from '../../../core/auth/auth.models';
import { LoginPage } from './login-page';

describe('LoginPage', () => {
  const user: StaffUser = {
    id: 7,
    name: 'Ana Anić',
    email: 'ana@example.com',
    role: 'TEACHER',
    mustChangePassword: false,
  };

  let login: ReturnType<typeof vi.fn>;
  let clearNotice: ReturnType<typeof vi.fn>;

  beforeEach(async () => {
    login = vi.fn().mockResolvedValue(user);
    clearNotice = vi.fn();

    await TestBed.configureTestingModule({
      imports: [LoginPage],
      providers: [
        provideRouter([]),
        {
          provide: AuthStore,
          useValue: {
            user: signal<StaffUser | null>(null),
            notice: signal<'password-changed' | null>(null),
            clearNotice,
            login,
          },
        },
      ],
    }).compileComponents();
  });

  function createPage() {
    const fixture = TestBed.createComponent(LoginPage);
    fixture.detectChanges();

    return { fixture, element: fixture.nativeElement as HTMLElement };
  }

  function inputFor(element: HTMLElement, labelText: string): HTMLInputElement {
    const label = Array.from(element.querySelectorAll('label')).find(
      (candidate) => candidate.textContent?.trim() === labelText,
    );
    const input = label?.htmlFor
      ? element.querySelector<HTMLInputElement>(`#${label.htmlFor}`)
      : null;

    if (!input) {
      throw new Error(`Input labelled "${labelText}" was not rendered`);
    }

    return input;
  }

  function enter(input: HTMLInputElement, value: string): void {
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
  }

  it('renders accessible email and password fields', () => {
    const { element } = createPage();
    const email = inputFor(element, 'Email');
    const password = inputFor(element, 'Lozinka');
    const visibilityButton = element.querySelector<HTMLButtonElement>('.password-toggle');

    expect(email.type).toBe('email');
    expect(email.autocomplete).toBe('username');
    expect(password.type).toBe('password');
    expect(password.autocomplete).toBe('current-password');
    expect(visibilityButton?.getAttribute('aria-label')).toBe('Prikaži lozinku');
    expect(element.querySelector('form')).toBeTruthy();
  });

  it('renders responsive branding outside the centered login card', () => {
    const { element } = createPage();
    const page = element.querySelector<HTMLElement>('.login-page');
    const header = page?.firstElementChild as HTMLElement | null;
    const main = header?.nextElementSibling as HTMLElement | null;
    const brandLink = header?.querySelector<HTMLAnchorElement>('.brand-link');
    const desktopSource = brandLink?.querySelector<HTMLSourceElement>('source');
    const logo = brandLink?.querySelector<HTMLImageElement>('img');

    expect(page?.classList).toContain('public-page-shell');
    expect(header?.classList).toContain('public-header');
    expect(main?.classList).toContain('public-main');
    expect(brandLink?.classList).toContain('brand-link');
    expect(brandLink?.classList).toContain('brand-slot');
    expect(brandLink?.getAttribute('href')).toBe('/');
    expect(desktopSource?.media).toBe('(min-width: 600px)');
    expect(desktopSource?.getAttribute('srcset')).toBe('/brand/logo-header.png');
    expect(logo?.getAttribute('src')).toContain('/brand/logo-small.png');
    expect(logo?.alt).toBe('CodeLand');
    expect(element.querySelector('.login-shell .brand-link')).toBeNull();
  });

  it('shows the password and hides it again through the visibility control', () => {
    const { fixture, element } = createPage();
    const password = inputFor(element, 'Lozinka');
    const visibilityButton = element.querySelector<HTMLButtonElement>('.password-toggle');

    visibilityButton?.click();
    fixture.detectChanges();

    expect(password.type).toBe('text');
    expect(visibilityButton?.getAttribute('aria-label')).toBe('Sakrij lozinku');

    visibilityButton?.click();
    fixture.detectChanges();

    expect(password.type).toBe('password');
    expect(visibilityButton?.getAttribute('aria-label')).toBe('Prikaži lozinku');
  });

  it('does not submit a valid form when the visibility control is activated', () => {
    const { fixture, element } = createPage();
    enter(inputFor(element, 'Email'), 'ana@example.com');
    enter(inputFor(element, 'Lozinka'), 'Password1!');
    fixture.detectChanges();

    const visibilityButton = element.querySelector<HTMLButtonElement>('.password-toggle');
    expect(element.querySelector<HTMLButtonElement>('button[type="submit"]')?.disabled).toBe(false);
    expect(visibilityButton?.type).toBe('button');

    visibilityButton?.click();
    fixture.detectChanges();

    expect(login).not.toHaveBeenCalled();
  });

  it('links back to the public game page', () => {
    const { element } = createPage();
    const backLink = Array.from(element.querySelectorAll('a')).find(
      (link) => link.textContent?.trim() === 'Nazad na igru',
    );

    expect(backLink).toBeTruthy();
    expect(backLink?.getAttribute('href')).toBe('/');
  });

  it('keeps an invalid form from submitting', () => {
    const { element } = createPage();
    const form = element.querySelector('form');
    const button = element.querySelector<HTMLButtonElement>('button[type="submit"]');

    expect(button?.disabled).toBe(true);
    form?.dispatchEvent(new SubmitEvent('submit', { bubbles: true, cancelable: true }));

    expect(login).not.toHaveBeenCalled();
  });

  it('trims the email and invokes login for valid input', async () => {
    const { fixture, element } = createPage();
    enter(inputFor(element, 'Email'), '  ana@example.com  ');
    enter(inputFor(element, 'Lozinka'), ' tajna lozinka ');
    fixture.detectChanges();

    element
      .querySelector('form')
      ?.dispatchEvent(new SubmitEvent('submit', { bubbles: true, cancelable: true }));
    await fixture.whenStable();

    expect(login).toHaveBeenCalledWith('ana@example.com', ' tajna lozinka ');
  });

  it('prevents duplicate submission while login is pending', () => {
    let resolveLogin!: (value: StaffUser) => void;
    login.mockReturnValue(new Promise<StaffUser>((resolve) => (resolveLogin = resolve)));
    const { fixture, element } = createPage();
    enter(inputFor(element, 'Email'), 'ana@example.com');
    enter(inputFor(element, 'Lozinka'), 'Password1!');
    const form = element.querySelector('form');

    form?.dispatchEvent(new SubmitEvent('submit', { bubbles: true, cancelable: true }));
    form?.dispatchEvent(new SubmitEvent('submit', { bubbles: true, cancelable: true }));
    fixture.detectChanges();

    expect(login).toHaveBeenCalledTimes(1);
    expect(element.querySelector<HTMLButtonElement>('button[type="submit"]')?.disabled).toBe(true);
    resolveLogin(user);
  });

  it('shows a generic message for credential failure', async () => {
    login.mockRejectedValue(new HttpErrorResponse({ status: 401 }));
    const { fixture, element } = createPage();
    enter(inputFor(element, 'Email'), 'ana@example.com');
    enter(inputFor(element, 'Lozinka'), 'pogrešna');

    element
      .querySelector('form')
      ?.dispatchEvent(new SubmitEvent('submit', { bubbles: true, cancelable: true }));
    await fixture.whenStable();
    fixture.detectChanges();

    expect(element.querySelector('[role="alert"]')?.textContent).toContain(
      'Email ili lozinka nisu ispravni.',
    );
  });
});
