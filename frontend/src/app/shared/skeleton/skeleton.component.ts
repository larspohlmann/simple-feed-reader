// src/app/shared/skeleton/skeleton.component.ts
import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';

/**
 * Placeholder rows for a list that is still loading. Sized from the same
 * comfortable row-density tokens the real rows use, so nothing shifts when the
 * data arrives -- which a spinner cannot do, because it does not know how tall
 * the list will be.
 *
 * `label` takes an already-translated string rather than an i18n key, so this
 * shared component never hardcodes a feature's translation keys.
 */
@Component({
  selector: 'app-skeleton',
  templateUrl: './skeleton.component.html',
  styleUrl: './skeleton.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SkeletonComponent {
  /** What is loading, announced to assistive technology. */
  readonly label = input.required<string>();

  /** How many placeholder rows to draw. */
  readonly rows = input<number>(3);

  protected readonly placeholders = computed(() => Array.from({ length: this.rows() }));
}
