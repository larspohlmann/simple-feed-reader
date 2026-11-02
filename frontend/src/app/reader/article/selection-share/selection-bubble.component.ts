import { DOCUMENT } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, inject, input, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import {
  CdkConnectedOverlay,
  ConnectedPosition,
  FlexibleConnectedPositionStrategyOrigin,
} from '@angular/cdk/overlay';
import { fromEvent } from 'rxjs';
import { LayoutService } from '../../layout.service';
import { ArticleSelection } from './article-selection.service';
import { SelectionActionsComponent } from './selection-actions.component';

const UNDER_THE_LAST_LINE: ConnectedPosition[] = [
  { originX: 'end', originY: 'bottom', overlayX: 'center', overlayY: 'top', offsetY: 8 },
  { originX: 'end', originY: 'top', overlayX: 'center', overlayY: 'bottom', offsetY: -8 },
];

/** The selection's actions under its last line, on a fine pointer only; a phone's own callout owns that spot. */
@Component({
  selector: 'app-selection-bubble',
  imports: [CdkConnectedOverlay, SelectionActionsComponent],
  templateUrl: './selection-bubble.component.html',
  styleUrl: './selection-bubble.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SelectionBubbleComponent {
  readonly url = input.required<string | null>();
  readonly title = input('');

  private readonly selection = inject(ArticleSelection);
  private readonly screen = inject(LayoutService);
  private readonly scrolls = signal(0);
  protected readonly positions = UNDER_THE_LAST_LINE;
  protected readonly nowhere: FlexibleConnectedPositionStrategyOrigin = { x: 0, y: 0 };
  /** The last line of a quotable selection while it is on screen; null closes the bubble. */
  protected readonly origin = computed((): FlexibleConnectedPositionStrategyOrigin | null => {
    this.scrolls();
    if (this.screen.isCoarse() || this.url() === null) return null;
    const range = this.selection.range();
    if (this.selection.quote() === null || range === null) return null;
    const lines = range.getClientRects();
    const last = lines.item(lines.length - 1);
    if (last === null || !onScreen(last)) return null;
    return { x: last.left, y: last.top, width: last.width, height: last.height };
  });

  constructor() {
    fromEvent(inject(DOCUMENT), 'scroll', { capture: true, passive: true })
      .pipe(takeUntilDestroyed())
      .subscribe(() => this.scrolls.update((count) => count + 1));
  }
}

function onScreen(line: DOMRect): boolean {
  return line.bottom > 0 && line.top < window.innerHeight;
}
