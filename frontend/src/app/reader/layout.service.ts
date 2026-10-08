import { Injectable, Signal, inject } from '@angular/core';
import { BreakpointObserver } from '@angular/cdk/layout';
import { toSignal } from '@angular/core/rxjs-interop';
import { map } from 'rxjs';

/** True when the viewport is wide enough to place the reader in a side pane. */
export const WIDE_QUERY = '(min-width: 900px)';
/** True below this width, where the sidebar is a swipe-in drawer, not a column.
 *  THE single source of the drawer boundary: the shell binds `.is-narrow` from
 *  this signal and the stylesheet keys every drawer rule to that class — no
 *  media query may re-declare this width (#185). */
export const NARROW_QUERY = '(max-width: 720px)';
/** True on devices whose primary pointer is coarse (touch), not fine (mouse/trackpad). */
export const COARSE_QUERY = '(pointer: coarse)';

/** True below `$bp-sm`, where the big player is a full-screen sheet rather than a card. */
export const PHONE_QUERY = '(max-width: 559.98px)';

@Injectable({ providedIn: 'root' })
export class LayoutService {
  private readonly bp = inject(BreakpointObserver);
  readonly isWide = this.matches(WIDE_QUERY, true);
  readonly isNarrow = this.matches(NARROW_QUERY);
  readonly isCoarse = this.matches(COARSE_QUERY);
  readonly isPhone = this.matches(PHONE_QUERY);

  /** `fallback` stands in for the query's answer where there is no window to ask. */
  private matches(query: string, fallback = false): Signal<boolean> {
    const initialValue =
      typeof window !== 'undefined' ? window.matchMedia(query).matches : fallback;
    return toSignal(this.bp.observe(query).pipe(map((state) => state.matches)), { initialValue });
  }
}
