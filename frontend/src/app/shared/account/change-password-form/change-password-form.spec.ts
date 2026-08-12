import { HttpErrorResponse } from '@angular/common/http';
import { TestBed } from '@angular/core/testing';

import { AuthStore } from '../../../core/auth/auth.store';
import { ChangePasswordForm } from './change-password-form';

describe('ChangePasswordForm', () => {
  let changePassword: ReturnType<typeof vi.fn>;

  beforeEach(async () => {
    changePassword = vi.fn().mockResolvedValue(undefined);

    await TestBed.configureTestingModule({
      imports: [ChangePasswordForm],
      providers: [
        {
          provide: AuthStore,
          useValue: { changePassword },
        },
      ],
    }).compileComponents();
  });

  function createForm() {
    const fixture = TestBed.createComponent(ChangePasswordForm);
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

  function passwordInputs(element: HTMLElement): HTMLInputElement[] {
    return [
      inputFor(element, 'Trenutna lozinka'),
      inputFor(element, 'Nova lozinka'),
      inputFor(element, 'Potvrdi novu lozinku'),
    ];
  }

  function enter(input: HTMLInputElement, value: string): void {
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function submit(element: HTMLElement): void {
    element
      .querySelector('form')
      ?.dispatchEvent(new SubmitEvent('submit', { bubbles: true, cancelable: true }));
  }

  it('starts all three fields hidden with non-submitting accessible eye buttons', () => {
    const { element } = createForm();
    const inputs = passwordInputs(element);
    const toggles = Array.from(element.querySelectorAll<HTMLButtonElement>('.password-toggle'));

    expect(inputs.map(({ type }) => type)).toEqual(['password', 'password', 'password']);
    expect(toggles).toHaveLength(3);
    toggles.forEach((toggle, index) => {
      expect(toggle.type).toBe('button');
      expect(toggle.getAttribute('aria-label')).toBe('Prikaži lozinku');
      expect(toggle.getAttribute('aria-controls')).toBe(inputs[index].id);
      expect(toggle.getAttribute('aria-pressed')).toBe('false');
    });
  });

  it.each([
    ['current', 0],
    ['new', 1],
    ['confirmation', 2],
  ])('toggles the %s password independently without altering values or submitting', (_, index) => {
    const { fixture, element } = createForm();
    const values = ['OldPassword1!', 'NewPassword2!', 'NewPassword2!'];
    const inputs = passwordInputs(element);
    inputs.forEach((input, inputIndex) => enter(input, values[inputIndex]));
    fixture.detectChanges();

    const submitListener = vi.fn();
    element.querySelector('form')?.addEventListener('submit', submitListener);
    const toggles = Array.from(element.querySelectorAll<HTMLButtonElement>('.password-toggle'));
    toggles[index].click();
    fixture.detectChanges();

    expect(inputs.map(({ type }) => type)).toEqual(
      inputs.map((_, inputIndex) => (inputIndex === index ? 'text' : 'password')),
    );
    expect(inputs.map(({ value }) => value)).toEqual(values);
    expect(toggles[index].getAttribute('aria-label')).toBe('Sakrij lozinku');
    expect(submitListener).not.toHaveBeenCalled();
    expect(changePassword).not.toHaveBeenCalled();
  });

  it('rejects a confirmation that does not match', () => {
    const { fixture, element } = createForm();
    const inputs = passwordInputs(element);
    enter(inputs[0], 'OldPassword1!');
    enter(inputs[1], 'NewPassword2!');
    enter(inputs[2], 'Different2!');
    submit(element);
    fixture.detectChanges();

    expect(changePassword).not.toHaveBeenCalled();
    expect(element.textContent).toContain('Potvrda nove lozinke se ne podudara.');
  });

  it('enforces the existing eight-character minimum', () => {
    const { fixture, element } = createForm();
    const inputs = passwordInputs(element);
    enter(inputs[0], 'OldPassword1!');
    enter(inputs[1], 'New1!');
    enter(inputs[2], 'New1!');
    submit(element);
    fixture.detectChanges();

    expect(changePassword).not.toHaveBeenCalled();
    expect(element.textContent).toContain('Nova lozinka mora imati najmanje 8 znakova.');
  });

  it('submits the exact backend fields without trimming passwords', async () => {
    const { fixture, element } = createForm();
    const inputs = passwordInputs(element);
    enter(inputs[0], ' OldPassword1! ');
    enter(inputs[1], ' NewPassword2! ');
    enter(inputs[2], ' NewPassword2! ');
    submit(element);
    await fixture.whenStable();

    expect(changePassword).toHaveBeenCalledWith({
      currentPassword: ' OldPassword1! ',
      newPassword: ' NewPassword2! ',
      newPasswordConfirmation: ' NewPassword2! ',
    });
  });

  it('shows a safe error when the backend rejects the submitted values', async () => {
    changePassword.mockRejectedValue(new HttpErrorResponse({ status: 400 }));
    const { fixture, element } = createForm();
    const inputs = passwordInputs(element);
    enter(inputs[0], 'OldPassword1!');
    enter(inputs[1], 'NewPassword2!');
    enter(inputs[2], 'NewPassword2!');
    submit(element);
    await fixture.whenStable();
    fixture.detectChanges();

    expect(element.querySelector('[role="alert"]')?.textContent).toContain(
      'Promjena lozinke nije uspjela. Provjerite unesene podatke.',
    );
  });
});
