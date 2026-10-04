import * as beacon from './client-error-beacon';
import {
  BOOT_WATCHDOG_WINDOW_MS,
  SETTLE_AFTER_LOAD_MS,
  SETTLE_AFTER_RESUME_MS,
  startBlankScreenProbe,
} from './blank-screen-probe';

type Rect = Pick<DOMRect, 'top' | 'left' | 'bottom' | 'right' | 'width' | 'height'>;

const VIEWPORT = { width: 400, height: 800 };
const ON_SCREEN: Rect = { top: 10, left: 10, bottom: 30, right: 200, width: 190, height: 20 };
const BELOW_THE_FOLD: Rect = {
  top: 5000,
  left: 10,
  bottom: 5020,
  right: 200,
  width: 190,
  height: 20,
};

describe('startBlankScreenProbe', () => {
  let sendClientError: jest.SpyInstance;
  let stopProbe: () => void;
  let textRect: Rect;

  const mountRouteContent = (text = 'An entry title'): HTMLElement => {
    const root = document.createElement('app-root');
    root.appendChild(document.createElement('router-outlet'));
    const page = document.createElement('app-reader');
    page.className = 'shell';
    page.textContent = text;
    root.appendChild(page);
    document.body.appendChild(root);
    return root;
  };

  const mountBootErrorSurface = (hidden: boolean): void => {
    const surface = document.createElement('div');
    surface.id = 'boot-error';
    surface.hidden = hidden;
    document.body.appendChild(surface);
  };

  const sentPayloads = () =>
    sendClientError.mock.calls.map(([item]) => ({
      item: item as beacon.ClientErrorItem,
      snapshot: JSON.parse((item as beacon.ClientErrorItem).stack ?? '{}'),
    }));

  const setVisibility = (state: DocumentVisibilityState): void => {
    Object.defineProperty(document, 'visibilityState', { value: state, configurable: true });
  };

  beforeEach(() => {
    jest.useFakeTimers();
    textRect = ON_SCREEN;
    Object.defineProperty(window, 'innerWidth', { value: VIEWPORT.width, configurable: true });
    Object.defineProperty(window, 'innerHeight', { value: VIEWPORT.height, configurable: true });
    (Range.prototype as unknown as { getBoundingClientRect: () => Rect }).getBoundingClientRect =
      () => textRect;
    document.elementFromPoint = () => document.body;
    sendClientError = jest.spyOn(beacon, 'sendClientError').mockImplementation(() => undefined);
    setVisibility('visible');
  });

  afterEach(() => {
    stopProbe?.();
    document.body.innerHTML = '';
    jest.useRealTimers();
    jest.restoreAllMocks();
  });

  it('stays silent when text is visible in the viewport', () => {
    mountRouteContent();
    mountBootErrorSurface(true);
    stopProbe = startBlankScreenProbe();

    jest.advanceTimersByTime(SETTLE_AFTER_LOAD_MS);

    expect(sendClientError).not.toHaveBeenCalled();
  });

  it('reports a blank screen when the only text lies outside the viewport', () => {
    mountRouteContent();
    mountBootErrorSurface(true);
    textRect = BELOW_THE_FOLD;
    stopProbe = startBlankScreenProbe();

    jest.advanceTimersByTime(SETTLE_AFTER_LOAD_MS);

    const [report] = sentPayloads();
    expect(report.item).toMatchObject({ kind: 'BlankScreen', message: 'Blank screen after load.' });
    expect(report.snapshot).toMatchObject({
      trigger: 'load',
      routeContent: true,
      viewport: { width: VIEWPORT.width, height: VIEWPORT.height },
    });
    expect(report.snapshot.outline).toContain('app-reader.shell');
  });

  it('reports a page with no content at all once the boot window has passed', () => {
    mountBootErrorSurface(true);
    stopProbe = startBlankScreenProbe();

    jest.advanceTimersByTime(BOOT_WATCHDOG_WINDOW_MS);
    setVisibility('visible');
    document.dispatchEvent(new Event('visibilitychange'));
    jest.advanceTimersByTime(SETTLE_AFTER_RESUME_MS);

    expect(sentPayloads().map(({ snapshot }) => snapshot.trigger)).toEqual(['visible']);
  });

  it('leaves a page without route content to the boot watchdog inside its window', () => {
    mountBootErrorSurface(true);
    stopProbe = startBlankScreenProbe();

    jest.advanceTimersByTime(SETTLE_AFTER_LOAD_MS);

    expect(sendClientError).not.toHaveBeenCalled();
  });

  it('leaves a page that is hidden when the check runs to its next visibilitychange', () => {
    mountRouteContent();
    mountBootErrorSurface(true);
    textRect = BELOW_THE_FOLD;
    setVisibility('hidden');
    stopProbe = startBlankScreenProbe();

    jest.advanceTimersByTime(SETTLE_AFTER_LOAD_MS);

    expect(sendClientError).not.toHaveBeenCalled();
  });

  it('stays silent while the boot-error surface is showing', () => {
    mountRouteContent();
    mountBootErrorSurface(false);
    textRect = BELOW_THE_FOLD;
    stopProbe = startBlankScreenProbe();

    jest.advanceTimersByTime(SETTLE_AFTER_LOAD_MS);

    expect(sendClientError).not.toHaveBeenCalled();
  });

  it('checks again after a back-forward cache restore', () => {
    mountRouteContent();
    mountBootErrorSurface(true);
    stopProbe = startBlankScreenProbe();
    jest.advanceTimersByTime(SETTLE_AFTER_LOAD_MS);

    textRect = BELOW_THE_FOLD;
    window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
    jest.advanceTimersByTime(SETTLE_AFTER_RESUME_MS);

    expect(sentPayloads().map(({ snapshot }) => snapshot)).toEqual([
      expect.objectContaining({ trigger: 'pageshow', restoredFromCache: true }),
    ]);
  });

  it('ignores a pageshow that is an ordinary load', () => {
    mountRouteContent();
    mountBootErrorSurface(true);
    stopProbe = startBlankScreenProbe();
    jest.advanceTimersByTime(SETTLE_AFTER_LOAD_MS);

    textRect = BELOW_THE_FOLD;
    window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: false }));
    jest.advanceTimersByTime(SETTLE_AFTER_RESUME_MS);

    expect(sendClientError).not.toHaveBeenCalled();
  });

  it('does not check when the page turns hidden', () => {
    mountRouteContent();
    mountBootErrorSurface(true);
    stopProbe = startBlankScreenProbe();
    jest.advanceTimersByTime(SETTLE_AFTER_LOAD_MS);

    textRect = BELOW_THE_FOLD;
    setVisibility('hidden');
    document.dispatchEvent(new Event('visibilitychange'));
    jest.advanceTimersByTime(SETTLE_AFTER_RESUME_MS);

    expect(sendClientError).not.toHaveBeenCalled();
  });

  it('reports at most once per page', () => {
    mountRouteContent();
    mountBootErrorSurface(true);
    textRect = BELOW_THE_FOLD;
    stopProbe = startBlankScreenProbe();
    jest.advanceTimersByTime(SETTLE_AFTER_LOAD_MS);

    document.dispatchEvent(new Event('visibilitychange'));
    jest.advanceTimersByTime(SETTLE_AFTER_RESUME_MS);

    expect(sendClientError).toHaveBeenCalledTimes(1);
  });

  it('counts text the browser reports as invisible as blank', () => {
    mountRouteContent();
    mountBootErrorSurface(true);
    (Element.prototype as unknown as { checkVisibility: () => boolean }).checkVisibility = () =>
      false;
    stopProbe = startBlankScreenProbe();

    jest.advanceTimersByTime(SETTLE_AFTER_LOAD_MS);
    delete (Element.prototype as unknown as { checkVisibility?: unknown }).checkVisibility;

    expect(sendClientError).toHaveBeenCalledTimes(1);
  });

  it('records the scroll offset of a scrolled element', () => {
    const root = mountRouteContent();
    mountBootErrorSurface(true);
    textRect = BELOW_THE_FOLD;
    const scroller = root.querySelector('app-reader') as HTMLElement;
    Object.defineProperty(scroller, 'scrollTop', { value: 4200 });
    stopProbe = startBlankScreenProbe();

    jest.advanceTimersByTime(SETTLE_AFTER_LOAD_MS);

    expect(sentPayloads()[0].snapshot.scrolled).toEqual([
      expect.objectContaining({ element: 'app-reader.shell', scrollTop: 4200 }),
    ]);
  });
});
