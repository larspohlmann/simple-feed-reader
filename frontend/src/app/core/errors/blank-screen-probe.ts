import { describeError, sendClientError, toClientErrorItem } from './client-error-beacon';

/**
 * Reports a page that shows nothing (#1374): the grey page an iOS browser leaves
 * after a relaunch, which the #282 boot watchdog never sees because route content
 * exists or the page is past its window. A diagnostic, so it reports the state that
 * tells the causes apart rather than repairing anything.
 *
 * Angular-free and started from main.ts before bootstrap, like boot-error-surface.
 */
export const SETTLE_AFTER_LOAD_MS = 5000;
export const SETTLE_AFTER_RESUME_MS = 3000;
/** Mirrors BOOT_DEADLINE_MS in index.html; inside it an empty app-root is the boot watchdog's. */
export const BOOT_WATCHDOG_WINDOW_MS = 15000;

const MEDIA_SELECTOR = 'img, svg, video, canvas';
const OUTLINE_DEPTH = 3;
const MAX_SCROLLED_ELEMENTS = 5;
const MAX_SCRIPT_RESOURCES = 10;

type Trigger = 'load' | 'pageshow' | 'visible';

export function startBlankScreenProbe(): () => void {
  let reported = false;
  let restoredFromCache = false;
  const timers = new Set<ReturnType<typeof setTimeout>>();

  const checkAfter = (trigger: Trigger, delayMs: number): void => {
    const timer = setTimeout(() => {
      timers.delete(timer);
      if (reported || !looksBlank()) {
        return;
      }
      reported = true;
      reportBlankScreen(trigger, restoredFromCache);
    }, delayMs);
    timers.add(timer);
  };
  const onLoad = (): void => checkAfter('load', SETTLE_AFTER_LOAD_MS);
  const onPageShow = (event: PageTransitionEvent): void => {
    if (event.persisted) {
      restoredFromCache = true;
      checkAfter('pageshow', SETTLE_AFTER_RESUME_MS);
    }
  };
  const onVisibilityChange = (): void => {
    if (document.visibilityState === 'visible') {
      checkAfter('visible', SETTLE_AFTER_RESUME_MS);
    }
  };

  if (document.readyState === 'complete') {
    onLoad();
  } else {
    window.addEventListener('load', onLoad, { once: true });
  }
  window.addEventListener('pageshow', onPageShow);
  document.addEventListener('visibilitychange', onVisibilityChange);

  return () => {
    timers.forEach((timer) => clearTimeout(timer));
    window.removeEventListener('load', onLoad);
    window.removeEventListener('pageshow', onPageShow);
    document.removeEventListener('visibilitychange', onVisibilityChange);
  };
}

function looksBlank(): boolean {
  if (document.visibilityState !== 'visible' || bootErrorSurfaceIsShowing()) {
    return false;
  }
  if (!hasRouteContent() && performance.now() < BOOT_WATCHDOG_WINDOW_MS) {
    return false;
  }
  return !hasVisibleText() && !hasVisibleMedia();
}

function bootErrorSurfaceIsShowing(): boolean {
  const surface = document.getElementById('boot-error');
  return surface !== null && !surface.hidden;
}

function hasRouteContent(): boolean {
  return document.querySelector('app-root :not(router-outlet)') !== null;
}

function hasVisibleText(): boolean {
  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  for (let node = walker.nextNode(); node !== null; node = walker.nextNode()) {
    if (textIsVisible(node)) {
      return true;
    }
  }
  return false;
}

function textIsVisible(node: Node): boolean {
  if (!node.textContent?.trim() || !node.parentElement || !rendersVisibly(node.parentElement)) {
    return false;
  }
  const range = document.createRange();
  range.selectNodeContents(node);
  return intersectsViewport(range.getBoundingClientRect());
}

function hasVisibleMedia(): boolean {
  return Array.from(document.body.querySelectorAll(MEDIA_SELECTOR)).some(
    (element) => rendersVisibly(element) && intersectsViewport(element.getBoundingClientRect()),
  );
}

