import { TestBed } from '@angular/core/testing';
import { Router } from '@angular/router';

import { AuthStore } from '../../../../core/auth/auth.store';
import { AccountPasswordPage } from './account-password-page';

describe('AccountPasswordPage', () => {
  let navigateByUrl: ReturnType<typeof vi.fn>;

  beforeEach(async () => {
    navigateByUrl = vi.fn().mockResolvedValue(true);

    await TestBed.configureTestingModule({
      imports: [AccountPasswordPage],
      providers: [
        {
          provide: AuthStore,
          useValue: { changePassword: vi.fn().mockResolvedValue(undefined) },
        },
        {
          provide: Router,
          useValue: { navigateByUrl },
        },
      ],
    }).compileComponents();
  });

  function createPage() {
    const fixture = TestBed.createComponent(AccountPasswordPage);
    fixture.detectChanges();

    return { fixture, element: fixture.nativeElement as HTMLElement };
  }

  it('renders the voluntary account context and shared password form', () => {
    const { element } = createPage();

    expect(element.querySelector('h1')?.textContent).toContain('Promjena lozinke');
    expect(element.textContent).toContain('Promijenite lozinku svog CodeLand naloga.');
    expect(element.querySelector('clq-change-password-form')).not.toBeNull();
    expect(element.querySelector('form')).not.toBeNull();
    expect(element.querySelector<HTMLButtonElement>('.secondary-button')?.textContent).toContain(
      'Otkaži',
    );
    expect(element.querySelector('.change-password-page')).toBeNull();
  });

  it('navigates cancel deterministically to the dashboard', () => {
    const { element } = createPage();

    element.querySelector<HTMLButtonElement>('.secondary-button')?.click();

    expect(navigateByUrl).toHaveBeenCalledOnce();
    expect(navigateByUrl).toHaveBeenCalledWith('/app/dashboard');
  });
});
