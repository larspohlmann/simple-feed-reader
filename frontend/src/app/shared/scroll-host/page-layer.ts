/** The class a pinned layer carries; the reader shell's stylesheet fixes it in place. */
export const PAGE_PINNED_CLASS = 'page-pinned';
/** How far the page had scrolled into a pinned layer; its pinned box is shifted up by it. */
export const PAGE_PIN_TOP = '--page-pin-top';
/** How far a leading layer's content starts below its top; it pads that much above its content. */
export const PAGE_LEAD = '--page-lead';

/** One of the reader's page-sized layers, the list or a full-screen article (#2038): in the
 *  page's flow while it owns the page, pinned at its own offset while a layer above does. */
export class PageLayer {
  private top: number | null = null;
  private documentTop = 0;
  private leadPx = 0;

  constructor(readonly element: HTMLElement) {}

  get pinned(): boolean {
    return this.top !== null;
  }

  /** The page offset the layer is held at, and takes again when unpinned. */
  get pinnedTop(): number {
    return this.top ?? 0;
  }

  /** The page offset at which the layer's content starts. */
  get lead(): number {
    return this.leadPx;
  }

  leadBy(lead: number): void {
    this.leadPx = lead;
    this.element.style.setProperty(PAGE_LEAD, `${lead}px`);
  }

  /** Out of the flow, showing exactly what it showed with the page at `top`. */
  pinAt(top: number): void {
    if (!this.pinned) {
      this.documentTop = this.element.getBoundingClientRect().top + top;
      this.element.classList.add(PAGE_PINNED_CLASS);
    }
    this.hold(top);
  }

  moveTo(top: number): void {
    if (this.top !== null) this.hold(top);
  }

  /** Back into the flow; returns the offset the page must take in the same task. */
  unpin(): number {
    const top = this.pinnedTop;
    this.top = null;
    this.element.classList.remove(PAGE_PINNED_CLASS);
    this.element.style.removeProperty(PAGE_PIN_TOP);
    return top;
  }

  private hold(top: number): void {
    this.top = top;
    this.element.style.setProperty(PAGE_PIN_TOP, `${top - this.documentTop}px`);
  }
}
