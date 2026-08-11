import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';

import { JoinPage } from './join-page';

describe('JoinPage', () => {
  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [JoinPage],
      providers: [provideRouter([])],
    }).compileComponents();
  });

  function createPage() {
    const fixture = TestBed.createComponent(JoinPage);
    fixture.detectChanges();

    return {
      fixture,
      element: fixture.nativeElement as HTMLElement,
    };
  }

  function enterPin(input: HTMLInputElement, value: string): void {
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function getJoinButton(element: HTMLElement): HTMLButtonElement {
    const button = Array.from(element.querySelectorAll('button')).find(
      (candidate) => candidate.textContent?.trim() === 'Pridruži se',
    );

    if (!button) {
      throw new Error('Join button was not rendered');
    }

    return button;
  }

  it('renders the page', () => {
    const { element } = createPage();

    expect(element.querySelector('main')).toBeTruthy();
    expect(element.querySelector('form')).toBeTruthy();
  });

  it('renders the CodeLand Quiz heading', () => {
    const { element } = createPage();

    expect(element.querySelector('h1')?.textContent).toContain('CodeLand Quiz');
  });

  it('links the login action to /login', () => {
    const { element } = createPage();
    const loginLink = Array.from(element.querySelectorAll('a')).find(
      (link) => link.textContent?.trim() === 'Prijavi se',
    );

    expect(loginLink?.getAttribute('href')).toBe('/login');
  });

  it('starts with the join action disabled', () => {
    const { element } = createPage();

    expect(getJoinButton(element).disabled).toBe(true);
  });

  it('keeps the join action disabled for fewer than six digits', () => {
    const { fixture, element } = createPage();
    const input = element.querySelector<HTMLInputElement>('#game-pin');

    expect(input).toBeTruthy();
    enterPin(input!, '12345');
    fixture.detectChanges();

    expect(getJoinButton(element).disabled).toBe(true);
  });

  it('enables the join action for exactly six digits', () => {
    const { fixture, element } = createPage();
    const input = element.querySelector<HTMLInputElement>('#game-pin');

    expect(input).toBeTruthy();
    enterPin(input!, '123456');
    fixture.detectChanges();

    expect(getJoinButton(element).disabled).toBe(false);
  });

  it('removes letters and spaces from the PIN', () => {
    const { fixture, element } = createPage();
    const input = element.querySelector<HTMLInputElement>('#game-pin');

    expect(input).toBeTruthy();
    enterPin(input!, 'a 1b 2c');
    fixture.detectChanges();

    expect(input!.value).toBe('12');
    expect(getJoinButton(element).disabled).toBe(true);
  });

  it('removes punctuation from the PIN', () => {
    const { fixture, element } = createPage();
    const input = element.querySelector<HTMLInputElement>('#game-pin');

    expect(input).toBeTruthy();
    enterPin(input!, '12-34.56!');
    fixture.detectChanges();

    expect(input!.value).toBe('123456');
    expect(getJoinButton(element).disabled).toBe(false);
  });

  it('normalizes pasted mixed content', () => {
    const { fixture, element } = createPage();
    const input = element.querySelector<HTMLInputElement>('#game-pin');

    expect(input).toBeTruthy();
    enterPin(input!, '12ab34-56');
    fixture.detectChanges();

    expect(input!.value).toBe('123456');
    expect(getJoinButton(element).disabled).toBe(false);
  });

  it('preserves leading zeroes', () => {
    const { fixture, element } = createPage();
    const input = element.querySelector<HTMLInputElement>('#game-pin');

    expect(input).toBeTruthy();
    enterPin(input!, '001234');
    fixture.detectChanges();

    expect(input!.value).toBe('001234');
    expect(getJoinButton(element).disabled).toBe(false);
  });

  it('truncates the PIN to six digits', () => {
    const { fixture, element } = createPage();
    const input = element.querySelector<HTMLInputElement>('#game-pin');

    expect(input).toBeTruthy();
    enterPin(input!, '123456789');
    fixture.detectChanges();

    expect(input!.value).toBe('123456');
    expect(getJoinButton(element).disabled).toBe(false);
  });

  it('navigates to the join route when a valid PIN is submitted', () => {
    const router = TestBed.inject(Router);
    const navigate = vi.spyOn(router, 'navigate').mockResolvedValue(true);
    const { fixture, element } = createPage();
    const input = element.querySelector<HTMLInputElement>('#game-pin');
    const form = element.querySelector('form');

    expect(input).toBeTruthy();
    expect(form).toBeTruthy();
    enterPin(input!, '654321');
    fixture.detectChanges();
    form!.dispatchEvent(new SubmitEvent('submit', { bubbles: true, cancelable: true }));

    expect(navigate).toHaveBeenCalledWith(['/join', '654321']);
  });
});
