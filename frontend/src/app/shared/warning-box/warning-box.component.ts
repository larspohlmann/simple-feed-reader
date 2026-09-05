import { ChangeDetectionStrategy, Component } from '@angular/core';

/**
 * The app's one warning callout: a neutral card ringed in amber
 * (`--border-warning`, #854) that lifts a step off the content on `--surface-1`.
 * The sibling of `error-banner` (the filled danger banner) — a warning reads as
 * a ring, not a fill. Content is projected, so each surface keeps its own body:
 * the paywall notice a glyph and prose (#785, #855), the unhealthy-feed row a
 * mono error string (#847). See docs/design-language.md.
 */
@Component({
  selector: 'app-warning-box',
  templateUrl: './warning-box.component.html',
  styleUrl: './warning-box.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class WarningBoxComponent {}
