import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { of } from 'rxjs';
import { RecommendationRunHistoryComponent } from './recommendation-run-history.component';
import { ReaderApi } from '../reader/reader-api';
import { RecommendationsService } from '../reader/recommendations.service';
import { RunHistoryPayload, RunHistoryRow } from '../reader/models';
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

describe('RecommendationRunHistoryComponent', () => {
  let runHistory: jest.Mock;
  let completedStamp: ReturnType<typeof signal<number>>;
  let fixture: ReturnType<typeof TestBed.createComponent<RecommendationRunHistoryComponent>>;

  function mount(payload: RunHistoryPayload) {
    runHistory.mockReturnValue(of(payload));
    fixture = TestBed.createComponent(RecommendationRunHistoryComponent);
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  beforeEach(() => {
    runHistory = jest.fn().mockReturnValue(of({ runs: [], totalCostNanoCredits: null }));
    completedStamp = signal(0);

    TestBed.configureTestingModule({
      imports: [RecommendationRunHistoryComponent, provideTranslocoTesting()],
      providers: [
        { provide: ReaderApi, useValue: { runHistory } },
        { provide: RecommendationsService, useValue: { completedStamp } },
      ],
    });
  });

  it('renders nothing until the account has run at least once', () => {
    const el = mount({ runs: [], totalCostNanoCredits: null });

    expect(el.querySelector('app-settings-card')).toBeNull();
  });

  it('renders one row per run', () => {
    const el = mount({ runs: [PRICED_RUN, UNPRICED_RUN], totalCostNanoCredits: 41_230_000 });

    expect(el.querySelectorAll('.run-history__row')).toHaveLength(2);
  });

  it('renders a priced run as credits with four decimals', () => {
    const el = mount({ runs: [PRICED_RUN], totalCostNanoCredits: 41_230_000 });

    expect(el.querySelector('.run-history__cost')?.textContent?.trim()).toBe('0.0412');
  });

  it('renders an em dash when the provider reported no price', () => {
    const el = mount({ runs: [UNPRICED_RUN], totalCostNanoCredits: null });

    expect(el.querySelector('.run-history__cost')?.textContent?.trim()).toBe('—');
  });

  it('shows the all-time total, not the sum of the rows on screen', () => {
    const el = mount({ runs: [PRICED_RUN], totalCostNanoCredits: 918_200_000 });

    expect(el.querySelector('.run-history__total-value')?.textContent).toContain('0.9182');
  });

  it('shows an em dash for a total no run ever priced', () => {
    const el = mount({ runs: [UNPRICED_RUN], totalCostNanoCredits: null });

    expect(el.querySelector('.run-history__total-value')?.textContent?.trim()).toBe('—');
  });

  it('names the provider and the model the run actually called', () => {
    const el = mount({ runs: [PRICED_RUN], totalCostNanoCredits: null });

    expect(el.querySelector('.run-history__provider')?.textContent).toContain('openrouter.ai');
    expect(el.querySelector('.run-history__provider')?.textContent).toContain('x-ai/grok-4-fast');
  });

  it('re-fetches when a run completes', () => {
    mount({ runs: [PRICED_RUN], totalCostNanoCredits: null });
    expect(runHistory).toHaveBeenCalledTimes(1);

    completedStamp.set(1);
    fixture.detectChanges();

    expect(runHistory).toHaveBeenCalledTimes(2);
  });
});
