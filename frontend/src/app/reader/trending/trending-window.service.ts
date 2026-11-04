import { Injectable, computed, inject } from '@angular/core';
import { UserDeviceStorage } from '../../core/preferences/user-device-storage';
import { TrendingWindow } from '../models';
import { DEFAULT_TRENDING_WINDOW, isTrendingWindow } from './trending-window';

const NAME = 'trending-window';

/** Which window the Trending list shows, per account on this device (#2017, decision 9). */
@Injectable({ providedIn: 'root' })
export class TrendingWindowService {
  private readonly storage = inject(UserDeviceStorage);

  readonly window = computed<TrendingWindow>(() => {
    const stored = this.storage.read(NAME);
    return isTrendingWindow(stored) ? stored : DEFAULT_TRENDING_WINDOW;
  });

  set(window: TrendingWindow): void {
    this.storage.write(NAME, window === DEFAULT_TRENDING_WINDOW ? null : window);
  }
}
