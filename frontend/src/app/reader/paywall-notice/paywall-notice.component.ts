import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { WarningBoxComponent } from '../../shared/warning-box/warning-box.component';

/**
 * The warning box above a paywalled reader body: the reader text is only the
 * free preview, with a link to the publisher (#785, #855). Wraps the shared
 * `app-warning-box` and holds the reader-side glyph, prose and link — the box
 * carries the amber-ring chrome, this component the feature's translations.
 */
@Component({
  selector: 'app-paywall-notice',
  templateUrl: './paywall-notice.component.html',
  styleUrl: './paywall-notice.component.scss',
  imports: [TranslocoPipe, WarningBoxComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PaywallNoticeComponent {
  /** The publisher URL for the "continue reading" link; `null` drops the link. */
  readonly url = input<string | null>(null);
}
