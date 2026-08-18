import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { Subject, of, throwError } from 'rxjs';
import { RecommendationRunHistoryComponent } from './recommendation-run-history.component';
import { ReaderApi } from '../reader/reader-api';
import { RecommendationsService } from '../reader/recommendations.service';
import {
  RunHistoryMonth,
  RunHistoryMonthPage,
  RunHistoryOverview,
  RunHistoryRow,
} from '../reader/models';
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

/** One August row at a given id. Ids ascend with creation time, so an id is
 *  the whole ordering the card's paging depends on. */
function runWithId(id: number): RunHistoryRow {
  return { ...PRICED_RUN, id };
}

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

  /** Scoped to `&__list`: the month's header strip carries the same `&__row`
   *  class its rows do (so its grid can never drift out of alignment with
   *  them -- see the month component's own spec), and an unscoped query
   *  would count that strip as an extra row. */
  function rows(section: Element): NodeListOf<Element> {
    return section.querySelectorAll('.run-history-month__list .run-history-month__row');
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

  /** Closing is silent by design (`DisclosureComponent.opened` fires on the
   *  way open only), so this exists purely to set up the re-open that is not. */
  function closeMonth(section: Element): void {
    const details = section.querySelector('details') as HTMLDetailsElement;
    details.open = false;
    details.dispatchEvent(new Event('toggle'));
    fixture.detectChanges();
  }

  function showMore(section: Element): void {
    (section.querySelector('.run-history-month__more') as HTMLButtonElement).click();
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

    expect(el.querySelector('.run-history__total-value')?.textContent?.trim()).toBe('$ 0.9182');
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
    expect(rows(sections[0])).toHaveLength(1);
    expect(detailsOf(sections[1]).open).toBe(false);
    expect(rows(sections[1])).toHaveLength(0);
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
    expect(rows(months(el)[1])).toHaveLength(1);
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
    expect(rows(newest)).toHaveLength(2);
  });

  it('re-fetches when a run completes', () => {
    mount(OVERVIEW);
    expect(runHistory).toHaveBeenCalledTimes(1);

    completedStamp.set(1);
    fixture.detectChanges();

    expect(runHistory).toHaveBeenCalledTimes(2);
  });

  it('on completion, refreshes the newest month and leaves an opened older month standing', () => {
    runHistoryMonth.mockReturnValue(
      of({ month: '2026-07', runs: [UNPRICED_RUN], nextCursor: null }),
    );
    const el = mount(OVERVIEW);

    openMonth(months(el)[1]);
    expect(rows(months(el)[1])).toHaveLength(1);

    runHistory.mockReturnValue(
      of({
        totalCostNanoCredits: 1_000_000_000,
        months: [{ ...AUGUST, runCount: 3 }, JULY],
        // Newest first, as the wire orders it -- the new run's id is above
        // the one already on screen, so the page carries nothing extra.
        latest: { month: '2026-08', runs: [OTHER_PRICED_RUN, PRICED_RUN], nextCursor: null },
      }),
    );
    completedStamp.set(1);
    fixture.detectChanges();

    const sections = months(el);
    expect(rows(sections[0])).toHaveLength(2);
    // The older month keeps the rows it already fetched and stays expanded --
    // a completed run can only land in the current month, so it has nothing
    // new to tell this section.
    expect(rows(sections[1])).toHaveLength(1);
    expect(detailsOf(sections[1]).open).toBe(true);
  });

  /** The refetch on completion is exactly the moment a reader is most likely
   *  to be sitting on this card, and it must not throw away the pages they
   *  paged into. The fresh `latest` is always the month's FIRST page, so
   *  everything below it can only come from what was already on screen. */
  it('on completion, the newest month keeps the pages the reader had loaded, and its cursor', () => {
    const overviewWithMore: RunHistoryOverview = {
      ...OVERVIEW,
      latest: { month: '2026-08', runs: [runWithId(43)], nextCursor: 42 },
    };
    runHistoryMonth
      .mockReturnValueOnce(of({ month: '2026-08', runs: [runWithId(42)], nextCursor: 41 }))
      .mockReturnValueOnce(of({ month: '2026-08', runs: [runWithId(41)], nextCursor: 40 }));
    const el = mount(overviewWithMore);

    showMore(months(el)[0]);
    showMore(months(el)[0]);
    expect(rows(months(el)[0])).toHaveLength(3);

    runHistory.mockReturnValue(
      of({
        ...OVERVIEW,
        latest: { month: '2026-08', runs: [runWithId(44), runWithId(43)], nextCursor: 42 },
      }),
    );
    completedStamp.set(1);
    fixture.detectChanges();

    // The new run on top, then the three rows the reader had paged into --
    // ordered and without the duplicate 43 the fresh page also carries.
    const newest = months(el)[0];
    expect(rows(newest)).toHaveLength(4);
    // And the cursor still points past the oldest row on screen, not back at
    // the end of the first page.
    runHistoryMonth.mockReturnValue(of({ month: '2026-08', runs: [], nextCursor: null }));
    showMore(newest);
    expect(runHistoryMonth).toHaveBeenLastCalledWith('2026-08', BROWSER_TZ, 40);
  });

  it('on completion, a newest month the reader had paged to the end of offers no more pages', () => {
    const overviewWithMore: RunHistoryOverview = {
      ...OVERVIEW,
      latest: { month: '2026-08', runs: [runWithId(43)], nextCursor: 42 },
    };
    runHistoryMonth.mockReturnValue(
      of({ month: '2026-08', runs: [runWithId(42)], nextCursor: null }),
    );
    const el = mount(overviewWithMore);

    showMore(months(el)[0]);
    expect(months(el)[0].querySelector('.run-history-month__more')).toBeNull();

    runHistory.mockReturnValue(
      of({
        ...OVERVIEW,
        latest: { month: '2026-08', runs: [runWithId(44), runWithId(43)], nextCursor: 42 },
      }),
    );
    completedStamp.set(1);
    fixture.detectChanges();

    // The fresh page's own cursor would re-offer rows already on screen.
    expect(months(el)[0].querySelector('.run-history-month__more')).toBeNull();
    expect(rows(months(el)[0])).toHaveLength(3);
  });

  /** An overview refetch lands on every completed run, and an older month's
   *  first page can still be in flight when it does. Clearing that month's
   *  `loading` there disarms the second half of `onOpened`'s guard, and a
   *  close/re-open then fires a second identical GET. */
  it('an overview refetch leaves an older month that is still loading alone', () => {
    const inFlight = new Subject<RunHistoryMonthPage>();
    runHistoryMonth.mockReturnValue(inFlight);
    const el = mount(OVERVIEW);

    openMonth(months(el)[1]);
    expect(months(el)[1].querySelector('.run-history-month__loading')).not.toBeNull();

    completedStamp.set(1);
    fixture.detectChanges();

    expect(months(el)[1].querySelector('.run-history-month__loading')).not.toBeNull();

    closeMonth(months(el)[1]);
    openMonth(months(el)[1]);

    expect(runHistoryMonth).toHaveBeenCalledTimes(1);
  });

  /** Without a message an open month whose fetch failed is indistinguishable
   *  from a month with no runs -- and the recovery, closing and re-opening it,
   *  is undiscoverable. */
  it('a failed first page renders a failure line rather than an empty open section', () => {
    jest.useFakeTimers();
    runHistoryMonth.mockReturnValue(throwError(() => new Error('the endpoint is down')));
    const el = mount(OVERVIEW);

    openMonth(months(el)[1]);
    expect(() => jest.runOnlyPendingTimers()).not.toThrow();
    fixture.detectChanges();

    const older = months(el)[1];
    expect(older.querySelector('.run-history-month__failed')).not.toBeNull();
    expect(older.querySelector('.run-history-month__loading')).toBeNull();
    expect(detailsOf(older).open).toBe(true);
    jest.useRealTimers();
  });

  it('re-opening a failed month retries and clears the failure line', () => {
    jest.useFakeTimers();
    runHistoryMonth.mockReturnValueOnce(throwError(() => new Error('the endpoint is down')));
    const el = mount(OVERVIEW);

    openMonth(months(el)[1]);
    expect(() => jest.runOnlyPendingTimers()).not.toThrow();
    fixture.detectChanges();
    expect(months(el)[1].querySelector('.run-history-month__failed')).not.toBeNull();

    runHistoryMonth.mockReturnValue(
      of({ month: '2026-07', runs: [UNPRICED_RUN], nextCursor: null }),
    );
    closeMonth(months(el)[1]);
    openMonth(months(el)[1]);

    expect(runHistoryMonth).toHaveBeenCalledTimes(2);
    expect(months(el)[1].querySelector('.run-history-month__failed')).toBeNull();
    expect(rows(months(el)[1])).toHaveLength(1);
    jest.useRealTimers();
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
    expect(rows(newest)).toHaveLength(1);
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
  it('renders the status legend once in the card, covering all five statuses', () => {
    const el = mount(OVERVIEW);

    expect(el.querySelectorAll('.run-history__legend')).toHaveLength(1);
    const items = el.querySelectorAll('.run-history__legend-item');
    expect(items).toHaveLength(5);
    // Scoped to the item's own direct-child `<span>`, not the whole `<li>`
    // (which also holds the icon's own glyph text, e.g. "check_circle") and
    // not `<app-icon>`'s internal span, which `querySelector('span')` would
    // find first since it comes before the word in document order.
    const words = Array.from(items).map((item) =>
      item.querySelector(':scope > span')?.textContent?.trim(),
    );
    expect(words).toEqual(['completed', 'failed', 'cancelled', 'running', 'pending']);
  });

  /** The row's status icon and the legend's icon for the same status must be
   *  the exact same glyph -- both read `run-history-status-icon.ts`'s one
   *  map, so a change there cannot leave the two disagreeing (#409). */
  it('agrees with the row on the icon for a shared status', () => {
    const el = mount(OVERVIEW); // OVERVIEW's newest month has one completed run

    const rowIcon = el.querySelector(
      '.run-history-month__list .run-history-month__status-icon .material-symbols-outlined',
    );
    const legendIcon = el.querySelector(
      '.run-history__legend-item--completed .material-symbols-outlined',
    );

    expect(rowIcon?.textContent?.trim()).not.toBe('');
    expect(rowIcon?.textContent?.trim()).toBe(legendIcon?.textContent?.trim());
  });

  it('leaves the sections standing when a re-fetch fails, and does not throw', () => {
    jest.useFakeTimers();
    const el = mount(OVERVIEW);

    runHistory.mockReturnValue(throwError(() => new Error('the endpoint is down')));
    completedStamp.set(1);
    fixture.detectChanges();

    expect(() => jest.runOnlyPendingTimers()).not.toThrow();
    expect(months(el)).toHaveLength(2);
    expect(el.querySelector('.run-history__total-value')?.textContent?.trim()).toBe('$ 0.9182');
    jest.useRealTimers();
  });
});
