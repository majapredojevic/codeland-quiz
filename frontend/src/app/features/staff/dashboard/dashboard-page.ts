import { Component, inject } from '@angular/core';

import { AuthStore } from '../../../core/auth/auth.store';

@Component({
  selector: 'clq-dashboard-page',
  templateUrl: './dashboard-page.html',
  styleUrl: './dashboard-page.scss',
})
export class DashboardPage {
  private readonly authStore = inject(AuthStore);

  protected readonly user = this.authStore.user;
}
