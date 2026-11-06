import type { ScrollHost } from './scroll-host';
import type { PageLayer } from './page-layer';
import { pageScrollingElement } from '../../core/page-scrolling-element';

/** The class a CDK BlockScrollStrategy puts on `<html>` while it pins the page. */
export const PAGE_SCROLL_BLOCK_CLASS = 'cdk-global-scrollblock';

const pageWriteListeners = new Set<(top: number) => void>();

/** Hears every offset the app writes to the page, for #2074's handoff probe; returns its removal. */
export function observePageWrites(listener: (top: number) => void): () => void {
  pageWriteListeners.add(listener);
  return () => pageWriteListeners.delete(listener);
}

function announcePageWrite(top: number): void {
  pageWriteListeners.forEach((listener) => listener(top));
}

export interface PageScrollEnvironment {
  readonly view: Window;
  readonly outsideZone: <T>(run: () => T) => T;
}

/** While a CDK block pins the page, or its layer is pinned beneath another (#2038), it reports
 *  the pinned offset, stays silent, and defers writes. */
export class PageScrollHost implements ScrollHost {
  readonly intersectionRoot = null;
  private pendingTop: number | null = null;

  private readonly view: Window;
  private readonly outsideZone: <T>(run: () => T) => T;

  constructor(
    readonly content: HTMLElement,
    environment: PageScrollEnvironment,
    private readonly layer: PageLayer | null = null,
  ) {
    this.view = environment.view;
    this.outsideZone = environment.outsideZone;
  }

  get scrollTop(): number {
    if (this.layer?.pinned) return this.layer.pinnedTop;
    if (this.blocked()) return -parseFloat(this.root.style.top) || 0;
    return this.scrollingElement.scrollTop;
  }

  set scrollTop(top: number) {
    if (this.layer?.pinned) {
      this.layer.moveTo(top);
      return;
    }
    if (!this.blocked()) {
      announcePageWrite(top);
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
    if (!this.held()) {
      if (options.top !== undefined) announcePageWrite(options.top);
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
    return parseFloat(this.view.getComputedStyle(this.content).scrollPaddingTop) || 0;
  }

  listen(listener: () => void): () => void {
    const view = this.view;
    const onScroll = (): void => {
      if (!this.held()) listener();
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
    this.scrollTop = top;
  }

  private held(): boolean {
    return (this.layer?.pinned ?? false) || this.blocked();
  }

  private blocked(): boolean {
    return this.root.classList.contains(PAGE_SCROLL_BLOCK_CLASS);
  }

  private get root(): HTMLElement {
    return this.view.document.documentElement;
  }

  private get scrollingElement(): Element {
    return pageScrollingElement(this.view.document);
  }
}
