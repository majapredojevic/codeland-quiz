import { TestBed } from '@angular/core/testing';

import { AuthStore } from '../../../core/auth/auth.store';
import { ChangePasswordPage } from './change-password-page';

describe('ChangePasswordPage', () => {
  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [ChangePasswordPage],
      providers: [
        {
          provide: AuthStore,
          useValue: { changePassword: vi.fn().mockResolvedValue(undefined) },
        },
      ],
    }).compileComponents();
  });

  it('keeps the required password change in the branded standalone layout without cancel', () => {
    const fixture = TestBed.createComponent(ChangePasswordPage);
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;
    const page = element.querySelector<HTMLElement>('.change-password-page');
    const header = page?.firstElementChild as HTMLElement | null;
    const main = header?.nextElementSibling as HTMLElement | null;
    const brandSlot = header?.querySelector<HTMLElement>('.brand-slot');
    const brandPicture = brandSlot?.querySelector<HTMLPictureElement>('.brand-picture');
    const desktopSource = brandPicture?.querySelector<HTMLSourceElement>('source');
    const logo = brandPicture?.querySelector<HTMLImageElement>('img');

    expect(page?.classList).toContain('public-page-shell');
    expect(header?.classList).toContain('public-header');
    expect(main?.classList).toContain('public-main');
    expect(brandPicture?.classList).toContain('brand-picture');
    expect(desktopSource?.media).toBe('(min-width: 600px)');
    expect(desktopSource?.getAttribute('srcset')).toBe('/brand/logo-header.png');
    expect(logo?.getAttribute('src')).toContain('/brand/logo-small.png');
    expect(logo?.alt).toBe('CodeLand');
    expect(logo?.closest('a')).toBeNull();
    expect(element.querySelector('.password-shell .brand-picture')).toBeNull();
    expect(element.querySelector('clq-change-password-form')).not.toBeNull();
    expect(element.querySelector('clq-staff-shell')).toBeNull();
    expect(element.querySelector('.secondary-button')).toBeNull();
    expect(element.textContent).not.toContain('Otkaži');
  });
});
