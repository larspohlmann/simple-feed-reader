import { Injectable, effect, inject, untracked } from '@angular/core';
import { ReaderRouteState } from './reader-route-state.service';
import { EntryStateActions } from './entry-state-actions.service';
import { ReaderOnboarding } from './reader-onboarding.service';
import { SubscriptionsStore } from '../state/subscriptions.store';
import { TagsStore } from '../state/tags.store';
import { EntriesStore } from '../state/entries.store';
import { RefreshService } from '../state/refresh.service';
import { RecommendationsService } from '../state/recommendations.service';
import { SavedSearchesStore } from '../state/saved-searches.store';
import { ListPreferences } from '../list/list-preferences.service';
import { queryFromSelection } from '../query/query';

/** The single owner of list reloads (#502). Injected for its effects. */
@Injectable()
export class ListReload {
  private readonly subs = inject(SubscriptionsStore);
  private readonly tags = inject(TagsStore);
  private readonly entries = inject(EntriesStore);
  private readonly refreshSvc = inject(RefreshService);
  private readonly recs = inject(RecommendationsService);
  private readonly savedSearchesStore = inject(SavedSearchesStore);
  private readonly listPreferences = inject(ListPreferences);
  private readonly entryActions = inject(EntryStateActions);
  private readonly onboarding = inject(ReaderOnboarding);
  private readonly selection = inject(ReaderRouteState).selection;

  constructor() {
    // Reload the list and sidebar counts whenever the selection (not the open
    // entry) changes. A new list has no removed rows, so clear the collapsed
    // set with it, else a recycled id would render an incoming row already collapsed.
    effect(() => {
      if (!this.listPreferences.ready()) return;
      const query = queryFromSelection(this.selection());
      untracked(() => {
        this.entryActions.clearLeaving();
        this.entries.load(query);
        this.subs.loadIfStale();
      });
    });

    // The onboarding sweep reloads on each landing slice, so a new user isn't
    // staring at an empty list (#127); a user-initiated refresh reloads once,
    // on finish, so it never flickers mid-sweep.
    effect(() => {
      const slice = this.refreshSvc.slice();
      const running = this.refreshSvc.running();
      untracked(() => {
        if (slice === 0) return; // nothing has reported yet
        if (!this.onboarding.sweeping() && running) return; // manual refresh: wait for finish
        this.subs.load();
        this.savedSearchesStore.load();
        // A refresh never touches tags, so reload them once when the run
        // finishes rather than on every onboarding slice (onDone reloaded them;
        // the old slice effect did not reload them at all).
        if (!running) this.tags.load();
        this.entries.load(queryFromSelection(this.selection()));
      });
    });

    // Reload the list when a for-you run completes while the user is already
    // on that feed. `completedStamp` starts at 0, which is the signal's
    // initial value, not a completion — the guard keeps a boot from reloading.
    effect(() => {
      if (this.recs.completedStamp() === 0) return;
      untracked(() => {
        if (this.selection().kind === 'for-you') this.entries.load({ view: 'for-you' });
      });
    });
  }
}
