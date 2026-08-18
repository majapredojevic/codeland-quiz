import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';

import { AuthStore } from './auth.store';

export const passwordChangeGuard: CanActivateFn = async () => {
  const authStore = inject(AuthStore);
  const router = inject(Router);

  await authStore.restoreSession();

  if (!authStore.isAuthenticated()) {
    return router.createUrlTree(['/login']);
  }

  return authStore.mustChangePassword() ? router.createUrlTree(['/change-password']) : true;
};

export const changePasswordPageGuard: CanActivateFn = async () => {
  const authStore = inject(AuthStore);
  const router = inject(Router);

  await authStore.restoreSession();

  if (!authStore.isAuthenticated()) {
    return router.createUrlTree(['/login']);
  }

  return authStore.mustChangePassword() ? true : router.createUrlTree(['/app/account/password']);
};
