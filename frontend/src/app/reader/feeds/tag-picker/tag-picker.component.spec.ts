import { ComponentFixture, TestBed } from '@angular/core/testing';
import { TagPickerComponent } from './tag-picker.component';
import { TagDto } from '../../models';

describe('TagPickerComponent', () => {
  let fixture: ComponentFixture<TagPickerComponent>;

  const tags: TagDto[] = [
    { id: 1, name: 'Tech', color: null, icon: 'memory', position: 0, parentId: null },
    { id: 3, name: 'Long reads', color: null, icon: null, position: 2, parentId: 2 },
    { id: 2, name: 'Reading', color: '#0a0', icon: null, position: 1, parentId: null },
  ];

  const pills = (): HTMLButtonElement[] => [
    ...fixture.nativeElement.querySelectorAll('button.tag-pill'),
  ];

  beforeEach(async () => {
    await TestBed.configureTestingModule({ imports: [TagPickerComponent] }).compileComponents();
    fixture = TestBed.createComponent(TagPickerComponent);
    fixture.componentRef.setInput('tags', tags);
    fixture.componentRef.setInput('selected', new Set<number>([1]));
    fixture.detectChanges();
  });

  it('renders one pill per tag', () => {
    expect(pills().length).toBe(3);
  });

  it('lists each root followed by its children, naming a child with its parent', () => {
    const labels = pills().map((pill) =>
      [...pill.childNodes]
        .filter((node) => node.nodeType === Node.TEXT_NODE)
        .map((node) => node.textContent)
        .join('')
        .trim(),
    );

    expect(labels).toEqual(['Tech', 'Reading', 'Reading / Long reads']);
  });

  it('marks selected tags pressed and the rest not', () => {
    const [tech, reading] = pills();
    expect(tech.getAttribute('aria-pressed')).toBe('true');
    expect(tech.classList.contains('on')).toBe(true);
    expect(reading.getAttribute('aria-pressed')).toBe('false');
    expect(reading.classList.contains('on')).toBe(false);
  });

  it('emits the tag id when a pill is clicked', () => {
    const seen: number[] = [];
    fixture.componentInstance.toggled.subscribe((id) => seen.push(id));

    pills()[1].click();

    expect(seen).toEqual([2]);
  });
});
