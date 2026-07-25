// src/app/shared/to-top-button/to-top-button.component.ts
import { Component, output } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../icon/icon.component';

/** How far a scroller must travel before the back-to-top button appears. */
export const BACK_TO_TOP_AFTER_PX = 500;

/**
 * The back-to-top circle, shared by the article view and the entry list. Purely
 * presentational: it reports a click and nothing else, because "the top" means a
 * different scroller in each place.
 *
 * Placement is the consumer's job too — the article pins its copy to the viewport
 * (`position: fixed`), the list to its own pane — so this stylesheet defines the
 * button's appearance and leaves the host element's offsets alone.
 */
@Component({
  selector: 'app-to-top-button',
  imports: [IconComponent, TranslocoPipe],
  templateUrl: './to-top-button.component.html',
  styleUrl: './to-top-button.component.scss',
})
export class ToTopButtonComponent {
  readonly activate = output<void>();
}
