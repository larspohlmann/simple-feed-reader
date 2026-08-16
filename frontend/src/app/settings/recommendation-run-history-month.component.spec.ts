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
}

const DEFAULT_PROPS: MountProps = {
  month: '2026-08',
  runCount: 3,
  costNanoCredits: 41_230_000,
  runs: null,
  nextCursor: null,
  loading: false,
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

    expect(el.querySelectorAll('.run-history-month__row')).toHaveLength(0);
  });

  it('renders one row per run once the month has rows', () => {
    const el = mount({ runs: [PRICED_RUN, UNPRICED_RUN] });

    expect(el.querySelectorAll('.run-history-month__row')).toHaveLength(2);
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

    expect(el.querySelector('.run-history-month__provider')?.textContent).toContain(
      'unknown provider',
    );
  });

  it('renders an empty duration cell for a run that has not finished', () => {
    const el = mount({ runs: [{ ...PRICED_RUN, durationSeconds: null }] });

    expect(el.querySelector('.run-history-month__duration')?.textContent?.trim()).toBe('');
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

  it('renders the loading label while a closed month is being fetched', () => {
    const el = mount({ runs: null, loading: true });

    expect(el.querySelector('.run-history-month__loading')?.textContent?.trim()).toBe('Loading…');
  });
});
