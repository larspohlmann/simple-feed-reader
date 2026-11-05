import { NgZone, effect, inject } from '@angular/core';
import { observeResize } from '../../shared/observe-resize';

export interface PageChromeGeometryOptions {
  readonly host: HTMLElement;
  readonly listColumn: () => HTMLElement | undefined;
  readonly main: () => HTMLElement | undefined;
  readonly audioBar: () => HTMLElement | undefined;
}

/** Construct in a field initializer: it injects and owns effects. */
export class PageChromeGeometry {
  private readonly zone = inject(NgZone);
  private readonly published = new Map<string, number>();

  // `main` too: hiding the sidebar moves a min-width split column without resizing it.
  private readonly _followListColumn = effect((onCleanup) => {
    const column = this.options.listColumn();
    if (!column) return;
    const main = this.options.main();
    const targets = main ? [column, main] : [column];
    onCleanup(observeResize(this.zone, targets, () => this.publishColumn(column)));
  });

  private readonly _followAudioBar = effect((onCleanup) => {
    const bar = this.options.audioBar();
    if (!bar) return;
    onCleanup(
      observeResize(this.zone, [bar], () =>
        this.publish('--audio-bar-h', bar.getBoundingClientRect().height),
      ),
    );
  });

  constructor(private readonly options: PageChromeGeometryOptions) {}

  private publishColumn(column: HTMLElement): void {
    const box = column.getBoundingClientRect();
    this.publish('--list-column-left', box.left);
    this.publish('--list-column-width', box.width);
  }

  private publish(property: string, pixels: number): void {
    if (this.published.get(property) === pixels) return;
    this.published.set(property, pixels);
    this.options.host.style.setProperty(property, `${pixels}px`);
  }
}
