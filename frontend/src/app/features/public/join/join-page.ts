import { NgOptimizedImage } from '@angular/common';
import { Component, computed, inject, signal } from '@angular/core';
import { Router, RouterLink } from '@angular/router';

@Component({
  selector: 'clq-join-page',
  imports: [NgOptimizedImage, RouterLink],
  templateUrl: './join-page.html',
  styleUrl: './join-page.scss',
})
export class JoinPage {
  private readonly router = inject(Router);

  protected readonly gamePin = signal('');
  protected readonly isGamePinValid = computed(() => /^\d{6}$/.test(this.gamePin()));

  protected updateGamePin(event: Event): void {
    const input = event.target;

    if (!(input instanceof HTMLInputElement)) {
      return;
    }

    const normalizedPin = input.value.replace(/\D/g, '').slice(0, 6);
    input.value = normalizedPin;
    this.gamePin.set(normalizedPin);
  }

  protected joinGame(event: SubmitEvent): void {
    event.preventDefault();

    if (!this.isGamePinValid()) {
      return;
    }

    void this.router.navigate(['/join', this.gamePin()]);
  }
}
