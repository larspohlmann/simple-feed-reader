import type { EmbedMedia } from '../../audio/embed-media';

export interface ListenActions {
  listen(media: EmbedMedia, frame: HTMLIFrameElement): void;
  toggleQueued(media: EmbedMedia): void;
}

/** What a player bar needs to offer Listen and the queue toggle for one embed (#1815, #1888). */
export interface EmbedListen {
  readonly media: EmbedMedia;
  readonly frame: HTMLIFrameElement;
  readonly actions: ListenActions;
}
