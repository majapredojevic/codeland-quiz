import { Component, inject } from '@angular/core';
import { Router } from '@angular/router';

import { ChangePasswordForm } from '../../../../shared/account/change-password-form/change-password-form';

@Component({
  selector: 'clq-account-password-page',
  imports: [ChangePasswordForm],
  templateUrl: './account-password-page.html',
  styleUrl: './account-password-page.scss',
})
export class AccountPasswordPage {
  private readonly router = inject(Router);

  protected cancel(): void {
    void this.router.navigateByUrl('/app/dashboard');
  }
}
