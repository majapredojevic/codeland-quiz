import { Component, computed, input } from '@angular/core';

import { formatCodeLandDateTime } from '../../utils/date-formatters';

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

  protected readonly createdLabel = computed(() => this.auditLabel(this.createdAt()));
  protected readonly updatedLabel = computed(() => this.auditLabel(this.updatedAt()));
  protected readonly hasMeaningfulUpdate = computed(() => {
    const created = Date.parse(this.createdAt());
    const updated = Date.parse(this.updatedAt());
    return Number.isFinite(created) && Number.isFinite(updated) && updated > created;
  });

  private auditLabel(value: string): string | null {
    const formatted = formatCodeLandDateTime(value);
    return formatted === '—' ? null : formatted;
  }
}
