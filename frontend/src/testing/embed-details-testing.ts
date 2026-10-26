import { Provider } from '@angular/core';
import { EMPTY } from 'rxjs';
import { EmbedDetailsApi } from '../app/reader/embed-details-api';

/** For a spec that renders an article body but asks nothing about its embeds. */
export const NO_EMBED_DETAILS: Provider = {
  provide: EmbedDetailsApi,
  useValue: { detailsOf: () => EMPTY },
};
