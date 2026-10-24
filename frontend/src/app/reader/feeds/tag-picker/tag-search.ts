import { HttpErrorResponse } from '@angular/common/http';
import { Signal, computed, signal } from '@angular/core';
import { parseProblem, problemMessage } from '../../../core/problem';
import { TagDto } from '../../models';
import { tagLabel, tagsInTreeOrder } from '../../state/subscription-derivations';
import { TagCreator } from './tag-creator.service';

export interface TagOption {
  readonly id: number;
  readonly name: string;
  readonly parentName: string | null;
  readonly label: string;
  readonly icon: string | null;
  readonly color: string | null;
  readonly selected: boolean;
  readonly indented: boolean;
}

export type TagGroup = 'suggested' | 'all' | 'matches';

export type TagEntry =
  | { readonly kind: 'option'; readonly group: TagGroup; readonly option: TagOption }
  | { readonly kind: 'create'; readonly name: string };

/** What a picker surface edits: the caller's tags and selection, changed only through `toggle`. */
export interface TagSearchSource {
  readonly tags: Signal<TagDto[]>;
  readonly selected: Signal<ReadonlySet<number>>;
  readonly suggested: Signal<TagDto[]>;
  readonly toggle: (id: number) => void;
}

/**
 * The query, the grouped option list, the keyboard cursor and the create flow
 * behind both tag picker surfaces — the inline combobox and the phone sheet.
 * The cursor indexes `entries`, so narrowing the query never leaves it on a
 * row that is gone.
 */
export class TagSearch {
  readonly query = signal('');
  readonly activeIndex = signal(0);
  private readonly creatingName = signal<string | null>(null);
  readonly creating = computed(() => this.creatingName() !== null);
  readonly error = signal<string | null>(null);

  readonly entries = computed<TagEntry[]>(() => {
    const needle = this.query().trim().toLowerCase();
    return needle ? this.matchEntries(needle) : this.browseEntries();
  });

  constructor(
    private readonly source: TagSearchSource,
    private readonly creator: TagCreator,
  ) {}

  setQuery(text: string): void {
    this.query.set(text);
    this.activeIndex.set(0);
    this.error.set(null);
  }

  move(step: 1 | -1): void {
    const count = this.entries().length;
    if (count === 0) return;
    this.activeIndex.update((index) => (index + step + count) % count);
  }

  takeActive(): void {
    const entry = this.entries()[this.activeIndex()];
    if (!entry) return;
    if (entry.kind === 'create') this.create();
    else this.pick(entry.option.id);
  }

  pick(id: number): void {
    this.source.toggle(id);
    this.setQuery('');
  }

  create(): void {
    const name = this.query().trim();
    if (!name || this.creating()) return;
    this.creatingName.set(name);
    this.creator.create(name).subscribe({
      next: (tag) => {
        this.creatingName.set(null);
        this.pick(tag.id);
      },
      error: (error: HttpErrorResponse) => {
        this.creatingName.set(null);
        this.error.set(problemMessage(parseProblem(error)));
      },
    });
  }

  private browseEntries(): TagEntry[] {
    const suggested = this.source.suggested().map((tag): TagEntry => ({
      kind: 'option',
      group: 'suggested',
      option: this.optionOf(tag, false),
    }));
    const all = tagsInTreeOrder(this.source.tags()).map((tag): TagEntry => ({
      kind: 'option',
      group: 'all',
      option: this.optionOf(tag, tag.parentId !== null),
    }));
    return [...suggested, ...all];
  }

  private matchEntries(needle: string): TagEntry[] {
    const tags = this.source.tags();
    const matches = tagsInTreeOrder(tags)
      .filter((tag) => tagLabel(tag, tags).toLowerCase().includes(needle))
      .map((tag): TagEntry => ({
        kind: 'option',
        group: 'matches',
        option: this.optionOf(tag, false),
      }));
    const taken = tags.some((tag) => tag.name.toLowerCase() === needle);
    return taken ? matches : [...matches, { kind: 'create', name: this.query().trim() }];
  }

  private optionOf(tag: TagDto, indented: boolean): TagOption {
    const tags = this.source.tags();
    return {
      id: tag.id,
      name: tag.name,
      parentName: tags.find((candidate) => candidate.id === tag.parentId)?.name ?? null,
      label: tagLabel(tag, tags),
      icon: tag.icon,
      color: tag.color,
      selected: this.source.selected().has(tag.id),
      indented,
    };
  }
}
