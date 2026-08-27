import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { formatEta } from '../eta-format';
import { RecommendationsService } from '../recommendations.service';

/** A Transloco key plus its interpolation params — resolved through the pipe so
 *  the label re-renders when the language switches at runtime. */
interface Phrase {
  readonly key: string;
  readonly params?: { count: number };
}

/**
 * The For-You run's progress surface: the "Ranking your feeds — X of Y" count
 * with the live ETA on the same line, and a determinate bar beneath it. It is
 * the content of the app-wide toast pill (`RecommendationsService` raises it),
 * so the run stays visible on every route rather than only in the reader. It
 * reads the run service directly and renders nothing unless a run is in flight.
 */
@Component({
  selector: 'app-for-you-progress',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslocoPipe],
  templateUrl: './for-you-progress.component.html',
  styleUrl: './for-you-progress.component.scss',
})
export class ForYouProgressComponent {
  protected readonly recs = inject(RecommendationsService);

  /** done / total for the "Ranking your feeds — X of Y" line. */
  protected readonly count = computed(() => {
    const report = this.recs.report();
    return { done: report?.batchesDone ?? 0, total: report?.batchesTotal ?? 0 };
  });

  /** The ETA/status phrase appended after the count, or null when there is
   *  nothing to add. `starting`, `waiting` and `lockHeld` are fixed phrases;
   *  `eta` formats the remaining seconds; `hidden` (no run) adds nothing. */
  protected readonly eta = computed<Phrase | null>(() => {
    switch (this.recs.etaState()) {
      case 'starting':
        return { key: 'reader.eta.starting' };
      case 'waiting':
        return { key: 'reader.eta.rateLimited' };
      case 'lockHeld':
        return { key: 'reader.eta.lockHeld' };
      case 'eta': {
        const seconds = this.recs.etaSeconds();
        if (seconds === null) return null;
        return formatEta(seconds);
      }
      case 'hidden':
        return null;
    }
  });

  /** 0..100 time-based fill for both the visual width and `aria-valuenow`.
   * The bar is not a live region, so its timer ticks do not repeat the run
   * status message to assistive technology. */
  protected readonly percent = computed(() => Math.round(this.recs.progress() * 100));
}
