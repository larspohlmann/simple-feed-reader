import type { ScrollHost } from './scroll-host';
import type { PageLayer } from './page-layer';
import { pageScrollingElement } from '../../core/page-scrolling-element';

export interface PageScrollEnvironment {
  readonly view: Window;
  readonly outsideZone: <T>(run: () => T) => T;
  readonly announceWrite: (top: number) => void;
}

/** While its layer is pinned beneath another (#2038), it reports the pinned offset, stays silent, and moves the pin on writes. */
export class PageScrollHost implements ScrollHost {
  readonly intersectionRoot = null;

  private readonly view: Window;
  private readonly outsideZone: <T>(run: () => T) => T;
  private readonly announceWrite: (top: number) => void;

  constructor(
    readonly content: HTMLElement,
    environment: PageScrollEnvironment,
    private readonly layer: PageLayer | null = null,
  ) {
    this.view = environment.view;
    this.outsideZone = environment.outsideZone;
    this.announceWrite = environment.announceWrite;
  }

  /** In the layer's own terms: 0 is where its content starts, below its lead (#2088). */
  get scrollTop(): number {
    return Math.max(0, this.pageTop - this.lead);
  }

  set scrollTop(top: number) {
    this.pageTop = top + this.lead;
  }

  get clientHeight(): number {
    return this.view.innerHeight;
  }

  get scrollHeight(): number {
    return this.scrollingElement.scrollHeight - this.lead;
  }

  scrollTo(options: ScrollToOptions): void {
    if (options.top === undefined) {
      if (!this.held()) this.view.scrollTo(options);
      return;
    }
    const top = options.top + this.lead;
    if (this.held()) {
      this.pageTop = top;
      return;
    }
    this.announceWrite(top);
    this.view.scrollTo({ ...options, top });
  }

  viewportTop(): number {
    return 0;
  }

  toContentY(viewportY: number): number {
    return viewportY + this.scrollTop;
  }

  scrollPaddingTop(): number {
    return parseFloat(this.view.getComputedStyle(this.content).scrollPaddingTop) || 0;
  }

  listen(listener: () => void): () => void {
    const view = this.view;
    const onScroll = (): void => {
      if (!this.held()) listener();
    };
    this.outsideZone(() => view.addEventListener('scroll', onScroll, { passive: true }));
    return () => view.removeEventListener('scroll', onScroll);
  }

  watchResize(listener: () => void): () => void {
    const view = this.view;
    const visualViewport = view.visualViewport;
    const onResize = (): void => listener();
    this.outsideZone(() => {
      view.addEventListener('resize', onResize, { passive: true });
      visualViewport?.addEventListener('resize', onResize, { passive: true });
    });
    return () => {
      view.removeEventListener('resize', onResize);
      visualViewport?.removeEventListener('resize', onResize);
    };
  }

  private get pageTop(): number {
    if (this.layer?.pinned) return this.layer.pinnedTop;
    return this.scrollingElement.scrollTop;
  }

  private set pageTop(top: number) {
    if (this.layer?.pinned) {
      this.layer.moveTo(top);
      return;
    }
    this.announceWrite(top);
    this.scrollingElement.scrollTop = top;
  }

  private get lead(): number {
    return this.layer?.lead ?? 0;
  }

  private held(): boolean {
    return this.layer?.pinned ?? false;
  }

  private get scrollingElement(): Element {
    return pageScrollingElement(this.view.document);
  }
}
