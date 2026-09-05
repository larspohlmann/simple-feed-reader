import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';

/**
 * The amber-ringed warning box above a paywalled reader body: the text is only
 * the free preview, with a link to the publisher (#785, #855). Its own component
 * so the box styling stays out of reader-view.component.scss, at its style budget.
 */
@Component({
  selector: 'app-paywall-notice',
  templateUrl: './paywall-notice.component.html',
  styleUrl: './paywall-notice.component.scss',
  imports: [TranslocoPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PaywallNoticeComponent {
  /** The publisher URL for the "continue reading" link; `null` drops the link. */
  readonly url = input<string | null>(null);
}
