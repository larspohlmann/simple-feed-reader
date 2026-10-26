import { Provider } from '@angular/core';
import { EMPTY } from 'rxjs';
import { EmbedChaptersApi } from '../app/reader/embed-chapters-api';

/** For a spec that renders an article body but asks nothing about its embeds' chapters. */
export const NO_EMBED_CHAPTERS: Provider = {
  provide: EmbedChaptersApi,
  useValue: { chaptersOf: () => EMPTY },
};
