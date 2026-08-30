// src/app/shared/progress-hairline/progress-hairline.component.ts
import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';

/**
 * A 2px determinate bar. Zero layout shift, so it sits in the app bar permanently
 * and upgrades EVERY refresh — not just the onboarding sweep — from "an icon is
 * spinning" to "this much of it is done".
 *
 * The width is only ever what the server has reported. A slice is budgeted at 25 s,
 * so it steps rather than creeps; the stylesheet's sheen carries the activity in
 * between (#721).
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
  styleUrl: './progress-hairline.component.scss',
})
export class ProgressHairlineComponent {
  readonly active = input.required<boolean>();
  /** 0..1, straight from RefreshService.progress(). */
  readonly value = input.required<number>();

  readonly percent = computed(() => Math.round(Math.min(1, Math.max(0, this.value())) * 100));
}
