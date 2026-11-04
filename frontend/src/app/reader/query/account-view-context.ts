import { Injectable, computed, inject } from '@angular/core';
import { PreferencesService } from '../../core/preferences/preferences.service';
import { TrendingWindowService } from '../trending/trending-window.service';
import { ViewContext } from './query';

/** This account's side of reading a reader URL, shared by everything that parses one. */
@Injectable({ providedIn: 'root' })
export class AccountViewContext {
  private readonly preferences = inject(PreferencesService);
  private readonly trendingWindow = inject(TrendingWindowService);

  readonly context = computed<ViewContext>(() => ({
    trending: this.preferences.trendingEnabled(),
    trendingWindow: this.trendingWindow.window(),
  }));
}
