import { ComponentFixture, TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { Observable, Subject } from 'rxjs';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { TagDto } from '../../models';
import { makeTag } from '../../testing/tag.factory';
import { TagCreator } from './tag-creator.service';
import { TagSearch } from './tag-search';
import { TagOptionListComponent } from './tag-option-list.component';

describe('TagOptionListComponent', () => {
  let fixture: ComponentFixture<TagOptionListComponent>;
  let search: TagSearch;
  let toggled: number[];
  let createdNames: string[];
  const element = (): HTMLElement => fixture.nativeElement;
  const rows = (): HTMLElement[] => [...element().querySelectorAll<HTMLElement>('[role="option"]')];

  beforeEach(async () => {
    toggled = [];
    createdNames = [];
    const creator = {
      create: (name: string): Observable<TagDto> => {
        createdNames.push(name);
        return new Subject<TagDto>();
      },
    } as unknown as TagCreator;
    search = new TagSearch(
      {
        tags: signal([
          makeTag(1, 'News'),
          makeTag(2, 'News en', { parentId: 1 }),
          makeTag(3, 'Politics'),
        ]),
        selected: signal(new Set([2])),
        suggested: signal([makeTag(3, 'Politics')]),
        toggle: (id) => toggled.push(id),
      },
      creator,
      'tags',
    );
    await TestBed.configureTestingModule({
      imports: [TagOptionListComponent, provideTranslocoTesting()],
    }).compileComponents();
    fixture = TestBed.createComponent(TagOptionListComponent);
    fixture.componentRef.setInput('search', search);
    fixture.detectChanges();
  });

  it('heads each browsing group once', () => {
    const headings = [...element().querySelectorAll('.heading')].map((heading) =>
      heading.textContent?.trim(),
    );
    expect(headings).toEqual(['Suggested', 'All tags']);
  });

  it('marks a selected option and names a match with its parent', () => {
    search.setQuery('en');
    fixture.detectChanges();

    const [row] = rows();
    expect(row.getAttribute('aria-selected')).toBe('true');
    expect(row.querySelector('.check')).not.toBeNull();
    expect(row.querySelector('.name')?.textContent?.replace(/\s+/g, ' ').trim()).toBe(
      'News / News en',
    );
  });

  it('indents each row by its depth and names a grandchild match with its path', () => {
    search = new TagSearch(
      {
        tags: signal([
          makeTag(1, 'Reading'),
          makeTag(2, 'Long reads', { parentId: 1 }),
          makeTag(3, 'Essays', { parentId: 2 }),
        ]),
        selected: signal(new Set<number>()),
        suggested: signal<TagDto[]>([]),
        toggle: () => undefined,
      },
      { create: () => new Subject<TagDto>() } as unknown as TagCreator,
      'nested',
    );
    fixture.componentRef.setInput('search', search);
    fixture.detectChanges();
    expect(rows().map((row) => row.style.getPropertyValue('--depth'))).toEqual(['0', '1', '2']);

    search.setQuery('Essays');
    fixture.detectChanges();

    expect(rows()[0].querySelector('.name')?.textContent?.replace(/\s+/g, ' ').trim()).toBe(
      'Reading / Long reads / Essays',
    );
  });

  it('marks the active row with its derived id', () => {
    search.move(1);
    fixture.detectChanges();

    expect(rows()[1].classList.contains('active')).toBe(true);
    expect(rows()[1].id).toBe('tags-option-1');
  });

  it('toggles a clicked option and creates from the create row', () => {
    search.setQuery('Ne');
    fixture.detectChanges();
    rows()[0].click();

    search.setQuery('Science');
    fixture.detectChanges();
    expect(rows()[0].textContent?.trim()).toContain('Create tag “Science”');
    rows()[0].click();

    expect(toggled).toEqual([1]);
    expect(createdNames).toEqual(['Science']);
  });

  it('says so when there is nothing to list', () => {
    const empty = new TagSearch(
      { tags: signal([]), selected: signal(new Set()), suggested: signal([]), toggle: jest.fn() },
      {} as TagCreator,
      'empty',
    );
    fixture.componentRef.setInput('search', empty);
    fixture.detectChanges();

    expect(element().querySelector('.empty')?.textContent?.trim()).toBe('No matching tags');
  });
});
