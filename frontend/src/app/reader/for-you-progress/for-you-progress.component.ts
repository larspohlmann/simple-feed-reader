import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { TranslocoService } from '@jsverse/transloco';
import { ProgressHairlineComponent } from '../../shared/progress-hairline/progress-hairline.component';
import { formatEta } from '../eta-format';
import { RecommendationsService } from '../recommendations.service';

/**
 * The For-You run's in-reader progress surface: the shared determinate hairline
 * plus a live ETA/status label. It reads the run service directly and is the
 * single definition behind both render sites in the reader shell, so the two
 * cannot drift. The shared hairline stays dumb — all interpolation lives in the
 * service.
 */
@Component({
  selector: 'app-for-you-progress',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ProgressHairlineComponent],
  templateUrl: './for-you-progress.component.html',
  styleUrl: './for-you-progress.component.scss',
})
export class ForYouProgressComponent {
  private readonly i18n = inject(TranslocoService);
  protected readonly recs = inject(RecommendationsService);

  /** The label text for the current state, or null when nothing should show. */
  protected readonly label = computed<string | null>(() => {
    switch (this.recs.etaState()) {
      case 'starting':
        return this.i18n.translate('reader.eta.starting');
      case 'waiting':
        return this.i18n.translate('reader.eta.rateLimited');
      case 'eta': {
        const seconds = this.recs.etaSeconds();
        if (seconds === null) return null;
        const { key, params } = formatEta(seconds);
        return this.i18n.translate(key, params);
      }
      case 'hidden':
        return null;
    }
  });
}
