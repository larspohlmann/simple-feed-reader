import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { TagChipsComponent } from './tag-chips.component';
import { TagOption } from './tag-search';

const TECH: TagOption = {
  id: 1,
  name: 'Tech',
  ancestorPath: null,
  label: 'Tech',
  icon: null,
  color: null,
  selected: true,
  depth: 0,
};

describe('TagChipsComponent', () => {
  let fixture: ComponentFixture<TagChipsComponent>;
  let removed: number[];
  let leadRemoved: number;

  const element = (): HTMLElement => fixture.nativeElement;
  const labels = (): string[] =>
    [...element().querySelectorAll('.chip .label')].map((label) => label.textContent!.trim());

  function mount(lead: { label: string; icon: string } | null): void {
    fixture = TestBed.createComponent(TagChipsComponent);
    fixture.componentRef.setInput('chips', [TECH]);
    fixture.componentRef.setInput('lead', lead);
    fixture.componentInstance.removed.subscribe((id) => removed.push(id));
    fixture.componentInstance.leadRemoved.subscribe(() => leadRemoved++);
    fixture.detectChanges();
  }

  beforeEach(() => {
    removed = [];
    leadRemoved = 0;
    TestBed.configureTestingModule({ imports: [TagChipsComponent, provideTranslocoTesting()] });
  });

  it('shows no lead chip by default', () => {
    mount(null);

    expect(labels()).toEqual(['Tech']);
  });

  it('puts the lead chip first', () => {
    mount({ label: 'News', icon: 'folder' });

    expect(labels()).toEqual(['News', 'Tech']);
  });

  it('removing the lead chip emits leadRemoved, not a tag id', () => {
    mount({ label: 'News', icon: 'folder' });

    const remove = element().querySelector<HTMLButtonElement>('.chip .remove')!;
    expect(remove.getAttribute('aria-label')).toBe('Remove News');
    remove.click();

    expect(leadRemoved).toBe(1);
    expect(removed).toEqual([]);
  });
});
