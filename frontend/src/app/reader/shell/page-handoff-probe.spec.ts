import { TestBed } from '@angular/core/testing';
import { API_BASE_URL } from '../../core/api';
import { ScrollHosts } from '../../shared/scroll-host/scroll-hosts.service';
import { sendClientError } from '../../core/errors/client-error-beacon';
import { restorePageScroll, trackPageScrollTop } from '../../../testing/scroll-testing';
import { PageHandoffProbe, WATCH_MS } from './page-handoff-probe';

jest.mock('../../core/errors/client-error-beacon', () => ({
  ...jest.requireActual('../../core/errors/client-error-beacon'),
  sendClientError: jest.fn(),
}));

describe('PageHandoffProbe', () => {
  const root = document.documentElement;
  const send = sendClientError as jest.MockedFunction<typeof sendClientError>;
  let page: { top: number };
  let probe: PageHandoffProbe;

  beforeEach(() => {
    jest.useFakeTimers();
    send.mockClear();
    page = trackPageScrollTop();
    Object.defineProperty(root, 'scrollHeight', { value: 20000, configurable: true });
    TestBed.configureTestingModule({
      providers: [PageHandoffProbe, { provide: API_BASE_URL, useValue: '' }],
    });
    probe = TestBed.inject(PageHandoffProbe);
  });

  afterEach(() => {
    TestBed.resetTestingModule();
    delete (root as unknown as { scrollHeight?: number }).scrollHeight;
    restorePageScroll();
    jest.useRealTimers();
  });

  it('stays silent when the page holds the offset it was handed', () => {
    page.top = 3300;
    probe.watch('leave', 3300);

    jest.advanceTimersByTime(WATCH_MS + 100);

    expect(send).not.toHaveBeenCalled();
  });

  it('reports an offset that drifts away after the handoff', () => {
    page.top = 3300;
    probe.watch('leave', 3300);
    jest.advanceTimersByTime(100);
    page.top = 9000;

    jest.advanceTimersByTime(WATCH_MS);

    expect(send).toHaveBeenCalledTimes(1);
    const item = send.mock.calls[0][0];
    expect(item.kind).toBe('ScrollHandoff');
    expect(JSON.parse(item.stack ?? '{}')).toMatchObject({
      handoff: 'leave',
      target: 3300,
      final: 9000,
    });
  });

  it('tells an app write apart from the browser moving the page', () => {
    page.top = 3300;
    probe.watch('leave', 3300);
    jest.advanceTimersByTime(100);
    TestBed.inject(ScrollHosts).forPage(document.body).scrollTop = 5000;
    jest.advanceTimersByTime(100);
    page.top = 9000;

    jest.advanceTimersByTime(WATCH_MS);

    const after: string[] = JSON.parse(send.mock.calls[0][0].stack ?? '{}').after;
    expect(after.filter((event) => event.includes(':write:'))).toEqual([
      expect.stringMatching(/:write:5000$/),
    ]);
  });

  it('leaves a drift alone once the user touches the page', () => {
    page.top = 0;
    probe.watch('enter', 0);
    window.dispatchEvent(new Event('touchstart'));
    page.top = 1200;

    jest.advanceTimersByTime(WATCH_MS + 100);

    expect(send).not.toHaveBeenCalled();
  });

  it('takes a target past the end as landing on the end', () => {
    page.top = 20000 - window.innerHeight;
    probe.watch('leave', 25000);

    jest.advanceTimersByTime(WATCH_MS + 100);

    expect(send).not.toHaveBeenCalled();
  });
});
