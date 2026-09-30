import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { Problem, parseProblem } from '../../core/problem';
import { ReaderApi } from '../reader-api';
import { TagDto } from '../models';

/** The complete tag list from GET /api/tags — including tags with zero feeds,
 *  which the sidebar's subscription-derived tree never shows. Management reads
 *  this; mutations happen through ReaderApi and callers call load() to re-sync. */
@Injectable({ providedIn: 'root' })
export class TagsStore {
  private readonly api = inject(ReaderApi);

  readonly tags = signal<TagDto[]>([]);
  readonly loading = signal(false);
  readonly error = signal<Problem | null>(null);

  load(): void {
    this.loading.set(true);
    this.error.set(null);
    this.api.tags().subscribe({
      next: (response) => {
        this.tags.set(
          [...response.tags].sort(
            (left, right) => left.position - right.position || left.name.localeCompare(right.name),
          ),
        );
        this.loading.set(false);
      },
      error: (error: HttpErrorResponse) => {
        this.error.set(parseProblem(error));
        this.loading.set(false);
      },
    });
  }
}
