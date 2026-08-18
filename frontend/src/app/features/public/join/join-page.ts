import { NgOptimizedImage } from '@angular/common';
import { Component, computed, inject, OnInit, signal } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';

@Component({
  selector: 'clq-join-page',
  imports: [NgOptimizedImage, RouterLink],
  templateUrl: './join-page.html',
  styleUrl: './join-page.scss',
})
export class JoinPage implements OnInit {
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);

  protected readonly gamePin = signal('');
  protected readonly isGamePinValid = computed(() => /^\d{6}$/.test(this.gamePin()));

  ngOnInit(): void {
    const queryPin = this.route.snapshot.queryParamMap.get('pin');
    if (queryPin === null) return;
    const normalizedPin = this.normalizedPin(queryPin);
    this.gamePin.set(normalizedPin);
    if (/^\d{6}$/.test(normalizedPin)) {
      void this.router.navigate(['/join', normalizedPin], { replaceUrl: true });
    }
  }

  protected updateGamePin(event: Event): void {
    const input = event.target;

    if (!(input instanceof HTMLInputElement)) {
      return;
    }

    const normalizedPin = this.normalizedPin(input.value);
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

  private normalizedPin(value: string): string {
    return value.replace(/\D/g, '').slice(0, 6);
  }
}
