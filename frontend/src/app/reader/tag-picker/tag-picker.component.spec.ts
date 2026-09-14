import { ComponentFixture, TestBed } from '@angular/core/testing';
import { TagPickerComponent } from './tag-picker.component';
import { TagDto } from '../models';

describe('TagPickerComponent', () => {
  let fixture: ComponentFixture<TagPickerComponent>;

  const tags: TagDto[] = [
    { id: 1, name: 'Tech', color: null, icon: 'memory', position: 0 },
    { id: 2, name: 'News', color: '#0a0', icon: null, position: 1 },
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
    expect(pills().length).toBe(2);
  });

  it('marks selected tags pressed and the rest not', () => {
    const [tech, news] = pills();
    expect(tech.getAttribute('aria-pressed')).toBe('true');
    expect(tech.classList.contains('on')).toBe(true);
    expect(news.getAttribute('aria-pressed')).toBe('false');
    expect(news.classList.contains('on')).toBe(false);
  });

  it('emits the tag id when a pill is clicked', () => {
    const seen: number[] = [];
    fixture.componentInstance.toggled.subscribe((id) => seen.push(id));

    pills()[1].click();

    expect(seen).toEqual([2]);
  });
});
