import { Injectable, inject } from '@angular/core';
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

@Injectable({ providedIn: 'root' })
export class LayoutService {
  private readonly bp = inject(BreakpointObserver);
  readonly isWide = toSignal(this.bp.observe(WIDE_QUERY).pipe(map((s) => s.matches)), {
    initialValue: typeof window !== 'undefined' ? window.matchMedia(WIDE_QUERY).matches : true,
  });
  readonly isNarrow = toSignal(this.bp.observe(NARROW_QUERY).pipe(map((s) => s.matches)), {
    initialValue: typeof window !== 'undefined' ? window.matchMedia(NARROW_QUERY).matches : false,
  });
  readonly isCoarse = toSignal(this.bp.observe(COARSE_QUERY).pipe(map((s) => s.matches)), {
    initialValue: typeof window !== 'undefined' ? window.matchMedia(COARSE_QUERY).matches : false,
  });
}
