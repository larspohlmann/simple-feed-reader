/** Where a pane scrolls: its own element, or the page (#2038). Mirrors the Element
 *  scroll API so geometry helpers such as `atBottom` take either. */
export interface ScrollHost {
  scrollTop: number;
  readonly clientHeight: number;
  readonly scrollHeight: number;
  scrollTo(options: ScrollToOptions): void;
  viewportTop(): number;
  /** A viewport y as an offset in the scrolled content. */
  toContentY(viewportY: number): number;
  /** The band at the top that chrome covers, from `scroll-padding-top`. */
  scrollPaddingTop(): number;
  /** The scrolled content: rows are queried and touches heard here. */
  readonly content: HTMLElement;
  /** The IntersectionObserver root: the scroller, or null for the viewport. */
  readonly intersectionRoot: HTMLElement | null;
  /** A passive listener, outside the Angular zone (#501); returns its removal. */
  listen(listener: () => void): () => void;
  /** Calls back when the visible box changes size, outside the Angular zone; returns its removal. */
  watchResize(listener: () => void): () => void;
}

export class ElementScrollHost implements ScrollHost {
  constructor(
    readonly content: HTMLElement,
    private readonly outsideZone: <T>(run: () => T) => T,
  ) {}

  get intersectionRoot(): HTMLElement {
    return this.content;
  }

  get scrollTop(): number {
    return this.content.scrollTop;
  }

  set scrollTop(top: number) {
    this.content.scrollTop = top;
  }

  get clientHeight(): number {
    return this.content.clientHeight;
  }

  get scrollHeight(): number {
    return this.content.scrollHeight;
  }

  scrollTo(options: ScrollToOptions): void {
    this.content.scrollTo(options);
  }

  viewportTop(): number {
    return this.content.getBoundingClientRect().top;
  }

  toContentY(viewportY: number): number {
    return viewportY - this.viewportTop() + this.scrollTop;
  }

  scrollPaddingTop(): number {
    return parseFloat(getComputedStyle(this.content).scrollPaddingTop) || 0;
  }

  listen(listener: () => void): () => void {
    const content = this.content;
    this.outsideZone(() => content.addEventListener('scroll', listener, { passive: true }));
    return () => content.removeEventListener('scroll', listener);
  }

  watchResize(listener: () => void): () => void {
    if (typeof ResizeObserver === 'undefined') return () => undefined;
    const observer = this.outsideZone(() => new ResizeObserver(() => listener()));
    observer.observe(this.content);
    return () => observer.disconnect();
  }
}
