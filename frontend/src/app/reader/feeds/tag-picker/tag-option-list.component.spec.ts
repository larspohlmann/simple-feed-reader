import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { TagEntry, TagOption } from './tag-search';
import { TagOptionListComponent } from './tag-option-list.component';

const option = (id: number, name: string, extra: Partial<TagOption> = {}): TagOption => ({
  id,
  name,
  parentName: null,
  label: name,
  icon: null,
  color: null,
  selected: false,
  indented: false,
  ...extra,
});

describe('TagOptionListComponent', () => {
  let fixture: ComponentFixture<TagOptionListComponent>;
  const element = (): HTMLElement => fixture.nativeElement;
  const rows = (): HTMLElement[] => [...element().querySelectorAll<HTMLElement>('[role="option"]')];

  function render(entries: TagEntry[], activeIndex = 0): void {
    fixture.componentRef.setInput('entries', entries);
    fixture.componentRef.setInput('activeIndex', activeIndex);
    fixture.detectChanges();
  }

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [TagOptionListComponent, provideTranslocoTesting()],
    }).compileComponents();
    fixture = TestBed.createComponent(TagOptionListComponent);
    fixture.componentRef.setInput('listId', 'tags');
  });

  it('heads each browsing group once', () => {
    render([
      { kind: 'option', group: 'suggested', option: option(3, 'Politics') },
      { kind: 'option', group: 'all', option: option(1, 'News') },
      { kind: 'option', group: 'all', option: option(3, 'Politics') },
    ]);

    const headings = [...element().querySelectorAll('.heading')].map((heading) =>
      heading.textContent?.trim(),
    );
    expect(headings).toEqual(['Suggested', 'All tags']);
  });

  it('marks a selected option and names a match with its parent', () => {
    render([
      {
        kind: 'option',
        group: 'matches',
        option: option(2, 'News en', { parentName: 'News', selected: true }),
      },
    ]);

    const [row] = rows();
    expect(row.getAttribute('aria-selected')).toBe('true');
    expect(row.querySelector('.check')).not.toBeNull();
    expect(row.querySelector('.name')?.textContent?.replace(/\s+/g, ' ').trim()).toBe(
      'News / News en',
    );
  });

  it('marks the active row with its derived id', () => {
    render(
      [
        { kind: 'option', group: 'matches', option: option(1, 'News') },
        { kind: 'option', group: 'matches', option: option(3, 'Politics') },
      ],
      1,
    );

    expect(rows()[1].classList.contains('active')).toBe(true);
    expect(rows()[1].id).toBe('tags-option-1');
  });

  it('emits the picked id and the create request', () => {
    const picked: number[] = [];
    let createRequests = 0;
    fixture.componentInstance.picked.subscribe((id) => picked.push(id));
    fixture.componentInstance.createRequested.subscribe(() => createRequests++);
    render([
      { kind: 'option', group: 'matches', option: option(1, 'News') },
      { kind: 'create', name: 'Ne' },
    ]);

    rows()[0].click();
    rows()[1].click();

    expect(picked).toEqual([1]);
    expect(createRequests).toBe(1);
    expect(rows()[1].textContent?.trim()).toContain('Create tag “Ne”');
  });

  it('says so when nothing matches', () => {
    render([]);

    expect(element().querySelector('.empty')?.textContent?.trim()).toBe('No matching tags');
  });
});
