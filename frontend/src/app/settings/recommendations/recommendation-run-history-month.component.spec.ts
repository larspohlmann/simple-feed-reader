import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { RecommendationRunHistoryMonthComponent } from './recommendation-run-history-month.component';
import { RunHistoryRow } from '../settings.models';
import { LanguageService } from '../../core/i18n/language.service';
import { Lang } from '../../core/i18n/language';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';

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

interface MountProperties {
  month: string;
  runCount: number;
  costNanoCredits: number | null;
  runs: RunHistoryRow[] | null;
  nextCursor: number | null;
  loading: boolean;
  failed: boolean;
}

const DEFAULT_PROPS: MountProperties = {
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

  function mount(overrides: Partial<MountProperties> = {}) {
    const properties = { ...DEFAULT_PROPS, ...overrides };
    fixture = TestBed.createComponent(RecommendationRunHistoryMonthComponent);
    fixture.componentRef.setInput('month', properties.month);
    fixture.componentRef.setInput('runCount', properties.runCount);
    fixture.componentRef.setInput('costNanoCredits', properties.costNanoCredits);
    fixture.componentRef.setInput('runs', properties.runs);
    fixture.componentRef.setInput('nextCursor', properties.nextCursor);
    fixture.componentRef.setInput('loading', properties.loading);
    fixture.componentRef.setInput('failed', properties.failed);
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
    const element = mount({ month: '2026-08' });

    expect(element.querySelector('.run-history-month__label')?.textContent?.trim()).toBe(
      'August 2026',
    );
  });

  it('renders the month label in German, and it differs from the English label', () => {
    lang.set('de');
    const elementDe = mount({ month: '2026-12' });
    expect(elementDe.querySelector('.run-history-month__label')?.textContent?.trim()).toBe(
      'Dezember 2026',
    );

    lang.set('en');
    const elementEn = mount({ month: '2026-12' });
    expect(elementEn.querySelector('.run-history-month__label')?.textContent?.trim()).toBe(
      'December 2026',
    );
  });

  it("shows the month's own run count and cost in the header", () => {
    const element = mount({ runCount: 5, costNanoCredits: 41_230_000 });

    const meta = element.querySelector('.run-history-month__meta')?.textContent ?? '';
    expect(meta).toContain('5');
    expect(meta).toContain('$ 0.0412');
  });

  it('shows an em dash in the header when nothing in the month reported a price', () => {
    const element = mount({ costNanoCredits: null });

    expect(element.querySelector('.run-history-month__meta')?.textContent).toContain('—');
  });

  it('uses the singular phrasing for a month with exactly one run', () => {
    const element = mount({ runCount: 1 });

    expect(element.querySelector('.run-history-month__meta')?.textContent).toContain('1 run ·');
  });

  it('uses the plural phrasing for a month with more than one run', () => {
    const element = mount({ runCount: 2 });

    expect(element.querySelector('.run-history-month__meta')?.textContent).toContain('2 runs ·');
  });

  it('renders no rows while the month has not been opened', () => {
    const element = mount({ runs: null });

    expect(
      element.querySelectorAll('.run-history-month__list .run-history-month__row'),
    ).toHaveLength(0);
  });

  it('renders one row per run once the month has rows', () => {
    const element = mount({ runs: [PRICED_RUN, UNPRICED_RUN] });

    // Scoped to `&__list`: the header strip above it carries the same
    // `&__row` class (so its grid can never drift from the rows'), and an
    // unscoped query would count it as a seventh "row".
    expect(
      element.querySelectorAll('.run-history-month__list .run-history-month__row'),
    ).toHaveLength(2);
  });

  it('starts open when it already has rows', () => {
    const element = mount({ runs: [PRICED_RUN] });

    expect((element.querySelector('details') as HTMLDetailsElement).open).toBe(true);
  });

  it('starts closed while it has not been opened', () => {
    const element = mount({ runs: null });

    expect((element.querySelector('details') as HTMLDetailsElement).open).toBe(false);
  });

  it('can be collapsed again once it has rows, and stays collapsed across a re-render', () => {
    const element = mount({ runs: [PRICED_RUN] });
    const details = element.querySelector('details') as HTMLDetailsElement;
    expect(details.open).toBe(true);

    details.open = false;
    details.dispatchEvent(new Event('toggle'));
    // Same `runs` reference in, so `startOpen` (bound to `runs() !== null`)
    // does not change value -- a later change detection must not re-open it.
    fixture.detectChanges();

    expect(details.open).toBe(false);
  });

  it('falls back to the translated "unknown provider" for a run that was never stamped', () => {
    const element = mount({ runs: [{ ...PRICED_RUN, providerHost: null }] });

    // Scoped to `&__list`: the header strip above it shares the `&__provider`
    // class too (see the header-columns test), so an unscoped query would
    // hit the "Provider" column label instead of this run's cell.
    expect(
      element.querySelector('.run-history-month__list .run-history-month__provider')?.textContent,
    ).toContain('unknown provider');
  });

  it('renders no duration value for a run that has not finished, only its label', () => {
    const element = mount({ runs: [{ ...PRICED_RUN, durationSeconds: null }] });
    const cell = element.querySelector(
      '.run-history-month__list .run-history-month__duration',
    ) as HTMLElement;
    const label = cell.querySelector('.run-history-month__cell-label') as HTMLElement;

    // The label stays (every cell carries one, finished or not); it is the
    // duration value itself -- everything but the label -- that must be
    // absent, or an unfinished run would misreport a duration of "0:00".
    expect((cell.textContent ?? '').replace(label.textContent ?? '', '').trim()).toBe('');
  });

  it('renders the day without the month’s year, since the section is already headed with it', () => {
    const element = mount({ runs: [PRICED_RUN] });
    const when =
      element.querySelector('.run-history-month__list .run-history-month__when')?.textContent ?? '';

    expect(when).not.toContain('2026');
    expect(when).toContain('16');
  });

  it('renders a long model string in full rather than truncating it', () => {
    const longModelRun: RunHistoryRow = {
      ...PRICED_RUN,
      providerHost: 'openrouter.ai',
      model: 'deepseek/deepseek-v4-pro',
    };
    const element = mount({ runs: [longModelRun] });

    expect(
      element.querySelector('.run-history-month__list .run-history-month__provider')?.textContent,
    ).toContain('deepseek/deepseek-v4-pro');
  });

  it('renders the six row-1 column headers, hidden from assistive tech, and no provider header', () => {
    const element = mount({ runs: [PRICED_RUN] });
    const header = element.querySelector('.run-history-month__row--header') as HTMLElement;

    expect(header.getAttribute('aria-hidden')).toBe('true');
    expect(header.querySelector('.run-history-month__when')?.textContent?.trim()).toBe('When');
    // Scoped the same way as the token headers below, and for the same
    // reason: the status header cell also carries `&__col-icon`, the glyph
    // that replaces the word below the mobile breakpoint (#465), and a
    // Material Symbol is a text ligature -- an unscoped query reads
    // "Status flag".
    expect(
      header
        .querySelector('.run-history-month__status .run-history-month__col-full')
        ?.textContent?.trim(),
    ).toBe('Status');
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

  /* #465: the header WORD, not the icon, sized the status track and didn't
     fit at 390px. The glyph must exist in the DOM for the stylesheet to
     swap it in. */
  it('carries a status header glyph for the mobile track, in the DOM at every width', () => {
    const element = mount({ runs: [PRICED_RUN] });

    const glyph = element.querySelector(
      '.run-history-month__row--header .run-history-month__status .run-history-month__col-icon .material-symbols-outlined',
    );

    expect(glyph?.textContent?.trim()).toBe('flag');
  });

  it('carries the short "In"/"Out" header text for the mobile track, in the DOM at every width', () => {
    const element = mount({ runs: [PRICED_RUN] });
    const header = element.querySelector('.run-history-month__row--header') as HTMLElement;

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
    const element = mount({ runs: [PRICED_RUN] });
    const row = element.querySelector(
      '.run-history-month__row:not(.run-history-month__row--header)',
    );
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
    const element = mount({ runs: [PRICED_RUN] });
    const row = element.querySelector(
      '.run-history-month__list .run-history-month__row',
    ) as HTMLElement;
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
    const element = mount({ runs: [PRICED_RUN] });
    const tokensInCell = element.querySelector(
      '.run-history-month__list .run-history-month__tokens-in',
    ) as HTMLElement;
    const tokensOutCell = element.querySelector(
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
    const element = mount({ runs: [PRICED_RUN] }); // status: 'completed'

    const icon = element.querySelector(
      '.run-history-month__list .run-history-month__status-icon .material-symbols-outlined',
    );

    expect(icon?.textContent?.trim()).toBe('check_circle');
  });

  it('keeps the raw status word in the DOM for assistive technology, alongside the icon', () => {
    const element = mount({ runs: [PRICED_RUN] });

    const word = element.querySelector('.run-history-month__list .run-history-month__status-word');

    expect(word?.textContent?.trim()).toBe('completed');
  });

  it('hides "show more" when the month has no further page', () => {
    const element = mount({ runs: [PRICED_RUN], nextCursor: null });

    expect(element.querySelector('.run-history-month__more')).toBeNull();
  });

  it('shows "show more" when another page is available', () => {
    const element = mount({ runs: [PRICED_RUN], nextCursor: 40 });

    expect(element.querySelector('.run-history-month__more')).not.toBeNull();
  });

  it('emits showMore when "show more" is clicked', () => {
    const element = mount({ runs: [PRICED_RUN], nextCursor: 40 });
    let emitted = 0;
    fixture.componentInstance.showMore.subscribe(() => emitted++);

    (element.querySelector('.run-history-month__more') as HTMLButtonElement).click();

    expect(emitted).toBe(1);
  });

  it('emits opened when a closed month is opened', () => {
    const element = mount({ runs: null });
    let emitted = 0;
    fixture.componentInstance.opened.subscribe(() => emitted++);

    // jsdom's native <details> toggles `.open` on a summary click but does
    // not dispatch the `toggle` event (a known jsdom gap), so this drives the
    // event directly -- the same workaround the shared disclosure's own spec
    // uses.
    const details = element.querySelector('details') as HTMLDetailsElement;
    details.open = true;
    details.dispatchEvent(new Event('toggle'));

    expect(emitted).toBe(1);
  });

  /** A month whose first page could not be fetched looks exactly like one
   *  nobody has opened yet -- no rows and nothing loading -- so the failure
   *  needs a flag of its own, and a line, or the open section is just blank. */
  it('renders a failure line when the first page could not be fetched', () => {
    const element = mount({ runs: null, loading: false, failed: true });

    expect(element.querySelector('.run-history-month__failed')?.textContent?.trim()).toBe(
      'This month could not be loaded. Close and open it to try again.',
    );
  });

  it('renders no failure line for a month that has simply never been opened', () => {
    const element = mount({ runs: null, loading: false, failed: false });

    expect(element.querySelector('.run-history-month__failed')).toBeNull();
  });

  it('renders the loading label while a closed month is being fetched', () => {
    const element = mount({ runs: null, loading: true });

    expect(element.querySelector('.run-history-month__loading')?.textContent?.trim()).toBe(
      'Loading…',
    );
  });
});
