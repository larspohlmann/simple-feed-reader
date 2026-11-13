import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { Problem, parseProblem } from '../../core/problem';
import { ReaderApi } from '../reader-api';
import { TagDto, TagInclusion } from '../models';
import { TagHierarchy } from './tag-hierarchy';

/** Position order, name as a stable tiebreak; a position orders a tag among its siblings only. */
export function compareTagPositions(left: TagDto, right: TagDto): number {
  return left.position - right.position || left.name.localeCompare(right.name);
}

/** Stands in for a tag the list has not loaded yet, until the reload brings the real one. */
export function placeholderTag(id: number): TagDto {
  return {
    id,
    name: '',
    color: null,
    icon: null,
    position: 0,
    parentId: null,
    includeInAllItems: true,
    includeInForYou: true,
    includeInSavedSearches: true,
  };
}

/** The complete tag list from GET /api/tags — including tags with zero feeds,
 *  which the sidebar's subscription-derived tree never shows. Management reads
 *  this; mutations happen through ReaderApi and callers call load() to re-sync. */
@Injectable({ providedIn: 'root' })
export class TagsStore {
  private readonly api = inject(ReaderApi);

  readonly tags = signal<TagDto[]>([]);
  readonly hierarchy = computed(() => new TagHierarchy(this.tags()));
  readonly loading = signal(false);
  readonly error = signal<Problem | null>(null);

  add(tag: TagDto): void {
    this.tags.update((tags) => [...tags, tag].sort(compareTagPositions));
  }

  /** Optimistically switch one tag in or out of a surface; the caller reconciles with the server. */
  patchInclusion(tagId: number, inclusion: Partial<TagInclusion>): void {
    this.tags.update((tags) =>
      tags.map((tag) => (tag.id === tagId ? { ...tag, ...inclusion } : tag)),
    );
  }

  load(): void {
    this.loading.set(true);
    this.error.set(null);
    this.api.tags().subscribe({
      next: (response) => {
        this.tags.set([...response.tags].sort(compareTagPositions));
        this.loading.set(false);
      },
      error: (error: HttpErrorResponse) => {
        this.error.set(parseProblem(error));
        this.loading.set(false);
      },
    });
  }
}
