import { Injectable, signal } from '@angular/core';
import { TrendingWindow } from './trending.models';

/** When each Trending window was last rebuilt, as its latest page reported (#2264). */
@Injectable({ providedIn: 'root' })
export class TrendingBuiltAt {
  private readonly byWindow = signal<Partial<Record<TrendingWindow, string | null>>>({});

  at(window: TrendingWindow): string | null {
    return this.byWindow()[window] ?? null;
  }

  record(window: TrendingWindow, builtAt: string | null): void {
    this.byWindow.update((times) => ({ ...times, [window]: builtAt }));
  }
}
