import { NgOptimizedImage } from '@angular/common';
import {
  afterNextRender,
  Component,
  DestroyRef,
  ElementRef,
  inject,
  Injector,
  signal,
  viewChild,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { MatMenu, MatMenuItem, MatMenuTrigger } from '@angular/material/menu';
import { NavigationEnd, Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { filter } from 'rxjs';

import { AuthStore } from '../../auth/auth.store';
import { StaffUserSummary } from './staff-user-summary';

@Component({
  selector: 'clq-staff-shell',
  imports: [
    NgOptimizedImage,
    MatMenu,
    MatMenuItem,
    MatMenuTrigger,
    RouterLink,
    RouterLinkActive,
    RouterOutlet,
    StaffUserSummary,
  ],
  templateUrl: './staff-shell.html',
  styleUrl: './staff-shell.scss',
  host: {
    '(document:keydown.escape)': 'closeNavigation(true)',
  },
})
export class StaffShell {
  private readonly authStore = inject(AuthStore);
  private readonly injector = inject(Injector);
  private readonly router = inject(Router);
  private readonly destroyRef = inject(DestroyRef);
  private readonly navigationClose =
    viewChild.required<ElementRef<HTMLButtonElement>>('navigationClose');
  private readonly navigationToggle =
    viewChild.required<ElementRef<HTMLButtonElement>>('navigationToggle');

  protected readonly user = this.authStore.user;
  protected readonly isAdmin = this.authStore.isAdmin;
  protected readonly isNavigationOpen = signal(false);
  protected readonly isPresentationMode = signal(false);
  protected readonly isLoggingOut = signal(false);
  protected readonly logoutError = signal<string | null>(null);

  constructor() {
    this.updatePresentationMode(this.router.url);
    this.router.events
      .pipe(
        filter((event): event is NavigationEnd => event instanceof NavigationEnd),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe((event) => {
        this.closeNavigation(false);
        this.updatePresentationMode(event.urlAfterRedirects);
      });
  }

  protected toggleNavigation(): void {
    if (this.isNavigationOpen()) {
      this.closeNavigation(true);
      return;
    }

    this.isNavigationOpen.set(true);
    afterNextRender(() => this.navigationClose().nativeElement.focus(), {
      injector: this.injector,
    });
  }

  protected closeNavigation(restoreFocus = false): void {
    if (!this.isNavigationOpen()) {
      return;
    }

    this.isNavigationOpen.set(false);

    if (restoreFocus) {
      afterNextRender(() => this.navigationToggle().nativeElement.focus(), {
        injector: this.injector,
      });
    }
  }

  protected async logout(): Promise<void> {
    if (this.isLoggingOut()) {
      return;
    }

    this.isLoggingOut.set(true);
    this.logoutError.set(null);

    try {
      await this.authStore.logout();
    } catch {
      this.logoutError.set('Odjava trenutno nije moguća. Pokušajte ponovo.');
    } finally {
      this.isLoggingOut.set(false);
    }
  }

  private updatePresentationMode(url: string): void {
    this.isPresentationMode.set(/^\/app\/sessions\/\d+(?:[/?#]|$)/.test(url));
  }
}
