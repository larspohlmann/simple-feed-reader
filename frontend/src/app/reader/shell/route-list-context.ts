import { Injectable, Provider, inject } from '@angular/core';
import { EntriesStore } from '../state/entries.store';
import { ListContext } from '../state/list-context';
import { OpenStory } from '../state/open-story';
import { ListCover } from './list-cover';
import { ReaderRouteState } from './reader-route-state.service';

/** The list the reader's URL names. */
@Injectable()
export class RouteListContext extends ListContext {
  readonly entries = inject(EntriesStore);
  readonly selection = inject(ReaderRouteState).selection;
}

/** The route's list as the reader shell's list context, and the cover a layer's list may lay over it (#2329). */
export function provideRouteList(): Provider[] {
  return [ListCover, { provide: ListContext, useClass: RouteListContext }, OpenStory];
}
