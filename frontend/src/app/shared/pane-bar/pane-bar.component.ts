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

/** The bar heading a pane — a list's header, an article's toolbar. The host places and moves it. */
@Component({
  selector: 'app-pane-bar',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: '<ng-content />',
  styleUrl: './pane-bar.component.scss',
})
export class PaneBarComponent {
  /** The bar's height, for the pane to reserve beneath it. */
  readonly resized = output<number>();

  constructor() {
    const bar: HTMLElement = inject(ElementRef).nativeElement;
    const stopObserving = observeResize(inject(NgZone), [bar], () => {
      if (bar.offsetHeight > 0) this.resized.emit(bar.offsetHeight);
    });
    inject(DestroyRef).onDestroy(stopObserving);
  }
}
