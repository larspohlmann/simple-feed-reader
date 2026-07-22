// src/app/reader/layout.service.ts
import { Injectable, inject } from '@angular/core';
import { BreakpointObserver } from '@angular/cdk/layout';
import { toSignal } from '@angular/core/rxjs-interop';
import { map } from 'rxjs';

/** True when the viewport is wide enough to place the reader in a side pane. */
export const WIDE_QUERY = '(min-width: 900px)';

@Injectable({ providedIn: 'root' })
export class LayoutService {
  private readonly bp = inject(BreakpointObserver);
  readonly isWide = toSignal(this.bp.observe(WIDE_QUERY).pipe(map((s) => s.matches)), {
    initialValue: typeof window !== 'undefined' ? window.matchMedia(WIDE_QUERY).matches : true,
  });
}
