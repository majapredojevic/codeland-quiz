import { ComponentFixture, TestBed } from '@angular/core/testing';

import { EntityAuditMeta } from './entity-audit-meta';

describe('EntityAuditMeta', () => {
  let fixture: ComponentFixture<EntityAuditMeta>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({ imports: [EntityAuditMeta] }).compileComponents();
    fixture = TestBed.createComponent(EntityAuditMeta);
    fixture.componentRef.setInput('createdBy', { id: 1, name: 'Maja' });
    fixture.componentRef.setInput('updatedBy', { id: 2, name: 'Marko' });
  });

  it('shows only canonical creator metadata when timestamps represent the same instant', () => {
    fixture.componentRef.setInput('createdAt', '2026-08-12T16:10:00+00:00');
    fixture.componentRef.setInput('updatedAt', '2026-08-12T16:10:00Z');
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;
    expect(element.textContent).toContain('Kreirao: Maja');
    expect(element.textContent).not.toContain('Posljednja izmjena');
    expect(element.textContent).not.toContain('2026-08-12T16:10:00');
  });

  it('shows the canonical updater only after a meaningful later update', () => {
    fixture.componentRef.setInput('createdAt', '2026-08-12T16:10:00Z');
    fixture.componentRef.setInput('updatedAt', '2026-08-12T16:42:00Z');
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;
    expect(element.textContent).toContain('Kreirao: Maja');
    expect(element.textContent).toContain('Posljednja izmjena: Marko');
  });

  it('renders nothing for invalid timestamps instead of exposing raw values', () => {
    fixture.componentRef.setInput('createdAt', 'not-a-date');
    fixture.componentRef.setInput('updatedAt', 'not-a-date');
    fixture.detectChanges();
    expect((fixture.nativeElement as HTMLElement).textContent?.trim()).toBe('');
  });
});
