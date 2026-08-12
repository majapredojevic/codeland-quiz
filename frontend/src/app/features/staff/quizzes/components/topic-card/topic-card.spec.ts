import { OverlayContainer } from '@angular/cdk/overlay';
import { ComponentFixture, TestBed } from '@angular/core/testing';

import { TopicItem } from '../../data-access/quizzes.models';
import { TopicCard } from './topic-card';

const topic: TopicItem = {
  id: 4,
  name: 'Scratch',
  description: 'Osnove programiranja',
  quizCount: 8,
  createdBy: { id: 1, name: 'Maja' },
  updatedBy: { id: 1, name: 'Maja' },
  createdAt: '',
  updatedAt: '',
};

describe('TopicCard', () => {
  let fixture: ComponentFixture<TopicCard>;
  let overlay: OverlayContainer;

  beforeEach(async () => {
    await TestBed.configureTestingModule({ imports: [TopicCard] }).compileComponents();
    fixture = TestBed.createComponent(TopicCard);
    fixture.componentRef.setInput('topic', topic);
    fixture.detectChanges();
    overlay = TestBed.inject(OverlayContainer);
  });

  it('renders the reliable backend quiz count and accessible pressed selection', () => {
    fixture.componentRef.setInput('selected', true);
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;
    const selection = element.querySelector<HTMLButtonElement>('.topic-select');
    expect(element.textContent).toContain('8 kvizova');
    expect(selection?.getAttribute('aria-pressed')).toBe('true');
    expect(selection?.getAttribute('aria-label')).toContain('Scratch');
  });

  it('keeps the overflow trigger separate and prevents deletion when quizzes exist', async () => {
    const trigger = (fixture.nativeElement as HTMLElement).querySelector<HTMLButtonElement>(
      '.menu-trigger',
    );
    expect(trigger?.getAttribute('aria-label')).toBe('Opcije za temu Scratch');
    trigger?.click();
    fixture.detectChanges();
    await fixture.whenStable();
    const deleteButton = Array.from(
      overlay.getContainerElement().querySelectorAll<HTMLButtonElement>('button'),
    ).find((button) => button.textContent?.includes('Obriši temu'));
    expect(deleteButton?.disabled).toBe(true);
    expect(deleteButton?.textContent).toContain(
      'Tema se može obrisati tek kada ne sadrži nijedan kviz.',
    );
  });

  it.each([
    [1, '1 kviz'],
    [2, '2 kviza'],
    [5, '5 kvizova'],
    [12, '12 kvizova'],
  ])('formats %i with local plural wording', (count, label) => {
    fixture.componentRef.setInput('topic', { ...topic, quizCount: count });
    fixture.detectChanges();
    expect((fixture.nativeElement as HTMLElement).textContent).toContain(label);
  });
});