/** Older WebKit lacks checkVisibility; there only geometry decides. */
function rendersVisibly(element: Element): boolean {
  if (typeof element.checkVisibility !== 'function') {
    return true;
  }
  return element.checkVisibility({ opacityProperty: true, visibilityProperty: true });
}

function intersectsViewport(rect: Pick<DOMRect, 'top' | 'left' | 'bottom' | 'right'>): boolean {
  const hasArea = rect.right > rect.left && rect.bottom > rect.top;
  return (
    hasArea &&
    rect.bottom > 0 &&
    rect.right > 0 &&
    rect.top < window.innerHeight &&
    rect.left < window.innerWidth
  );
}

function reportBlankScreen(trigger: Trigger, restoredFromCache: boolean): void {
  const description = describeError(new Error(`Blank screen after ${trigger}.`));
  const snapshot = JSON.stringify(snapshotOf(trigger, restoredFromCache));
  sendClientError(
    toClientErrorItem({ ...description, kind: 'BlankScreen', stack: snapshot }, { route: null }),
  );
}

function snapshotOf(trigger: Trigger, restoredFromCache: boolean): Record<string, unknown> {
  const root = document.querySelector('app-root');
  return {
    trigger,
    restoredFromCache,
    pageAgeMs: Math.round(performance.now()),
    navigation: navigationTiming(),
    visibility: document.visibilityState,
    viewport: {
      width: window.innerWidth,
      height: window.innerHeight,
      scrollX: window.scrollX,
      scrollY: window.scrollY,
      documentHeight: document.documentElement.scrollHeight,
    },
    routeContent: hasRouteContent(),
    renderedElements: root?.querySelectorAll('*').length ?? null,
    outline: root ? outlineOf(root, OUTLINE_DEPTH) : null,
    scrolled: scrolledElements(),
    atViewportCentre: elementAtViewportCentre(),
    scripts: scriptResources(),
  };
}

function elementAtViewportCentre(): string | null {
  const element = document.elementFromPoint(window.innerWidth / 2, window.innerHeight / 2);
  return element ? describeElement(element) : null;
}

function navigationTiming(): Record<string, unknown> | null {
  const [entry] = performance.getEntriesByType?.('navigation') ?? [];
  if (!entry) {
    return null;
  }
  const timing = entry as PerformanceNavigationTiming;
  return {
    type: timing.type,
    transferSize: timing.transferSize,
    responseStatus: timing.responseStatus,
  };
}

function outlineOf(element: Element, depth: number): string {
  const label = describeElement(element);
  if (depth === 0 || element.children.length === 0) {
    return label;
  }
  const children = Array.from(element.children).map((child) => outlineOf(child, depth - 1));
  return `${label}>(${children.join(',')})`;
}

function describeElement(element: Element): string {
  const firstClass = element.classList[0];
  return firstClass ? `${element.localName}.${firstClass}` : element.localName;
}

function scrolledElements(): Record<string, unknown>[] {
  return Array.from(document.querySelectorAll('body *'))
    .filter((element) => element.scrollTop > 0)
    .slice(0, MAX_SCROLLED_ELEMENTS)
    .map((element) => ({
      element: describeElement(element),
      scrollTop: element.scrollTop,
      scrollHeight: element.scrollHeight,
      clientHeight: element.clientHeight,
    }));
}

function scriptResources(): Record<string, unknown>[] {
  const entries = (performance.getEntriesByType?.('resource') ?? []) as PerformanceResourceTiming[];
  return entries
    .filter((entry) => /\.m?js(\?|$)/.test(entry.name))
    .slice(0, MAX_SCRIPT_RESOURCES)
    .map((entry) => ({
      name: entry.name.split('/').pop(),
      transferSize: entry.transferSize,
      responseStatus: entry.responseStatus,
    }));
}
