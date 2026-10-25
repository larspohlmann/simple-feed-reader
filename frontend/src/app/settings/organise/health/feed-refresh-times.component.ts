import { ChangeDetectionStrategy, Component, inject, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { LanguageService } from '../../../core/i18n/language.service';
import { formatLongDateTime, relativeTime, relativeTimeUntil } from '../../../reader/format';
import { SubscriptionDto } from '../../../reader/models';

/** The refresh-timing meta of an Organise feed row: when the feed was last
 *  checked, when it last delivered content, and when the scheduler will next
 *  fetch it. Relative labels for a glance, each carrying the full timestamp as
 *  a hover tooltip. Its own component, like the health facts grid, so the row
 *  template stays lean and this line is tested on its own. */
@Component({
  selector: 'app-feed-refresh-times',
  imports: [TranslocoPipe],
  templateUrl: './feed-refresh-times.component.html',
  styleUrl: './feed-refresh-times.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class FeedRefreshTimesComponent {
  readonly subscription = input.required<SubscriptionDto>();

  private readonly language = inject(LanguageService);

  protected relative(iso: string): string {
    return relativeTime(iso, this.language.lang());
  }

  protected until(iso: string): string {
    return relativeTimeUntil(iso, this.language.lang());
  }

  protected full(iso: string): string {
    return formatLongDateTime(iso, this.language.lang());
  }
}
