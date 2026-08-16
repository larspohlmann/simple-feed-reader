import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { RecommendationRunHistoryMonthComponent } from './recommendation-run-history-month.component';
import { RunHistoryRow } from '../reader/models';
import { LanguageService } from '../core/language.service';
import { Lang } from '../core/language';
import { provideTranslocoTesting } from '../../testing/transloco-testing';

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

const UNPRICED_RUN: RunHistoryRow = {
  ...PRICED_RUN,
  id: 41,
  providerHost: 'localhost',
  model: 'bonsai-27b',
  costNanoCredits: null,
};

interface MountProps {
  month: string;
  runCount: number;
  costNanoCredits: number | null;
  runs: RunHistoryRow[] | null;
  nextCursor: number | null;
  loading: boolean;
  failed: boolean;
}

const DEFAULT_PROPS: MountProps = {
  month: '2026-08',
  runCount: 3,
  costNanoCredits: 41_230_000,
  runs: null,
  nextCursor: null,
  loading: false,
  failed: false,
};

describe('RecommendationRunHistoryMonthComponent', () => {
  let lang: ReturnType<typeof signal<Lang>>;
  let fixture: ReturnType<typeof TestBed.createComponent<RecommendationRunHistoryMonthComponent>>;

  function mount(overrides: Partial<MountProps> = {}) {
    const props = { ...DEFAULT_PROPS, ...overrides };
    fixture = TestBed.createComponent(RecommendationRunHistoryMonthComponent);
    fixture.componentRef.setInput('month', props.month);
    fixture.componentRef.setInput('runCount', props.runCount);
    fixture.componentRef.setInput('costNanoCredits', props.costNanoCredits);
    fixture.componentRef.setInput('runs', props.runs);
    fixture.componentRef.setInput('nextCursor', props.nextCursor);
    fixture.componentRef.setInput('loading', props.loading);
    fixture.componentRef.setInput('failed', props.failed);
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  beforeEach(() => {
    lang = signal<Lang>('en');

    TestBed.configureTestingModule({
      imports: [RecommendationRunHistoryMonthComponent, provideTranslocoTesting()],
      providers: [{ provide: LanguageService, useValue: { lang } }],
    });
  });

  it('renders the month label through Intl on the active language', () => {
    const el = mount({ month: '2026-08' });

    expect(el.querySelector('.run-history-month__label')?.textContent?.trim()).toBe('August 2026');
  });

  it('renders the month label in German, and it differs from the English label', () => {
    lang.set('de');
    const elDe = mount({ month: '2026-12' });
    expect(elDe.querySelector('.run-history-month__label')?.textContent?.trim()).toBe(
      'Dezember 2026',
    );

    lang.set('en');
    const elEn = mount({ month: '2026-12' });
    expect(elEn.querySelector('.run-history-month__label')?.textContent?.trim()).toBe(
      'December 2026',
    );
  });

  it("shows the month's own run count and cost in the header", () => {
    const el = mount({ runCount: 5, costNanoCredits: 41_230_000 });

    const meta = el.querySelector('.run-history-month__meta')?.textContent ?? '';
    expect(meta).toContain('5');
    expect(meta).toContain('$ 0.04123');
  });

  it('shows an em dash in the header when nothing in the month reported a price', () => {
    const el = mount({ costNanoCredits: null });

    expect(el.querySelector('.run-history-month__meta')?.textContent).toContain('—');
  });

  it('uses the singular phrasing for a month with exactly one run', () => {
    const el = mount({ runCount: 1 });

    expect(el.querySelector('.run-history-month__meta')?.textContent).toContain('1 run ·');
  });

  it('uses the plural phrasing for a month with more than one run', () => {
    const el = mount({ runCount: 2 });

    expect(el.querySelector('.run-history-month__meta')?.textContent).toContain('2 runs ·');
  });

  it('renders no rows while the month has not been opened', () => {
    const el = mount({ runs: null });

    expect(el.querySelectorAll('.run-history-month__list .run-history-month__row')).toHaveLength(0);
  });

  it('renders one row per run once the month has rows', () => {
    const el = mount({ runs: [PRICED_RUN, UNPRICED_RUN] });

    // Scoped to `&__list`: the header strip above it carries the same
    // `&__row` class (so its grid can never drift from the rows'), and an
    // unscoped query would count it as a seventh "row".
    expect(el.querySelectorAll('.run-history-month__list .run-history-month__row')).toHaveLength(2);
  });

  it('starts open when it already has rows', () => {
    const el = mount({ runs: [PRICED_RUN] });

    expect((el.querySelector('details') as HTMLDetailsElement).open).toBe(true);
  });

  it('starts closed while it has not been opened', () => {
    const el = mount({ runs: null });

    expect((el.querySelector('details') as HTMLDetailsElement).open).toBe(false);
  });

  it('can be collapsed again once it has rows, and stays collapsed across a re-render', () => {
    const el = mount({ runs: [PRICED_RUN] });
    const details = el.querySelector('details') as HTMLDetailsElement;
    expect(details.open).toBe(true);

    details.open = false;
    details.dispatchEvent(new Event('toggle'));
    // Same `runs` reference in, so `startOpen` (bound to `runs() !== null`)
    // does not change value -- a later change detection must not re-open it.
    fixture.detectChanges();

    expect(details.open).toBe(false);
  });

  it('falls back to the translated "unknown provider" for a run that was never stamped', () => {
    const el = mount({ runs: [{ ...PRICED_RUN, providerHost: null }] });

    // Scoped to `&__list`: the header strip above it shares the `&__provider`
    // class too (see the header-columns test), so an unscoped query would
    // hit the "Provider" column label instead of this run's cell.
    expect(
      el.querySelector('.run-history-month__list .run-history-month__provider')?.textContent,
    ).toContain('unknown provider');
  });

  it('renders no duration value for a run that has not finished, only its label', () => {
    const el = mount({ runs: [{ ...PRICED_RUN, durationSeconds: null }] });
    const cell = el.querySelector(
      '.run-history-month__list .run-history-month__duration',
    ) as HTMLElement;
    const label = cell.querySelector('.run-history-month__cell-label') as HTMLElement;

    // The label stays (every cell carries one, finished or not); it is the
    // duration value itself -- everything but the label -- that must be
    // absent, or an unfinished run would misreport a duration of "0:00".
    expect((cell.textContent ?? '').replace(label.textContent ?? '', '').trim()).toBe('');
  });

  it('renders the day without the month’s year, since the section is already headed with it', () => {
    const el = mount({ runs: [PRICED_RUN] });
    const when =
      el.querySelector('.run-history-month__list .run-history-month__when')?.textContent ?? '';

    expect(when).not.toContain('2026');
    expect(when).toContain('16');
  });

  it('renders a long model string in full rather than truncating it', () => {
    const longModelRun: RunHistoryRow = {
      ...PRICED_RUN,
      providerHost: 'openrouter.ai',
      model: 'deepseek/deepseek-v4-pro',
    };
    const el = mount({ runs: [longModelRun] });

    expect(
      el.querySelector('.run-history-month__list .run-history-month__provider')?.textContent,
    ).toContain('deepseek/deepseek-v4-pro');
  });

  it('renders the six row-1 column headers, hidden from assistive tech, and no provider header', () => {
    const el = mount({ runs: [PRICED_RUN] });
    const header = el.querySelector('.run-history-month__row--header') as HTMLElement;

    expect(header.getAttribute('aria-hidden')).toBe('true');
    expect(header.querySelector('.run-history-month__when')?.textContent?.trim()).toBe('When');
    expect(header.querySelector('.run-history-month__status')?.textContent?.trim()).toBe('Status');
    expect(header.querySelector('.run-history-month__duration')?.textContent?.trim()).toBe('Time');
    // Scoped to `&__col-full`: the cell also carries `&__col-short` ("In"),
    // shown only below the mobile breakpoint -- an unscoped query would run
    // the two together.
    expect(
      header
        .querySelector('.run-history-month__tokens-in .run-history-month__col-full')
        ?.textContent?.trim(),
    ).toBe('Tokens in');
    expect(
      header
        .querySelector('.run-history-month__tokens-out .run-history-month__col-full')
        ?.textContent?.trim(),
    ).toBe('Tokens out');
    expect(header.querySelector('.run-history-month__cost')?.textContent?.trim()).toBe('Cost');
    // The provider cell moved to its own full-width row 2 and has no column
    // header of its own -- see the provider-cell test below.
    expect(header.querySelector('.run-history-month__provider')).toBeNull();
  });

  it('carries the short "In"/"Out" header text for the mobile track, in the DOM at every width', () => {
    const el = mount({ runs: [PRICED_RUN] });
    const header = el.querySelector('.run-history-month__row--header') as HTMLElement;

    expect(
      header
        .querySelector('.run-history-month__tokens-in .run-history-month__col-short')
        ?.textContent?.trim(),
    ).toBe('In');
    expect(
      header
        .querySelector('.run-history-month__tokens-out .run-history-month__col-short')
        ?.textContent?.trim(),
    ).toBe('Out');
  });

  it('gives every row cell a label carrying that column’s name, provider included', () => {
    const el = mount({ runs: [PRICED_RUN] });
    const row = el.querySelector('.run-history-month__row:not(.run-history-month__row--header)');
    const labels = Array.from(row?.querySelectorAll('.run-history-month__cell-label') ?? []).map(
      (label) => label.textContent?.trim(),
    );

    expect(labels).toEqual([
      'When',
      'Status',
      'Time',
      'Tokens in',
      'Tokens out',
      'Cost',
      'Provider',
    ]);
  });

  it('renders the provider cell last, on its own full-width row, carrying its own label', () => {
    const el = mount({ runs: [PRICED_RUN] });
    const row = el.querySelector('.run-history-month__list .run-history-month__row') as HTMLElement;
    const cells = Array.from(row.children);

    // Row 1's six named cells, then the provider cell -- grid auto-placement
    // (default `grid-auto-flow: row`) puts the seventh item into an implicit
    // row 2 once row 1's six explicit columns are full, and `&__provider`'s
    // `grid-column: 1 / -1` widens what lands there to span it.
    expect((cells.at(-1) as HTMLElement).classList.contains('run-history-month__provider')).toBe(
      true,
    );
    const providerLabel = (cells.at(-1) as HTMLElement).querySelector(
      '.run-history-month__cell-label',
    );
    expect(providerLabel?.textContent?.trim()).toBe('Provider');
  });

  it('renders tokens in and tokens out as separate cells holding bare numbers', () => {
    const el = mount({ runs: [PRICED_RUN] });
    const tokensInCell = el.querySelector(
      '.run-history-month__list .run-history-month__tokens-in',
    ) as HTMLElement;
    const tokensOutCell = el.querySelector(
      '.run-history-month__list .run-history-month__tokens-out',
    ) as HTMLElement;
    const tokensInLabel = tokensInCell.querySelector(
      '.run-history-month__cell-label',
    ) as HTMLElement;
    const tokensOutLabel = tokensOutCell.querySelector(
      '.run-history-month__cell-label',
    ) as HTMLElement;

    // PRICED_RUN.promptTokens = 118432, PRICED_RUN.completionTokens = 2216 --
    // bare figures, no "in"/"out" wording left in the value now that the
    // column header names which is which.
    expect(
      (tokensInCell.textContent ?? '').replace(tokensInLabel.textContent ?? '', '').trim(),
    ).toBe('118432');
    expect(
      (tokensOutCell.textContent ?? '').replace(tokensOutLabel.textContent ?? '', '').trim(),
    ).toBe('2216');
  });

  it('renders the icon matching the row status', () => {
    const el = mount({ runs: [PRICED_RUN] }); // status: 'completed'

    const icon = el.querySelector(
      '.run-history-month__list .run-history-month__status-icon .material-symbols-outlined',
    );

    expect(icon?.textContent?.trim()).toBe('check_circle');
  });

  it('keeps the raw status word in the DOM for assistive technology, alongside the icon', () => {
    const el = mount({ runs: [PRICED_RUN] });

    const word = el.querySelector('.run-history-month__list .run-history-month__status-word');

    expect(word?.textContent?.trim()).toBe('completed');
  });

  it('hides "show more" when the month has no further page', () => {
    const el = mount({ runs: [PRICED_RUN], nextCursor: null });

    expect(el.querySelector('.run-history-month__more')).toBeNull();
  });

  it('shows "show more" when another page is available', () => {
    const el = mount({ runs: [PRICED_RUN], nextCursor: 40 });

    expect(el.querySelector('.run-history-month__more')).not.toBeNull();
  });

  it('emits showMore when "show more" is clicked', () => {
    const el = mount({ runs: [PRICED_RUN], nextCursor: 40 });
    let emitted = 0;
    fixture.componentInstance.showMore.subscribe(() => emitted++);

    (el.querySelector('.run-history-month__more') as HTMLButtonElement).click();

    expect(emitted).toBe(1);
  });

  it('emits opened when a closed month is opened', () => {
    const el = mount({ runs: null });
    let emitted = 0;
    fixture.componentInstance.opened.subscribe(() => emitted++);

    // jsdom's native <details> toggles `.open` on a summary click but does
    // not dispatch the `toggle` event (a known jsdom gap), so this drives the
    // event directly -- the same workaround the shared disclosure's own spec
    // uses.
    const details = el.querySelector('details') as HTMLDetailsElement;
    details.open = true;
    details.dispatchEvent(new Event('toggle'));

    expect(emitted).toBe(1);
  });

  /** A month whose first page could not be fetched looks exactly like one
   *  nobody has opened yet -- no rows and nothing loading -- so the failure
   *  needs a flag of its own, and a line, or the open section is just blank. */
  it('renders a failure line when the first page could not be fetched', () => {
    const el = mount({ runs: null, loading: false, failed: true });

    expect(el.querySelector('.run-history-month__failed')?.textContent?.trim()).toBe(
      'This month could not be loaded. Close and open it to try again.',
    );
  });

  it('renders no failure line for a month that has simply never been opened', () => {
    const el = mount({ runs: null, loading: false, failed: false });

    expect(el.querySelector('.run-history-month__failed')).toBeNull();
  });

  it('renders the loading label while a closed month is being fetched', () => {
    const el = mount({ runs: null, loading: true });

    expect(el.querySelector('.run-history-month__loading')?.textContent?.trim()).toBe('Loading…');
  });
});
