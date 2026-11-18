import { DestroyRef, Injectable, Signal, computed, inject, signal } from '@angular/core';
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

/** The list the reader sees: the one over the route's, else the route's. Call from an injection context at the
 *  reader shell, where `ListContext` is the route's. */
export function injectListOnScreen(): Signal<ListContext> {
  const route = inject(ListContext);
  const cover = inject(ListCover);
  return computed(() => cover.list()?.context ?? route);
}

/** The heading of the list the reader sees. Call from an injection context at the reader shell. */
export function injectHeadingOnScreen(): Signal<ListHeading> {
  const route = inject(ListHeading);
  const cover = inject(ListCover);
  return computed(() => cover.list()?.heading ?? route);
}
