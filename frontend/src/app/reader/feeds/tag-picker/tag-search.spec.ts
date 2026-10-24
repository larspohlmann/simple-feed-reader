import { HttpErrorResponse } from '@angular/common/http';
import { signal } from '@angular/core';
import { Observable, Subject } from 'rxjs';
import { TagDto } from '../../models';
import { TagCreator } from './tag-creator.service';
import { TagEntry, TagSearch } from './tag-search';

const tag = (id: number, name: string, parentId: number | null = null): TagDto => ({
  id,
  name,
  color: null,
  icon: null,
  position: id,
  parentId,
});

describe('TagSearch', () => {
  const tags = signal<TagDto[]>([
    tag(1, 'News'),
    tag(2, 'News en', 1),
    tag(3, 'Politics'),
    tag(4, 'Lokales'),
  ]);
  const selected = signal<ReadonlySet<number>>(new Set([2]));
  const suggested = signal<TagDto[]>([tag(3, 'Politics')]);
  let toggled: number[];
  let created: Subject<TagDto>;
  let createdNames: string[];
  let search: TagSearch;

  const describeEntry = (entry: TagEntry): string =>
    entry.kind === 'create'
      ? `create:${entry.name}`
      : `${entry.group}:${entry.option.label}${entry.option.indented ? ' (indented)' : ''}${entry.option.selected ? ' ✓' : ''}`;
  const listed = (): string[] => search.entries().map(describeEntry);

  beforeEach(() => {
    toggled = [];
    createdNames = [];
    created = new Subject<TagDto>();
    const creator = {
      create: (name: string): Observable<TagDto> => {
        createdNames.push(name);
        return created;
      },
    } as unknown as TagCreator;
    search = new TagSearch(
      { tags, selected, suggested, toggle: (id) => toggled.push(id) },
      creator,
    );
  });

  it('browses suggestions first, then every tag in tree order with children indented', () => {
    expect(listed()).toEqual([
      'suggested:Politics',
      'all:News',
      'all:News / News en (indented) ✓',
      'all:Politics',
      'all:Lokales',
    ]);
  });

  it('matches the full label and offers to create a tag that does not exist', () => {
    search.setQuery('en');

    expect(listed()).toEqual(['matches:News / News en ✓', 'create:en']);
  });

  it('offers no create entry when a tag already has the name, whatever its case', () => {
    search.setQuery(' politics ');

    expect(listed()).toEqual(['matches:Politics']);
  });

  it('wraps the cursor at both ends', () => {
    search.move(-1);
    expect(search.activeIndex()).toBe(4);
    search.move(1);
    expect(search.activeIndex()).toBe(0);
  });

  it('toggles the active option and clears the query', () => {
    search.setQuery('lok');
    search.takeActive();

    expect(toggled).toEqual([4]);
    expect(search.query()).toBe('');
  });

  it('creates the typed tag, selects it and clears the query', () => {
    search.setQuery('Science ');
    search.move(1);
    search.takeActive();
    expect(search.creating()).toBe(true);

    search.create();
    created.next(tag(9, 'Science'));

    expect(createdNames).toEqual(['Science']);
    expect(toggled).toEqual([9]);
    expect(search.creating()).toBe(false);
    expect(search.query()).toBe('');
  });

  it('keeps the query and reports the problem when creating fails', () => {
    search.setQuery('Science');
    search.create();
    created.error(
      new HttpErrorResponse({
        status: 422,
        error: { type: 'about:blank', title: 'Invalid', detail: 'Name is taken' },
      }),
    );

    expect(search.error()).toBe('Name is taken');
    expect(search.query()).toBe('Science');
    expect(search.creating()).toBe(false);
  });
});
