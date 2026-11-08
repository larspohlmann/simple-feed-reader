import { PlatformLocation } from '@angular/common';
import { TestBed } from '@angular/core/testing';
import { CollapsedBarLocationStrategy } from './collapsed-bar-location-strategy';

describe('CollapsedBarLocationStrategy', () => {
  const pushState = jest.fn();
  const replaceState = jest.fn();
  let popState: (() => void) | undefined;
  let touch: boolean;
  let strategy: CollapsedBarLocationStrategy;

  function resizeTo(height: number, width = 390): void {
    Object.defineProperty(window, 'innerHeight', { value: height, configurable: true });
    Object.defineProperty(window, 'innerWidth', { value: width, configurable: true });
    window.dispatchEvent(new Event('resize'));
  }

  beforeEach(() => {
    pushState.mockClear();
    replaceState.mockClear();
    touch = true;
    Object.defineProperty(window, 'innerHeight', { value: 664, configurable: true });
    Object.defineProperty(window, 'innerWidth', { value: 390, configurable: true });
    jest.spyOn(window, 'matchMedia').mockImplementation(
      () =>
        ({
          get matches() {
            return touch;
          },
        }) as MediaQueryList,
    );
    TestBed.configureTestingModule({
      providers: [
        CollapsedBarLocationStrategy,
        {
          provide: PlatformLocation,
          useValue: {
            pushState,
            replaceState,
            pathname: '/reader',
            search: '',
            getBaseHrefFromDOM: () => '/',
            onPopState: (listener: () => void) => (popState = listener),
            getState: () => null,
          },
        },
      ],
    });
    strategy = TestBed.inject(CollapsedBarLocationStrategy);
  });

  afterEach(() => {
    TestBed.resetTestingModule();
    jest.restoreAllMocks();
  });

  it('writes history at once while the address bar shows', () => {
    strategy.pushState(null, '', '/reader', 'entry=7-title');

    expect(pushState).toHaveBeenCalledWith(null, '', '/reader?entry=7-title');
  });

  it('holds a write back while the bar is collapsed, and makes it once the bar is back', () => {
    resizeTo(748);

    strategy.pushState({ navigationId: 2 }, '', '/reader', 'entry=7-title');
    expect(pushState).not.toHaveBeenCalled();
    expect(strategy.path()).toBe('/reader?entry=7-title');
    expect(strategy.getState()).toEqual({ navigationId: 2 });

    resizeTo(664);
    expect(pushState).toHaveBeenCalledWith({ navigationId: 2 }, '', '/reader?entry=7-title');
    expect(pushState).toHaveBeenCalledTimes(1);
  });

  it('keeps a held push a push when a replace follows it', () => {
    resizeTo(748);
    strategy.pushState(null, '', '/reader', 'entry=7-title');
    strategy.replaceState(null, '', '/reader', 'entry=8-title');

    resizeTo(664);

    expect(pushState).toHaveBeenCalledWith(null, '', '/reader?entry=8-title');
    expect(replaceState).not.toHaveBeenCalled();
  });

  it('makes a held write before one the bar lets through', () => {
    resizeTo(748);
    strategy.pushState(null, '', '/reader', 'entry=7-title');
    resizeTo(700, 391);

    strategy.pushState(null, '', '/reader', '');

    expect(pushState.mock.calls.map((call) => call[2])).toEqual([
      '/reader?entry=7-title',
      '/reader',
    ]);
  });

  it('drops a held write the browser went back past', () => {
    resizeTo(748);
    strategy.pushState(null, '', '/reader', 'entry=7-title');

    popState?.();
    resizeTo(664);

    expect(pushState).not.toHaveBeenCalled();
  });

  it('writes at once on a desktop window that grew taller', () => {
    touch = false;
    resizeTo(748);

    strategy.pushState(null, '', '/reader', 'entry=7-title');

    expect(pushState).toHaveBeenCalled();
  });

  it('measures the bar afresh after a rotation', () => {
    resizeTo(748);
    resizeTo(340, 844);

    strategy.pushState(null, '', '/reader', 'entry=7-title');

    expect(pushState).toHaveBeenCalled();
  });
});
