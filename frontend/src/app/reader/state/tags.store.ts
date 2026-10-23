import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { Problem, parseProblem } from '../../core/problem';
import { ReaderApi } from '../reader-api';
import { TagDto } from '../models';

/** Position order, name as a stable tiebreak; a position orders a tag among its siblings only. */
export function compareTagPositions(left: TagDto, right: TagDto): number {
  return left.position - right.position || left.name.localeCompare(right.name);
}

/** The complete tag list from GET /api/tags — including tags with zero feeds,
 *  which the sidebar's subscription-derived tree never shows. Management reads
 *  this; mutations happen through ReaderApi and callers call load() to re-sync. */
@Injectable({ providedIn: 'root' })
export class TagsStore {
  private readonly api = inject(ReaderApi);

  readonly tags = signal<TagDto[]>([]);
  /** The tags that may be a parent: only a root can take children (#1736). */
  readonly rootTags = computed(() => this.tags().filter((tag) => tag.parentId === null));
  readonly loading = signal(false);
  readonly error = signal<Problem | null>(null);

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
