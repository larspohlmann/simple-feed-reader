// src/app/core/opml-export.ts
import { HttpErrorResponse } from '@angular/common/http';
import { WritableSignal } from '@angular/core';
import { Problem, parseProblem } from './problem';
import { saveAs } from './save-as';
import { ReaderApi } from '../reader/reader-api';

/** Downloads the account's feeds as feeds.opml, threading loading/error state
 *  through the two signals the caller renders. OpmlSectionComponent's own
 *  export button and BackupSectionComponent's safety-net export both call
 *  this, so the blob shape, filename and error mapping have exactly one home. */
export function downloadOpmlExport(
  api: ReaderApi,
  exporting: WritableSignal<boolean>,
  error: WritableSignal<Problem | null>,
): void {
  exporting.set(true);
  error.set(null);
  api.exportOpml().subscribe({
    next: (xml) => {
      exporting.set(false);
      saveAs(new Blob([xml], { type: 'text/x-opml' }), 'feeds.opml');
    },
    error: (e: HttpErrorResponse) => {
      exporting.set(false);
      error.set(parseProblem(e));
    },
  });
}
