// src/app/reader/list-scroll-reset.ts
import { Injectable, inject } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { NavigationStart, PRIMARY_OUTLET, Router, convertToParamMap } from '@angular/router';
import { ListScrollMemory } from './list-scroll-memory';
import { Selection, listSelectionFrom, sameSelection, selectionFromParams } from './query';

/** How the router says a navigation began. Taken from the event rather than
 *  spelled out, so a trigger Angular adds later cannot drift out of sync. */
type NavigationTrigger = NavigationStart['navigationTrigger'];

/**
 * The two things a reader URL says about what the user is looking at: the list
 * itself, and what is shown over it — the same list, or a search.
 *
 * A search is its own view over every subscription, so its `Selection` names no
 * tag, feed or view (`selectionFromParams`). The URL keeps those parameters
 * beside the term all the same, because clearing the search returns to the list
 * it was started from (#542) — and reading them is the only way to tell that
 * return from a click on some other list (#579).
 */
export interface ReaderPlace {
  /** The list underneath, with any search set aside. */
  list: Selection;
  /** The list actually on screen: the search, or `list` when there is none. */
  shown: Selection;
}

/**
 * Whether the incoming list must start at the top rather than where it was left.
 *
 * Asking for a list is not the same as returning to one (#286). A click on a tag
 * or on "All items" asks for that list, and the user expects its newest entries;
 * back, forward and a resume-reload return to a list, and there the remembered
 * place is the whole point.
 *
 * A search is both — asked for by typing, returned from by clearing — and it is
 * the same click either way, so the two are told apart from the list underneath
 * rather than from the click (#579).
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
  // Everything else is judged on the list underneath, so that dropping a search
  // reads as the return to that list which it is, rather than as a click on a
  // list the user never chose. A click that does not change it — mark-all-read,
  // opening or closing an article, a refresh — leaves the user where they are.
  return !sameSelection(previous.list, incoming.list);
}

/**
 * Puts a clicked list back at the top by dropping its remembered offset before
 * the list loads.
 *
 * Erasing the memory is the whole mechanism: the entry list already restores the
 * remembered offset on a selection change, and restoring `0` *is* a scroll to the
 * top. That keeps the #267 guarantee intact — the outgoing list stays rendered
 * during the load (#254), so its scroller must be moved deliberately rather than
 * left alone — and needs no second source of truth beside the saved offset.
 *
 * `NavigationStart` is the hook because it alone carries `navigationTrigger`, and
 * because it fires before the route parameters update, so the erase always lands
 * before anything reads the offset.
 *
 * Root-provided, and deliberately not tied to the reader shell's lifetime: the
 * shell is destroyed by a trip to settings, and a listener that died with it
 * would come back not knowing which list the user had left — so the first click
 * after returning would restore instead of resetting. `providedIn: 'root'` does
 * not pull this into the initial bundle, because only the reader's lazy chunk
 * imports it. The bootstrap navigation is never seen either way, which is exactly
 * the "nothing came before" case that makes a resume-reload restore.
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
