import { APP_BASE_HREF, DOCUMENT, PathLocationStrategy, PlatformLocation } from '@angular/common';
import { Injectable, NgZone, inject } from '@angular/core';
import { COARSE_QUERY } from './coarse-pointer';

/** The page grows by at least this much when a mobile browser collapses its address bar. */
const COLLAPSED_BAR_GROWTH_PX = 30;

/** A history write's arguments: state, title, url, query string. */
type HistoryWrite = Parameters<PathLocationStrategy['pushState']>;

interface HeldWrite {
  readonly push: boolean;
  readonly write: HistoryWrite;
}

/** Holds a history write back while a touch browser's address bar is collapsed, and makes it once
 *  the bar is back: Brave expands its bar on every URL change, pushState included (#2088). A
 *  bookmark or a shared link needs the bar, so it still sees the current URL. */
@Injectable()
export class CollapsedBarLocationStrategy extends PathLocationStrategy {
  private readonly view = inject(DOCUMENT).defaultView ?? window;
  private readonly touch = this.view.matchMedia(COARSE_QUERY);
  private shortestHeight = this.view.innerHeight;
  private width = this.view.innerWidth;
  private held: HeldWrite | null = null;

  constructor() {
    const platformLocation = inject(PlatformLocation);
    super(platformLocation, inject(APP_BASE_HREF, { optional: true }) ?? undefined);
    platformLocation.onPopState(() => (this.held = null));
    inject(NgZone).runOutsideAngular(() =>
      this.view.addEventListener('resize', () => this.onResize(), { passive: true }),
    );
  }

  override path(includeHash = false): string {
    if (!this.held) return super.path(includeHash);
    const [, , url, queryParams] = this.held.write;
    return url + (queryParams ? `?${queryParams}` : '');
  }

  override pushState(...write: HistoryWrite): void {
    this.writeOrHold({ push: true, write });
  }

  /** A replace of a held push stays a push: the entry it replaces was never written. */
  override replaceState(...write: HistoryWrite): void {
    this.writeOrHold({ push: this.held?.push ?? false, write });
  }

  override getState(): unknown {
    return this.held ? this.held.write[0] : super.getState();
  }

  private writeOrHold(write: HeldWrite): void {
    if (this.barCollapsed()) {
      this.held = write;
      return;
    }
    this.release();
    this.make(write);
  }

  private onResize(): void {
    const { innerWidth: width, innerHeight: height } = this.view;
    this.shortestHeight = width === this.width ? Math.min(this.shortestHeight, height) : height;
    this.width = width;
    if (!this.barCollapsed()) this.release();
  }

  private barCollapsed(): boolean {
    return (
      this.touch.matches && this.view.innerHeight > this.shortestHeight + COLLAPSED_BAR_GROWTH_PX
    );
  }

  private release(): void {
    const held = this.held;
    if (!held) return;
    this.held = null;
    this.make(held);
  }

  private make({ push, write }: HeldWrite): void {
    if (push) super.pushState(...write);
    else super.replaceState(...write);
  }
}
