import { HttpErrorResponse } from '@angular/common/http';
import { WritableSignal } from '@angular/core';
import { Problem, parseProblem } from '../core/problem';
import { saveAs } from './backup/save-as';
import { SettingsApi } from './settings-api';

/** Downloads the account's feeds as feeds.opml, threading loading/error state
 *  through the two signals the caller renders. OpmlSectionComponent's own
 *  export button and BackupSectionComponent's safety-net export both call
 *  this, so the blob shape, filename and error mapping have exactly one home. */
export function downloadOpmlExport(
  api: SettingsApi,
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
    error: (httpError: HttpErrorResponse) => {
      exporting.set(false);
      error.set(parseProblem(httpError));
    },
  });
}
