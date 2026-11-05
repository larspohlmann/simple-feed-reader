import type { ScrollHost } from './scroll-host';

/** The class a CDK BlockScrollStrategy puts on `<html>` while it pins the page. */
export const PAGE_SCROLL_BLOCK_CLASS = 'cdk-global-scrollblock';

/** The document as a scroller (#2038). While a CDK block pins the page it reports the
 *  pinned offset and stays silent, and a write waits for the release, so no consumer
 *  reads the block as a scroll. */
export class PageScrollHost implements ScrollHost {
  readonly intersectionRoot = null;
  private pendingTop: number | null = null;

  constructor(
    readonly content: HTMLElement,
    private readonly view: Window,
    private readonly outsideZone: <T>(run: () => T) => T,
  ) {}

  get scrollTop(): number {
    if (this.blocked()) return -parseFloat(this.root.style.top) || 0;
    return this.scrollingElement.scrollTop;
  }

  set scrollTop(top: number) {
    if (!this.blocked()) {
      this.scrollingElement.scrollTop = top;
      return;
    }
    this.pendingTop = top;
    this.root.style.top = `${-top}px`;
  }

  get clientHeight(): number {
    return this.view.innerHeight;
  }

  get scrollHeight(): number {
    return this.scrollingElement.scrollHeight;
  }

  scrollTo(options: ScrollToOptions): void {
    if (!this.blocked()) {
      this.view.scrollTo(options);
      return;
    }
    if (options.top !== undefined) this.scrollTop = options.top;
  }

  viewportTop(): number {
    return 0;
  }

  toContentY(viewportY: number): number {
    return viewportY + this.scrollTop;
  }

  scrollPaddingTop(): number {
    return parseFloat(this.view.getComputedStyle(this.root).scrollPaddingTop) || 0;
  }

  listen(listener: () => void): () => void {
    const view = this.view;
    const onScroll = (): void => {
      if (!this.blocked()) listener();
    };
    const release = this.outsideZone(() => {
      view.addEventListener('scroll', onScroll, { passive: true });
      const observer = new MutationObserver(() => this.landPendingTop());
      observer.observe(this.root, { attributes: true, attributeFilter: ['class'] });
      return observer;
    });
    return () => {
      view.removeEventListener('scroll', onScroll);
      release.disconnect();
    };
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

  private landPendingTop(): void {
    if (this.pendingTop === null || this.blocked()) return;
    const top = this.pendingTop;
    this.pendingTop = null;
    this.scrollingElement.scrollTop = top;
  }

  private blocked(): boolean {
    return this.root.classList.contains(PAGE_SCROLL_BLOCK_CLASS);
  }

  private get root(): HTMLElement {
    return this.view.document.documentElement;
  }

  private get scrollingElement(): Element {
    return this.view.document.scrollingElement ?? this.root;
  }
}
