import { NgZone, effect, inject } from '@angular/core';

export interface PageChromeGeometryOptions {
  readonly host: HTMLElement;
  readonly listColumn: () => HTMLElement | undefined;
  readonly main: () => HTMLElement | undefined;
  readonly audioBar: () => HTMLElement | undefined;
}

/** Publishes on the shell what its fixed chrome positions against, since `fixed` measures
 *  from the viewport (#2038): the list column's box and the audio bar's height. Built in a
 *  field initializer, for its effects. */
export class PageChromeGeometry {
  private readonly zone = inject(NgZone);

  // `main` too: hiding the sidebar moves a min-width split column without resizing it.
  private readonly _followListColumn = effect((onCleanup) => {
    const column = this.options.listColumn();
    if (!column) return;
    onCleanup(this.observe([column, this.options.main()], () => this.publishColumn(column)));
  });

  private readonly _followAudioBar = effect((onCleanup) => {
    const bar = this.options.audioBar();
    if (!bar) return;
    onCleanup(
      this.observe([bar], () => this.publish('--audio-bar-h', bar.getBoundingClientRect().height)),
    );
  });

  constructor(private readonly options: PageChromeGeometryOptions) {}

  private observe(targets: (HTMLElement | undefined)[], measure: () => void): () => void {
    if (typeof ResizeObserver === 'undefined') return () => undefined;
    const observer = this.zone.runOutsideAngular(() => new ResizeObserver(() => measure()));
    for (const target of targets) if (target) observer.observe(target);
    return () => observer.disconnect();
  }

  private publishColumn(column: HTMLElement): void {
    const box = column.getBoundingClientRect();
    this.publish('--list-column-left', box.left);
    this.publish('--list-column-width', box.width);
  }

  private publish(property: string, pixels: number): void {
    this.options.host.style.setProperty(property, `${pixels}px`);
  }
}
