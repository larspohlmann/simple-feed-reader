import type { EmbedMedia } from '../app/reader/audio/embed-media';

export const VIDEO_ID = 'dQw4w9WgXcQ';
export const WATCH_URL = `https://www.youtube.com/watch?v=${VIDEO_ID}`;
export const POSTER_URL = `https://i.ytimg.com/vi/${VIDEO_ID}/hqdefault.jpg`;
export const WIDGET_URL =
  'https://w.soundcloud.com/player/?url=https%3A%2F%2Fapi.soundcloud.com%2Ftracks%2F123456789';

export const VIDEO_MEDIA: EmbedMedia = { kind: 'youtube', url: WATCH_URL, posterUrl: POSTER_URL };
export const SOUND_MEDIA: EmbedMedia = { kind: 'soundcloud', url: WIDGET_URL, posterUrl: null };
