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

  it('uses the shared public layout and responsive branding', () => {
    const { element } = createPage();
    const page = element.querySelector<HTMLElement>('.join-page');
    const header = page?.firstElementChild as HTMLElement | null;
    const main = header?.nextElementSibling as HTMLElement | null;
    const brandLink = header?.querySelector<HTMLAnchorElement>('.brand-link');
    const desktopSource = brandLink?.querySelector<HTMLSourceElement>('source');
    const logo = brandLink?.querySelector<HTMLImageElement>('img');

    expect(page?.classList).toContain('public-page-shell');
    expect(header?.classList).toContain('public-header');
    expect(main?.classList).toContain('public-main');
    expect(brandLink?.classList).toContain('brand-slot');
    expect(brandLink?.getAttribute('href')).toBe('/');
    expect(desktopSource?.media).toBe('(min-width: 600px)');
    expect(desktopSource?.getAttribute('srcset')).toBe('/brand/logo.png');
    expect(logo?.getAttribute('src')).toContain('/brand/logo-small.png');
    expect(logo?.alt).toBe('CodeLand');
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

  it('prefills a valid PIN from the query parameter', async () => {
    const router = TestBed.inject(Router);
    await router.navigateByUrl('/?pin=004321');
    const navigate = vi.spyOn(router, 'navigate').mockResolvedValue(true);
    const { element } = createPage();
    const input = element.querySelector<HTMLInputElement>('#game-pin');

    expect(input?.value).toBe('004321');
    expect(getJoinButton(element).disabled).toBe(false);
    expect(navigate).toHaveBeenCalledWith(['/join', '004321'], { replaceUrl: true });
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
