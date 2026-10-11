import { Injectable } from '@angular/core';

/** Whether anything is still working on a pending entry's link card (#1516). With no poll to
 *  ask, a pending entry is taken at its word. */
@Injectable({ providedIn: 'root', useFactory: () => PENDING_MEANS_LOADING })
export abstract class PreviewProgress {
  abstract isLoading(entryId: number): boolean;
}

const PENDING_MEANS_LOADING: PreviewProgress = { isLoading: () => true };
