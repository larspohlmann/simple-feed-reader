import { InjectionToken } from '@angular/core';

/** Absent where the page is no entry, as in the link reader. */
export interface ShareRecorder {
  record(): void;
}

export const SHARE_RECORDER = new InjectionToken<ShareRecorder>('ShareRecorder');
