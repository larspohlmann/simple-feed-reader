// src/app/shared/overlay-panel/overlay-panel.component.ts
import { ChangeDetectionStrategy, Component, input } from '@angular/core';

let nextId = 0;

/**
 * The frame every interrupt surface renders inside: a centred card on desktop,
 * full screen on a phone. Owns the heading, the scrolling body and the footer
 * row, so a dialog's own stylesheet carries only what is specific to it.
 *
 * Width is the one dimension that legitimately varies per consumer, so it is
 * read from --panel-w rather than being an input — that keeps it in the
 * stylesheet where the rest of the sizing lives.
 */
@Component({
  selector: 'app-overlay-panel',
  templateUrl: './overlay-panel.component.html',
  styleUrl: './overlay-panel.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class OverlayPanelComponent {
  /**
   * Named `heading`, not `title`: an input called `title` on a component host
   * collides with the native attribute and would render a stray browser
   * tooltip over every dialog.
   */
  readonly heading = input.required<string>();

  /**
   * Where the heading sits in the document outline. A dialog opens over a page
   * that already has its own `h1`, so 2 is right for every one of them. A panel
   * that *is* the page — discover, a route rather than an overlay — has to
   * supply the `h1` itself, or the page has none and its section headings rank
   * level with the title.
   */
  readonly headingLevel = input<1 | 2>(2);

  /** Ties the panel to its heading; unique so several panels can coexist. */
  protected readonly headingId = `overlay-panel-heading-${nextId++}`;
}
