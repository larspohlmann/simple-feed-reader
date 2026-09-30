import { Component, computed, effect, input, output, signal, untracked } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { SavedSearchDto } from '../../models';

/** How many saved-search rows the sidebar shows before the "Show more" link. */
const SIDEBAR_SAVED_SEARCH_LIMIT = 6;

/** Ids of a saved-search list, ranked unread-first (count desc) then newest
 *  first (id desc). Id is the creation-order proxy — there is no date field. */
const rankedSavedSearchIds = (searches: readonly SavedSearchDto[]): number[] =>
  [...searches]
    .sort((left, right) => right.unreadCount - left.unreadCount || right.id - left.id)
    .map((search) => search.id);

const sameIds = (current: readonly number[], frozen: readonly number[]): boolean => {
  if (current.length !== frozen.length) return false;
  const set = new Set(frozen);
  return current.every((id) => set.has(id));
};

/** The sidebar's "Saved searches" group. Always rendered, so its frozen order
 *  and expanded state survive the list emptying and refilling. */
@Component({
  selector: 'app-sidebar-saved-searches',
  imports: [RouterLink, TranslocoPipe, IconComponent],
  templateUrl: './sidebar-saved-searches.component.html',
  styleUrl: './sidebar-saved-searches.component.scss',
})
export class SidebarSavedSearchesComponent {
  readonly savedSearches = input<SavedSearchDto[]>([]);
  /** The saved search the list is currently showing, by id, or null. */
  readonly activeSavedSearchId = input<number | null>(null);
  /** The combined saved-searches list is the current selection. */
  readonly combinedActive = input(false);
  /** The trailing envelope button shows only when mail can send AND the account
   *  digest is on. The row styles itself around its presence (#636). */
  readonly showDigestToggles = input(false);

  /** The mail icon on a row was clicked; the shell confirms and flips `includeInDigest`. */
  readonly toggleDigest = output<SavedSearchDto>();

  /** Whether the group is expanded. In-memory only, default collapsed — mirrors
   *  the tags' expand behaviour (state resets on reload). */
  readonly savedSearchesExpanded = signal(false);

  /** The frozen display order (saved-search ids). Recomputed only when the
   *  section opens or the set of searches changes — never on a count change —
   *  so reading an entry does not reshuffle the list under the reader (#876). */
  private readonly frozenSavedSearchOrder = signal<number[]>([]);

  /** Re-rank the frozen order from the current unread counts. The two triggers
   *  that reset the freeze — a structural change and a section open — share this
   *  one write (#876). */
  private refreezeSavedSearchOrder(): void {
    this.frozenSavedSearchOrder.set(rankedSavedSearchIds(this.savedSearches()));
  }

  /** Re-rank on a structural change: the initial load, a create, or a delete.
   *  Keyed on the id set only, so a count-only change leaves the order frozen. */
  private readonly refreezeOnStructuralChange = effect(() => {
    const ids = this.savedSearches().map((search) => search.id);
    if (!sameIds(ids, untracked(this.frozenSavedSearchOrder))) {
      this.refreezeSavedSearchOrder();
    }
  });

  /** The saved searches in frozen order, each with live params and count. */
  protected readonly orderedSavedSearches = computed(() => {
    const byId = new Map(this.savedSearches().map((row) => [row.id, row]));
    return this.frozenSavedSearchOrder()
      .map((id) => byId.get(id))
      .filter((row): row is NonNullable<typeof row> => row !== undefined);
  });

  /** Total unread matches across all saved searches, for the collapsed badge. */
  readonly savedSearchesUnread = computed(() =>
    this.savedSearches().reduce((sum, saved) => sum + saved.unreadCount, 0),
  );

  /** Whether the "Show more" expansion is open. In memory only, reset when the
   *  section re-opens (#876) — the section chevron and this are separate states. */
  readonly savedSearchListExpanded = signal(false);

  /** The rows to render: the whole list when expanded; otherwise the top six,
   *  plus the active search pinned as an extra row when it is not among them,
   *  so the current selection is always visible (#876). */
  protected readonly visibleSavedSearches = computed(() => {
    const all = this.orderedSavedSearches();
    if (this.savedSearchListExpanded()) return all;
    const top = all.slice(0, SIDEBAR_SAVED_SEARCH_LIMIT);
    const activeId = this.activeSavedSearchId();
    if (activeId === null || top.some((row) => row.id === activeId)) return top;
    const active = all.find((row) => row.id === activeId);
    return active ? [...top, active] : top;
  });

  /** How many ranked searches are not currently on screen. */
  protected readonly hiddenSavedSearchCount = computed(
    () => this.orderedSavedSearches().length - this.visibleSavedSearches().length,
  );

  toggleSavedSearchList(): void {
    this.savedSearchListExpanded.update((open) => !open);
  }

  toggleSavedSearches(): void {
    const opening = !this.savedSearchesExpanded();
    this.savedSearchesExpanded.set(opening);
    if (opening) {
      // Opening the section is a fresh view: re-rank with the current counts.
      this.refreezeSavedSearchOrder();
      this.savedSearchListExpanded.set(false);
    }
  }
}
