import { DestroyRef, Injectable, Signal, computed, inject, signal } from '@angular/core';
import { Selection } from '../query/query';
import { ListContext } from '../state/list-context';
import { ListHeading } from './list-heading.service';

/** A list shown over the route's list, with the heading it carries, while `open` holds. */
export interface CoveringList {
  readonly context: ListContext;
  readonly heading: ListHeading;
  /** False from the moment the layer starts to leave, so a click on the list it reveals reaches that list. */
  readonly open: Signal<boolean>;
}

/** The list a layer shows over the route's list, as a Trending story's does (#2329). Row and article actions, the
 *  previews poll and the shell's list controls follow it while it is up. */
@Injectable()
export class ListCover {
  private readonly covering = signal<CoveringList | null>(null);
  readonly list = computed(() => {
    const list = this.covering();
    return list?.open() ? list : null;
  });

  /** Shows `list` over the route's list until its host is destroyed. */
  coverWith(list: CoveringList, hostDestroyed: DestroyRef): void {
    this.covering.set(list);
    hostDestroyed.onDestroy(() => {
      if (this.covering() === list) this.covering.set(null);
    });
  }
}

/** What `pick` reads off the list the reader sees: the one over the route's, else `route`, the route list's own.
 *  Call from an injection context at the reader shell. */
export function onScreen<T>(route: T, pick: (list: CoveringList) => T): Signal<T> {
  return resolveOnScreen(inject(ListCover), route, pick);
}

/** The list the reader sees. Call from an injection context at the reader shell, where `ListContext` is the
 *  route's. */
export function injectListOnScreen(): Signal<ListContext> {
  return onScreen(inject(ListContext), (list) => list.context);
}

/** What the list the reader sees lists, or null where no reader shell provides a list (a host mounted on its own). */
export function injectSelectionOnScreen(): Signal<Selection | null> {
  const list = resolveOnScreen(
    inject(ListCover, { optional: true }),
    inject(ListContext, { optional: true }),
    (covering) => covering.context,
  );
  return computed(() => list()?.selection() ?? null);
}

function resolveOnScreen<T>(
  cover: ListCover | null,
  route: T,
  pick: (list: CoveringList) => T,
): Signal<T> {
  return computed(() => {
    const list = cover?.list();
    return list ? pick(list) : route;
  });
}
