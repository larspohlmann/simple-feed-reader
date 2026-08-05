// src/app/reader/list-scroll-reset.ts
import { DestroyRef, inject } from '@angular/core';
import { NavigationStart, PRIMARY_OUTLET, Router, convertToParamMap } from '@angular/router';
import { ListScrollMemory } from './list-scroll-memory';
import { Selection, sameSelection, selectionFromParams } from './query';

/** How the router says a navigation began. `undefined` is read as a gesture, not
 *  as a click, so an unattributed navigation never costs the user their place. */
export type NavigationTrigger = 'imperative' | 'popstate' | 'hashchange' | undefined;

/**
 * Whether the incoming list must start at the top rather than where it was left.
 *
 * Asking for a list is not the same as returning to one (#286). A click on a tag
 * or on "All feeds" asks for that list, and the user expects its newest entries;
 * back, forward and a resume-reload return to a list, and there the remembered
 * place is the whole point.
 */
export function forgetsPosition(
  previous: Selection | null,
  incoming: Selection,
  trigger: NavigationTrigger,
): boolean {
  // Nothing came before: the app is booting, or the browser reloaded a
  // backgrounded tab. Both must land where the user left off.
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
 * Started by the reader shell, not by an app initializer: everything here belongs
 * to the reader's lazy chunk, and wiring it at bootstrap would pull the chunk into
 * the initial bundle. The bootstrap navigation is therefore never seen, which is
 * exactly the "nothing came before" case — a resume-reload restores.
 */
export function startListScrollReset(): void {
  const router = inject(Router);
  const memory = inject(ListScrollMemory);
  let previous: Selection | null = null;

  const subscription = router.events.subscribe((event) => {
    if (!(event instanceof NavigationStart)) return;
    const incoming = readerSelectionFrom(router, event.url);
    // Settings, admin, discover: not a list, so neither the memory nor the list
    // left behind may be disturbed by passing through them.
    if (incoming === null) return;
    if (forgetsPosition(previous, incoming, event.navigationTrigger)) memory.forget(incoming);
    previous = incoming;
  });

  inject(DestroyRef).onDestroy(() => subscription.unsubscribe());
}

/** The list a URL names, or null when the URL is not the reader. The reader is
 *  the app's root route, so any path segment at all names something else. */
function readerSelectionFrom(router: Router, url: string): Selection | null {
  const tree = router.parseUrl(url);
  const primary = tree.root.children[PRIMARY_OUTLET];
  if (primary && primary.segments.length > 0) return null;
  return selectionFromParams(convertToParamMap(tree.queryParams)).selection;
}
