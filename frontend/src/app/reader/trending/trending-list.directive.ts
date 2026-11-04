import { Directive, Signal, computed, inject } from '@angular/core';
import { ENTRY_ROW_ACTION, EntryRowAction } from '../entry/entry-actions/entry-row-action';
import { ReaderRouteState } from '../shell/reader-route-state.service';
import { TrendingHideAction } from './trending-hide-action';

function hideWhileTrendingIsShown(): Signal<EntryRowAction | null> {
  const routeState = inject(ReaderRouteState);
  const hide = inject(TrendingHideAction);
  return computed(() => (routeState.selection().kind === 'trending' ? hide : null));
}

/** Put on the reader's entry list: while it shows Trending, every card's action row ends in Hide (#2017, D40). */
@Directive({
  selector: '[appTrendingList]',
  providers: [
    TrendingHideAction,
    { provide: ENTRY_ROW_ACTION, useFactory: hideWhileTrendingIsShown },
  ],
})
export class TrendingListDirective {}
