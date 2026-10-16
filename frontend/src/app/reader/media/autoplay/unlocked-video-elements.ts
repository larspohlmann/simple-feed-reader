import { Injectable } from '@angular/core';

export const SPARE_VIDEO_ELEMENTS = 40;

/** Video elements iOS lets play with sound later: it unlocks per element, and only inside a tap. */
@Injectable()
export class UnlockedVideoElements {
  private readonly spares: HTMLVideoElement[] = [];

  take(): HTMLVideoElement {
    return this.spares.pop() ?? document.createElement('video');
  }

  /** Must run synchronously inside a tap's event handler. */
  refill(): void {
    while (this.spares.length < SPARE_VIDEO_ELEMENTS) {
      const video = document.createElement('video');
      video.muted = true;
      video.muted = false;
      this.spares.push(video);
    }
  }
}
