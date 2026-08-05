// src/app/reader/list-scroll-reset.ts
import { Injectable, inject } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { NavigationStart, PRIMARY_OUTLET, Router, convertToParamMap } from '@angular/router';
import { ListScrollMemory } from './list-scroll-memory';
import { Selection, sameSelection, selectionFromParams } from './query';

/** How the router says a navigation began. Taken from the event rather than
 *  spelled out, so a trigger Angular adds later cannot drift out of sync. */
type NavigationTrigger = NavigationStart['navigationTrigger'];

/**
 * Whether the incoming list must start at the top rather than where it was left.
 *
 * Asking for a list is not the same as returning to one (#286). A click on a tag
 * or on "All items" asks for that list, and the user expects its newest entries;
 * back, forward and a resume-reload return to a list, and there the remembered
 * place is the whole point.
 */
export function forgetsPosition(
  previous: Selection | null,
  incoming: Selection,
  trigger: NavigationTrigger,
): boolean {
  // Nothing came before: the app is booting, or the browser reloaded a
  // backgrounded tab. Both must land where the user left off. An unattributed
  // navigation is read the same way, so it never costs the user their place.
  if (previous === null) return false;
  if (trigger !== 'imperative') return false;
  // A click that does not change the list — mark-all-read, opening or closing an
  // article, a refresh — must leave the user exactly where they are.
  return !sameSelection(previous, incoming);
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

  /** The list the user was last on, or null before the first one is seen. */
  private previous: Selection | null = null;

  constructor() {
    this.router.events.pipe(takeUntilDestroyed()).subscribe((event) => {
      if (event instanceof NavigationStart) this.onNavigationStart(event);
    });
  }

  private onNavigationStart(event: NavigationStart): void {
    const incoming = this.readerSelectionFrom(event.url);
    // Settings, admin, discover: not a list, so neither the memory nor the list
    // left behind may be disturbed by passing through them.
    if (incoming === null) return;
    if (forgetsPosition(this.previous, incoming, event.navigationTrigger)) {
      this.memory.forget(incoming);
    }
    this.previous = incoming;
  }

  /** The list a URL names, or null when the URL is not the reader. The reader is
   *  the app's root route, so any path segment at all names something else. */
  private readerSelectionFrom(url: string): Selection | null {
    const tree = this.router.parseUrl(url);
    const primary = tree.root.children[PRIMARY_OUTLET];
    if (primary && primary.segments.length > 0) return null;
    return selectionFromParams(convertToParamMap(tree.queryParams)).selection;
  }
}
