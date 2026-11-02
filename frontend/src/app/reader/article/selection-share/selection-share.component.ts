import { DOCUMENT } from '@angular/common';
import {
  ChangeDetectionStrategy,
  Component,
  NgZone,
  computed,
  effect,
  inject,
  input,
  signal,
} from '@angular/core';
import {
  CdkConnectedOverlay,
  ConnectedPosition,
  FlexibleConnectedPositionStrategyOrigin,
} from '@angular/cdk/overlay';
import { TranslocoPipe } from '@jsverse/transloco';
import { fromEvent } from 'rxjs';
import { KeepSelectionDirective } from '../../../shared/keep-selection/keep-selection.directive';
import { LayoutService } from '../../layout.service';
import { ArticleSelection } from './article-selection.service';
import { SelectedQuote } from './selected-quote';
import { SelectionActionsComponent } from './selection-actions.component';

const UNDER_THE_LAST_LINE: ConnectedPosition[] = [
  { originX: 'end', originY: 'bottom', overlayX: 'center', overlayY: 'top', offsetY: 8 },
  { originX: 'end', originY: 'top', overlayX: 'center', overlayY: 'bottom', offsetY: -8 },
];

/**
 * The selection's actions: on a fine pointer a bubble under its last line; on a coarse pointer, where the phone's own
 * callout owns that spot, a bar docked at the foot of the article.
 */
@Component({
  selector: 'app-selection-share',
  imports: [TranslocoPipe, CdkConnectedOverlay, KeepSelectionDirective, SelectionActionsComponent],
  templateUrl: './selection-share.component.html',
  styleUrl: './selection-share.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: {
    '(pointerdown)': 'pressing.set(true)',
    '(pointerup)': 'release()',
    '(pointercancel)': 'release()',
  },
})
export class SelectionShareComponent {
  readonly url = input.required<string | null>();
  readonly title = input('');

  private readonly selection = inject(ArticleSelection);
  private readonly document = inject(DOCUMENT);
  private readonly zone = inject(NgZone);
  protected readonly coarse = inject(LayoutService).isCoarse;
  private readonly scrolls = signal(0);
  protected readonly positions = UNDER_THE_LAST_LINE;
  /** A press may collapse the selection before its click; the bar stays for that click. */
  protected readonly pressing = signal(false);
  protected readonly barQuote = computed(
    (): SelectedQuote | null =>
      this.selection.quote() ?? (this.pressing() ? this.selection.remembered() : null),
  );
  protected readonly bubbleOrigin = computed((): FlexibleConnectedPositionStrategyOrigin | null => {
    this.scrolls();
    const range = this.selection.range();
    if (this.selection.quote() === null || range === null) return null;
    const lines = range.getClientRects();
    const last = lines.item(lines.length - 1);
    if (last === null || !onScreen(last)) return null;
    return { x: last.left, y: last.top, width: last.width, height: last.height };
  });

  constructor() {
    effect((onCleanup) => {
      if (this.coarse() || this.selection.quote() === null) return;
      const scrolling = this.zone.runOutsideAngular(() =>
        fromEvent(this.document, 'scroll', { capture: true, passive: true }).subscribe(() =>
          this.scrolls.update((count) => count + 1),
        ),
      );
      onCleanup(() => scrolling.unsubscribe());
    });
  }

  protected release(): void {
    setTimeout(() => this.pressing.set(false));
  }
}

function onScreen(line: DOMRect): boolean {
  return line.bottom > 0 && line.top < window.innerHeight;
}
