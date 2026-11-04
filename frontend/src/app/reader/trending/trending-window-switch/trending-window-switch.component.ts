import { Component, inject } from '@angular/core';
import { SegmentedChoiceComponent } from '../../../shared/segmented-choice/segmented-choice.component';
import { TrendingWindow } from '../trending.models';
import { TrendingWindowService } from '../trending-window.service';

/** The Trending list's window choice, both options visible (#2017, decision 27). */
@Component({
  selector: 'app-trending-window-switch',
  imports: [SegmentedChoiceComponent],
  templateUrl: './trending-window-switch.component.html',
  styleUrl: './trending-window-switch.component.scss',
})
export class TrendingWindowSwitchComponent {
  protected readonly trendingWindow = inject(TrendingWindowService);
  protected readonly windows: readonly TrendingWindow[] = ['day', 'week'];
}
