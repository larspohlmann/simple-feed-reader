// src/app/shared/error-banner/error-banner.component.ts
import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';

/**
 * The app's one error banner: a message and an optional single action button
 * (retry a failed load, dismiss a failed row action). Extracted when the
 * markup and styles had drifted into three byte-identical copies across the
 * admin screens (#180) -- see docs/design-language.md.
 *
 * `actionLabel` takes an already-translated string rather than an i18n key,
 * so this shared component never hardcodes a feature's translation keys
 * ('admin.retry' / 'admin.dismiss' today) -- the caller resolves those with
 * its own `transloco` pipe and passes the result in.
 */
@Component({
  selector: 'app-error-banner',
  templateUrl: './error-banner.component.html',
  styleUrl: './error-banner.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ErrorBannerComponent {
  readonly message = input.required<string>();

  /** `null` renders a plain message banner with no button. */
  readonly actionLabel = input<string | null>(null);

  readonly action = output<void>();
}
