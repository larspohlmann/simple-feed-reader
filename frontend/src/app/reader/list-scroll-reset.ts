import { Injectable, inject } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { NavigationStart, PRIMARY_OUTLET, Router, convertToParamMap } from '@angular/router';
import { ListScrollMemory } from './list-scroll-memory';
import { Selection, listSelectionFrom, sameSelection, selectionFromParams } from './query';

/** How the router says a navigation began. Taken from the event rather than
 *  spelled out, so a trigger Angular adds later cannot drift out of sync. */
type NavigationTrigger = NavigationStart['navigationTrigger'];

/**
 * What a reader URL says: the list itself, and what's shown over it (same list,
 * or a search). A search names no tag/feed/view (`selectionFromParams`); the URL
 * keeps those params beside the term anyway, because clearing a search returns to
 * the list it was started from (#542) — reading them is the only way to tell that
 * return from a click on another list (#579).
 */
export interface ReaderPlace {
  /** The list underneath, with any search set aside. */
  list: Selection;
  /** The list actually on screen: the search, or `list` when there is none. */
  shown: Selection;
}

/**
 * Whether the incoming list must start at the top vs. where it was left.
 * A click asking for a list (a tag, "All items") expects its newest entries;
 * back/forward/resume-reload return to a list and the remembered place is the
 * point (#286). A search is both — asked for by typing, returned from by
 * clearing — so it's told apart by the list underneath, not the click (#579).
 */
export function forgetsPosition(
  previous: ReaderPlace | null,
  incoming: ReaderPlace,
  trigger: NavigationTrigger,
): boolean {
  // Nothing came before: the app is booting, or the browser reloaded a
  // backgrounded tab. Both must land where the user left off. An unattributed
  // navigation is read the same way, so it never costs the user their place.
  if (previous === null) return false;
  if (trigger !== 'imperative') return false;
  // A search is a list asked for by typing: every term shows its own results, so
  // a new or changed one starts at their top. Re-asserting the same term does
  // not — that is an article opening over the results, not a fresh search.
  if (incoming.shown.kind === 'search') return !sameSelection(previous.shown, incoming.shown);
  // Everything else is judged on the list underneath, so dropping a search reads
  // as returning to that list, not a fresh click. A click that doesn't change it
  // (mark-all-read, opening/closing an article, a refresh) leaves the user in place.
  return !sameSelection(previous.list, incoming.list);
}

/**
 * Puts a clicked list back at the top by dropping its remembered offset before
 * the list loads. Erasing the memory is the whole mechanism: the entry list
 * already restores it on a selection change, and restoring `0` *is* the scroll
 * to top — keeping the #267 guarantee (the outgoing list stays rendered during
 * the load, #254) intact.
 *
 * `NavigationStart` is the hook: it alone carries `navigationTrigger`, and fires
 * before route parameters update, so the erase always lands first. Root-provided
 * and deliberately not shell-scoped — a shell destroyed by a trip to settings
 * would forget which list the user left, so the next click would restore instead
 * of reset; `providedIn: 'root'` costs nothing up front since only the reader's
 * lazy chunk imports it.
 */
@Injectable({ providedIn: 'root' })
export class ListScrollReset {
  private readonly router = inject(Router);
  private readonly memory = inject(ListScrollMemory);

  /** Where the user was last, or null before the first list is seen. */
  private previous: ReaderPlace | null = null;

  constructor() {
    this.router.events.pipe(takeUntilDestroyed()).subscribe((event) => {
      if (event instanceof NavigationStart) this.onNavigationStart(event);
    });
  }

  private onNavigationStart(event: NavigationStart): void {
    const incoming = this.readerPlaceFrom(event.url);
    // Settings, admin, discover: not a list, so neither the memory nor the list
    // left behind may be disturbed by passing through them.
    if (incoming === null) return;
    if (forgetsPosition(this.previous, incoming, event.navigationTrigger)) {
      this.memory.forget(incoming.shown);
    }
    this.previous = incoming;
  }

  /** Where a URL puts the user, or null when the URL is not the reader. The
   *  reader is the app's root route, so any path segment at all names something
   *  else. */
  private readerPlaceFrom(url: string): ReaderPlace | null {
    const tree = this.router.parseUrl(url);
    const primary = tree.root.children[PRIMARY_OUTLET];
    if (primary && primary.segments.length > 0) return null;
    return {
      list: listSelectionFrom(tree.queryParams),
      shown: selectionFromParams(convertToParamMap(tree.queryParams)).selection,
    };
  }
}
