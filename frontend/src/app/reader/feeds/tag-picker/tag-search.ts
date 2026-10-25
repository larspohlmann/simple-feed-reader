import { HttpErrorResponse } from '@angular/common/http';
import { Signal, computed, signal } from '@angular/core';
import { parseProblem, problemMessage } from '../../../core/problem';
import { TagDto } from '../../models';
import { tagPath, tagsInTreeOrder } from '../../state/subscription-derivations';
import { TagCreator } from './tag-creator.service';

export interface TagOption {
  readonly id: number;
  readonly name: string;
  readonly ancestorPath: string | null;
  readonly label: string;
  readonly icon: string | null;
  readonly color: string | null;
  readonly selected: boolean;
  readonly depth: number;
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

interface IndexedTag {
  readonly tag: TagDto;
  readonly ancestorPath: string | null;
  readonly depth: number;
  readonly label: string;
  readonly searchText: string;
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
  readonly creating = signal(false);
  readonly error = signal<string | null>(null);

  private readonly indexed = computed(() => {
    const tags = this.source.tags();
    const byId = new Map(tags.map((tag) => [tag.id, tag]));
    return tagsInTreeOrder(tags).map((tag): IndexedTag => {
      const path = tagPath(tag, byId);
      const label = path.join(' / ');
      const ancestorPath = path.length > 1 ? path.slice(0, -1).join(' / ') : null;
      return { tag, ancestorPath, depth: path.length - 1, label, searchText: label.toLowerCase() };
    });
  });

  private readonly indexedById = computed(
    () => new Map(this.indexed().map((indexed) => [indexed.tag.id, indexed])),
  );

  readonly chips = computed(() =>
    this.indexed()
      .filter((indexed) => this.source.selected().has(indexed.tag.id))
      .map((indexed) => this.optionOf(indexed, false)),
  );

  readonly suggestions = computed(() =>
    this.source
      .suggested()
      .flatMap((tag) => this.indexedById().get(tag.id) ?? [])
      .map((indexed) => this.optionOf(indexed, false)),
  );

  readonly entries = computed<TagEntry[]>(() => {
    const needle = this.query().trim().toLowerCase();
    return needle ? this.matchEntries(needle) : this.browseEntries();
  });

  readonly activeOptionId = computed(() =>
    this.entries().length ? this.optionId(this.activeIndex()) : null,
  );

  constructor(
    private readonly source: TagSearchSource,
    private readonly creator: TagCreator,
    readonly listId: string,
  ) {}

  optionId(index: number): string {
    return `${this.listId}-option-${index}`;
  }

  setQuery(text: string): void {
    this.query.set(text);
    this.activeIndex.set(0);
    this.error.set(null);
  }

  /** The list keys of the combobox pattern, plus Backspace on an empty query dropping the last chip. */
  handleKey(event: KeyboardEvent): void {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      this.move(event.key === 'ArrowDown' ? 1 : -1);
    } else if (event.key === 'Enter') {
      event.preventDefault();
      this.takeActive();
    } else if (event.key === 'Backspace' && this.query() === '') {
      this.removeLastChip();
    }
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
    this.creating.set(true);
    this.creator.create(name).subscribe({
      next: (tag) => {
        this.creating.set(false);
        this.pick(tag.id);
      },
      error: (error: HttpErrorResponse) => {
        this.creating.set(false);
        this.error.set(problemMessage(parseProblem(error)));
      },
    });
  }

  private removeLastChip(): void {
    const last = this.chips().at(-1);
    if (last) this.source.toggle(last.id);
  }

  private browseEntries(): TagEntry[] {
    const suggested = this.suggestions().map((option): TagEntry => ({
      kind: 'option',
      group: 'suggested',
      option,
    }));
    const all = this.indexed().map((indexed): TagEntry => ({
      kind: 'option',
      group: 'all',
      option: this.optionOf(indexed, true),
    }));
    return [...suggested, ...all];
  }

  private matchEntries(needle: string): TagEntry[] {
    const matches = this.indexed()
      .filter((indexed) => indexed.searchText.includes(needle))
      .map((indexed): TagEntry => ({
        kind: 'option',
        group: 'matches',
        option: this.optionOf(indexed, false),
      }));
    const taken = this.indexed().some((indexed) => indexed.tag.name.toLowerCase() === needle);
    return taken ? matches : [...matches, { kind: 'create', name: this.query().trim() }];
  }

  private optionOf(indexed: IndexedTag, nested: boolean): TagOption {
    const { tag } = indexed;
    return {
      id: tag.id,
      name: tag.name,
      ancestorPath: indexed.ancestorPath,
      label: indexed.label,
      icon: tag.icon,
      color: tag.color,
      selected: this.source.selected().has(tag.id),
      depth: nested ? indexed.depth : 0,
    };
  }
}
