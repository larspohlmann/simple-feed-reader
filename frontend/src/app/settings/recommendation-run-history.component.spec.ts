import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { of, throwError } from 'rxjs';
import { RecommendationRunHistoryComponent } from './recommendation-run-history.component';
import { ReaderApi } from '../reader/reader-api';
import { RecommendationsService } from '../reader/recommendations.service';
import { RunHistoryMonth, RunHistoryOverview, RunHistoryRow } from '../reader/models';
import { LanguageService } from '../core/language.service';
import { Lang } from '../core/language';
import { provideTranslocoTesting } from '../../testing/transloco-testing';

const BROWSER_TZ = Intl.DateTimeFormat().resolvedOptions().timeZone;

const PRICED_RUN: RunHistoryRow = {
  id: 42,
  status: 'completed',
  providerHost: 'openrouter.ai',
  model: 'x-ai/grok-4-fast',
  createdAt: '2026-08-16T09:12:00+00:00',
  completedAt: '2026-08-16T09:12:47+00:00',
  durationSeconds: 47,
  promptTokens: 118432,
  completionTokens: 2216,
  reasoningTokens: 0,
  cachedTokens: 0,
  costNanoCredits: 41_230_000,
};

const OTHER_PRICED_RUN: RunHistoryRow = { ...PRICED_RUN, id: 43 };

const UNPRICED_RUN: RunHistoryRow = {
  ...PRICED_RUN,
  id: 41,
  providerHost: 'localhost',
  model: 'bonsai-27b',
  costNanoCredits: null,
};

const AUGUST: RunHistoryMonth = { month: '2026-08', runCount: 2, costNanoCredits: 41_230_000 };
const JULY: RunHistoryMonth = { month: '2026-07', runCount: 1, costNanoCredits: null };

const EMPTY_OVERVIEW: RunHistoryOverview = {
  totalCostNanoCredits: null,
  months: [],
  latest: null,
};

const OVERVIEW: RunHistoryOverview = {
  totalCostNanoCredits: 918_200_000,
  months: [AUGUST, JULY],
  latest: { month: '2026-08', runs: [PRICED_RUN], nextCursor: null },
};

