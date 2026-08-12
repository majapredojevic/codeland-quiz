import { Component, computed, input } from '@angular/core';

export interface AuditActor {
  id: number;
  name: string;
}

@Component({
  selector: 'clq-entity-audit-meta',
  templateUrl: './entity-audit-meta.html',
  styleUrl: './entity-audit-meta.scss',
})
export class EntityAuditMeta {
  readonly createdBy = input.required<AuditActor>();
  readonly updatedBy = input.required<AuditActor>();
  readonly createdAt = input.required<string>();
  readonly updatedAt = input.required<string>();

  protected readonly createdLabel = computed(() => this.formatDateTime(this.createdAt()));
  protected readonly updatedLabel = computed(() => this.formatDateTime(this.updatedAt()));
  protected readonly hasMeaningfulUpdate = computed(() => {
    const created = Date.parse(this.createdAt());
    const updated = Date.parse(this.updatedAt());
    return Number.isFinite(created) && Number.isFinite(updated) && updated > created;
  });

  private formatDateTime(value: string): string | null {
    const date = new Date(value);
    if (!Number.isFinite(date.getTime())) return null;

    const dateLabel = new Intl.DateTimeFormat('sr-Latn-BA', {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
    }).format(date);
    const timeLabel = new Intl.DateTimeFormat('sr-Latn-BA', {
      hour: '2-digit',
      minute: '2-digit',
      hour12: false,
    }).format(date);
    return `${dateLabel} u ${timeLabel}`;
  }
}
