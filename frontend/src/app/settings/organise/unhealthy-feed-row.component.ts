import { ChangeDetectionStrategy, Component, computed, input, output } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { feedHealthReason, isGone as feedIsGone } from '../../reader/feed-health';
import { SubscriptionDto } from '../../reader/models';
import { ButtonComponent } from '../../shared/button/button.component';
import { DisclosureComponent } from '../../shared/disclosure/disclosure.component';
import { IconComponent } from '../../shared/icon/icon.component';
import { FeedHealthFactsComponent } from './feed-health-facts.component';

/** One row in the unhealthy-feeds list: a status glyph whose colour is the
 *  dead/failing signal, the title, a friendly reason line, Retry and
 *  Unsubscribe, and a disclosure that expands in place to the full facts and
 *  the raw error. Presentational — it emits and never writes. */
@Component({
  selector: 'app-unhealthy-feed-row',
  imports: [
    TranslocoPipe,
    IconComponent,
    ButtonComponent,
    DisclosureComponent,
    FeedHealthFactsComponent,
  ],
  templateUrl: './unhealthy-feed-row.component.html',
  styleUrl: './unhealthy-feed-row.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class UnhealthyFeedRowComponent {
  readonly subscription = input.required<SubscriptionDto>();
  readonly retry = output<void>();
  readonly unsubscribe = output<void>();

  protected readonly reason = computed(() => feedHealthReason(this.subscription(), new Date()));
  protected readonly isGone = computed(() => feedIsGone(this.subscription()));

  /** A dead feed is a broken link; a failing one is a warning. The glyph's
   *  colour (set in the stylesheet) carries the status, so no text pill does. */
  protected readonly statusIcon = computed(() => (this.isGone() ? 'link_off' : 'warning'));
}