describe('RecommendationRunHistoryComponent', () => {
  let runHistory: jest.Mock;
  let runHistoryMonth: jest.Mock;
  let completedStamp: ReturnType<typeof signal<number>>;
  let lang: ReturnType<typeof signal<Lang>>;
  let fixture: ReturnType<typeof TestBed.createComponent<RecommendationRunHistoryComponent>>;

  function mount(overview: RunHistoryOverview) {
    runHistory.mockReturnValue(of(overview));
    fixture = TestBed.createComponent(RecommendationRunHistoryComponent);
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  function months(el: HTMLElement): NodeListOf<Element> {
    return el.querySelectorAll('.run-history__month');
  }

  function detailsOf(section: Element): HTMLDetailsElement {
    return section.querySelector('details') as HTMLDetailsElement;
  }

  /** jsdom's native <details> toggles `.open` on a summary click but does not
   *  dispatch the `toggle` event (a known jsdom gap), so this drives the
   *  event directly -- the same workaround the shared disclosure's own spec
   *  uses -- rather than `summary.click()`. */
  function openMonth(section: Element): void {
    const details = section.querySelector('details') as HTMLDetailsElement;
    details.open = true;
    details.dispatchEvent(new Event('toggle'));
    fixture.detectChanges();
  }

  beforeEach(() => {
    runHistory = jest.fn().mockReturnValue(of(EMPTY_OVERVIEW));
    runHistoryMonth = jest
      .fn()
      .mockReturnValue(of({ month: '2026-07', runs: [], nextCursor: null }));
    completedStamp = signal(0);
    lang = signal<Lang>('en');

    TestBed.configureTestingModule({
      imports: [RecommendationRunHistoryComponent, provideTranslocoTesting()],
      providers: [
        { provide: ReaderApi, useValue: { runHistory, runHistoryMonth } },
        { provide: RecommendationsService, useValue: { completedStamp } },
        { provide: LanguageService, useValue: { lang } },
      ],
    });
  });

  it('renders nothing until the account has run at least once', () => {
    const el = mount(EMPTY_OVERVIEW);

    expect(el.querySelector('app-settings-card')).toBeNull();
  });

  it('shows the all-time total, not the sum of the rows on screen', () => {
    const el = mount(OVERVIEW);

    expect(el.querySelector('.run-history__total-value')?.textContent?.trim()).toBe('$ 0.91820');
  });

  it('shows an em dash for a total no run ever priced', () => {
    const el = mount({ ...OVERVIEW, totalCostNanoCredits: null });

    expect(el.querySelector('.run-history__total-value')?.textContent?.trim()).toBe('—');
  });

  it('sends the browser timezone on the overview fetch', () => {
    mount(OVERVIEW);

    expect(runHistory).toHaveBeenCalledWith(BROWSER_TZ);
  });

  it('renders the newest month expanded with its rows, and older months collapsed with none', () => {
    const el = mount(OVERVIEW);

    const sections = months(el);
    expect(sections).toHaveLength(2);
    // Every month keeps its collapse control -- only the initial open state
    // and row presence differ between the newest and older months.
    expect(detailsOf(sections[0]).open).toBe(true);
    expect(sections[0].querySelectorAll('.run-history-month__row')).toHaveLength(1);
    expect(detailsOf(sections[1]).open).toBe(false);
    expect(sections[1].querySelectorAll('.run-history-month__row')).toHaveLength(0);
  });

  it('lets the reader collapse the newest month, and a later re-render does not force it back open', () => {
    const el = mount(OVERVIEW);
    const newest = detailsOf(months(el)[0]);
    expect(newest.open).toBe(true);

    newest.open = false;
    newest.dispatchEvent(new Event('toggle'));
    fixture.detectChanges();

    expect(newest.open).toBe(false);
  });

  it('opening an older month fetches its first page with the browser timezone and renders the rows', () => {
    runHistoryMonth.mockReturnValue(
      of({ month: '2026-07', runs: [UNPRICED_RUN], nextCursor: null }),
    );
    const el = mount(OVERVIEW);

    openMonth(months(el)[1]);

    expect(runHistoryMonth).toHaveBeenCalledWith('2026-07', BROWSER_TZ);
    expect(months(el)[1].querySelectorAll('.run-history-month__row')).toHaveLength(1);
  });

  it('fetches an already-opened month only once', () => {
    runHistoryMonth.mockReturnValue(
      of({ month: '2026-07', runs: [UNPRICED_RUN], nextCursor: null }),
    );
    mount(OVERVIEW);

    fixture.componentInstance.onOpened('2026-07');
    fixture.componentInstance.onOpened('2026-07');

    expect(runHistoryMonth).toHaveBeenCalledTimes(1);
  });

  it('"show more" fetches with the section\'s nextCursor and appends rather than replaces', () => {
    const overviewWithMore: RunHistoryOverview = {
      ...OVERVIEW,
      latest: { month: '2026-08', runs: [PRICED_RUN], nextCursor: 41 },
    };
    runHistoryMonth.mockReturnValue(
      of({ month: '2026-08', runs: [UNPRICED_RUN], nextCursor: null }),
    );
    const el = mount(overviewWithMore);

    const newest = months(el)[0];
    (newest.querySelector('.run-history-month__more') as HTMLButtonElement).click();
    fixture.detectChanges();

    expect(runHistoryMonth).toHaveBeenCalledWith('2026-08', BROWSER_TZ, 41);
    expect(newest.querySelectorAll('.run-history-month__row')).toHaveLength(2);
  });

  it('re-fetches when a run completes', () => {
    mount(OVERVIEW);
    expect(runHistory).toHaveBeenCalledTimes(1);

    completedStamp.set(1);
    fixture.detectChanges();

    expect(runHistory).toHaveBeenCalledTimes(2);
  });

  it('on completion, replaces the newest month wholesale and leaves an opened older month standing', () => {
    runHistoryMonth.mockReturnValue(
      of({ month: '2026-07', runs: [UNPRICED_RUN], nextCursor: null }),
    );
    const el = mount(OVERVIEW);

    openMonth(months(el)[1]);
    expect(months(el)[1].querySelectorAll('.run-history-month__row')).toHaveLength(1);

    runHistory.mockReturnValue(
      of({
        totalCostNanoCredits: 1_000_000_000,
        months: [{ ...AUGUST, runCount: 3 }, JULY],
        latest: { month: '2026-08', runs: [PRICED_RUN, OTHER_PRICED_RUN], nextCursor: null },
      }),
    );
    completedStamp.set(1);
    fixture.detectChanges();

    const sections = months(el);
    expect(sections[0].querySelectorAll('.run-history-month__row')).toHaveLength(2);
    // The older month keeps the rows it already fetched and stays expanded --
    // a completed run can only land in the current month, so it has nothing
    // new to tell this section.
    expect(sections[1].querySelectorAll('.run-history-month__row')).toHaveLength(1);
    expect(detailsOf(sections[1]).open).toBe(true);
  });

  it('a failed "show more" clears loading and leaves the rows already loaded standing', () => {
    jest.useFakeTimers();
    const overviewWithMore: RunHistoryOverview = {
      ...OVERVIEW,
      latest: { month: '2026-08', runs: [PRICED_RUN], nextCursor: 41 },
    };
    runHistoryMonth.mockReturnValue(throwError(() => new Error('the endpoint is down')));
    const el = mount(overviewWithMore);

    fixture.componentInstance.onShowMore('2026-08');
    fixture.detectChanges();

    expect(() => jest.runOnlyPendingTimers()).not.toThrow();
    fixture.detectChanges();
    const newest = months(el)[0];
    expect(newest.querySelectorAll('.run-history-month__row')).toHaveLength(1);
    // `loading` must come back down on the error path too, or "show more"
    // stays disabled forever after one failed page fetch.
    expect((newest.querySelector('.run-history-month__more') as HTMLButtonElement).disabled).toBe(
      false,
    );
    jest.useRealTimers();
  });

  /** The effect re-fires on every completed run, so an endpoint that stays
   *  broken would throw once per run for as long as the settings page is open.
   *  The sections already on screen stay. */
  it('leaves the sections standing when a re-fetch fails, and does not throw', () => {
    jest.useFakeTimers();
    const el = mount(OVERVIEW);

    runHistory.mockReturnValue(throwError(() => new Error('the endpoint is down')));
    completedStamp.set(1);
    fixture.detectChanges();

    expect(() => jest.runOnlyPendingTimers()).not.toThrow();
    expect(months(el)).toHaveLength(2);
    expect(el.querySelector('.run-history__total-value')?.textContent?.trim()).toBe('$ 0.91820');
    jest.useRealTimers();
  });
});
