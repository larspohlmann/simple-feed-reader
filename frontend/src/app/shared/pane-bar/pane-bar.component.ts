import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  ElementRef,
  NgZone,
  inject,
  output,
} from '@angular/core';
import { observeResize } from '../observe-resize';

/** The bar heading a pane — a list's header, an article's toolbar. */
@Component({
  selector: 'app-pane-bar',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: '<ng-content />',
  styleUrl: './pane-bar.component.scss',
})
export class PaneBarComponent {
  /** The bar's height, for the pane to reserve beneath it. */
  readonly resized = output<number>();

  private reportedHeight = 0;

  constructor() {
    const bar: HTMLElement = inject(ElementRef).nativeElement;
    const stopObserving = observeResize(inject(NgZone), [bar], () => {
      const height = bar.offsetHeight;
      if (height === 0 || height === this.reportedHeight) return;
      this.reportedHeight = height;
      this.resized.emit(height);
    });
    inject(DestroyRef).onDestroy(stopObserving);
  }
}
