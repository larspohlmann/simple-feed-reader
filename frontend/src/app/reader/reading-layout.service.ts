import { Injectable, signal } from '@angular/core';

/** The media-first views, each projecting the current selection through the
 *  entries' `media[]`/`attachments[]` rather than listing whole entries (#916). */
export type MediaView = 'pictures' | 'videos' | 'audios';
export type ReadingLayout = 'list' | 'pane' | 'magazine' | MediaView;
const KEY = 'sfr.layout';
const MEDIA_VIEWS: MediaView[] = ['pictures', 'videos', 'audios'];
const MODES: ReadingLayout[] = ['list', 'pane', 'magazine', ...MEDIA_VIEWS];

/** Whether a layout is one of the media-first projections. */
export function isMediaView(layout: ReadingLayout): layout is MediaView {
  return (MEDIA_VIEWS as ReadingLayout[]).includes(layout);
}

@Injectable({ providedIn: 'root' })
export class ReadingLayoutService {
  readonly mode = signal<ReadingLayout>(this.readSaved());

  set(mode: ReadingLayout): void {
    localStorage.setItem(KEY, mode);
    this.mode.set(mode);
  }

  private readSaved(): ReadingLayout {
    const saved = localStorage.getItem(KEY) as ReadingLayout | null;
    return saved && MODES.includes(saved) ? saved : 'magazine';
  }
}
