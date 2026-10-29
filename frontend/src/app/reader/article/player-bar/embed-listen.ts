import type { EmbedMedia } from '../../audio/embed-media';

export interface ListenActions {
  listen(media: EmbedMedia, frame: HTMLIFrameElement): void;
  toggleQueued(media: EmbedMedia): void;
}

export interface EmbedListen {
  readonly media: EmbedMedia;
  listen(): void;
  toggleQueued(): void;
}
