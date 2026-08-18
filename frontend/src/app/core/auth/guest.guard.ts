import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';

import { AuthStore } from './auth.store';

export const guestGuard: CanActivateFn = async () => {
  const authStore = inject(AuthStore);
  const router = inject(Router);

  await authStore.restoreSession();

  if (!authStore.isAuthenticated()) {
    return true;
  }

  return router.createUrlTree([
    authStore.mustChangePassword() ? '/change-password' : '/app/dashboard',
  ]);
};
