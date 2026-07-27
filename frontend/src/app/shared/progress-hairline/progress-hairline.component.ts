// src/app/shared/progress-hairline/progress-hairline.component.ts
import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';

/**
 * A 2px determinate bar under the header. Zero layout shift, so it can sit in
 * the reader permanently and upgrade EVERY refresh — not just the onboarding
 * sweep — from "an icon is spinning" to "this much of it is done".
 */
@Component({
  selector: 'app-progress-hairline',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (active()) {
      <div
        class="bar"
        role="progressbar"
        aria-valuemin="0"
        aria-valuemax="100"
        [attr.aria-valuenow]="percent()"
      >
        <span [style.width.%]="percent()"></span>
      </div>
    }
  `,
  styles: `
    .bar {
      height: var(--space-0);
      background: var(--border);
    }
    span {
      display: block;

      /* stylelint-disable-next-line declaration-property-unit-allowed-list --
         fills the parent bar's height; a proportion, not a spacing value. */
      height: 100%;
      background: var(--accent);
      transition: width 0.3s ease-out;
    }
  `,
})
export class ProgressHairlineComponent {
  readonly active = input.required<boolean>();
  /** 0..1, straight from RefreshService.progress(). */
  readonly value = input.required<number>();

  readonly percent = computed(() => Math.round(Math.min(1, Math.max(0, this.value())) * 100));
}
