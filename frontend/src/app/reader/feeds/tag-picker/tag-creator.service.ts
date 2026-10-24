import { Injectable, inject } from '@angular/core';
import { Observable, map, tap } from 'rxjs';
import { ReaderApi } from '../../reader-api';
import { TagDto } from '../../models';
import { TagsStore } from '../../state/tags.store';

/** Creates a top-level tag by name from a picker; colour, icon and parent are set later in tag settings. */
@Injectable({ providedIn: 'root' })
export class TagCreator {
  private readonly api = inject(ReaderApi);
  private readonly tagsStore = inject(TagsStore);

  create(name: string): Observable<TagDto> {
    return this.api.createTag({ name, color: null, icon: null, parentId: null }).pipe(
      map((response) => response.tag),
      tap((tag) => this.tagsStore.add(tag)),
    );
  }
}
