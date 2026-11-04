import { Component, input } from '@angular/core';
import { Params } from '@angular/router';
import { SegmentedChoiceComponent } from '../../../shared/segmented-choice/segmented-choice.component';
import { TrendingWindow } from '../trending.models';

/** The Trending list's window choice, both options visible, as links: the URL owns the window (#2017, D27, D40). */
@Component({
  selector: 'app-trending-window-switch',
  imports: [SegmentedChoiceComponent],
  templateUrl: './trending-window-switch.component.html',
  styleUrl: './trending-window-switch.component.scss',
})
export class TrendingWindowSwitchComponent {
  readonly window = input.required<TrendingWindow>();
  protected readonly windows: readonly TrendingWindow[] = ['day', 'week'];
  protected readonly links: Readonly<Record<TrendingWindow, Params>> = {
    day: { window: 'day' },
    week: { window: 'week' },
  };
}
