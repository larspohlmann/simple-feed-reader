import { ComponentFixture, TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { LanguageService } from '../../core/language.service';
import { formatLongDateTime } from '../../reader/format';
import { makeSubscription } from '../../reader/testing/subscription.factory';
import { SubscriptionDto } from '../../reader/models';
import { FeedRefreshTimesComponent } from './feed-refresh-times.component';

describe('FeedRefreshTimesComponent', () => {
  let fixture: ComponentFixture<FeedRefreshTimesComponent>;

  async function render(subscription: SubscriptionDto) {
    await TestBed.configureTestingModule({
      imports: [FeedRefreshTimesComponent, provideTranslocoTesting()],
      providers: [{ provide: LanguageService, useValue: { lang: () => 'en' } }],
    }).compileComponents();

    fixture = TestBed.createComponent(FeedRefreshTimesComponent);
    fixture.componentRef.setInput('subscription', subscription);
    fixture.detectChanges();
  }

  function value(test: string): HTMLElement {
    return fixture.debugElement.query(By.css(`[data-test="${test}"] .value`)).nativeElement;
  }

  it('shows a relative time for each of checked, updated and next', async () => {
    await render(
      makeSubscription({
        lastFetchedAt: '2026-01-01T09:00:00Z',
        lastNewContentAt: '2026-01-01T08:00:00Z',
        nextFetchAt: '2099-01-01T10:00:00Z',
      }),
    );

    for (const test of ['checked', 'updated', 'next']) {
      expect((value(test).textContent ?? '').trim().length).toBeGreaterThan(0);
    }
  });

  it('carries the full timestamp as a hover tooltip on each time', async () => {
    await render(
      makeSubscription({
        lastFetchedAt: '2026-01-01T09:00:00Z',
        lastNewContentAt: '2026-01-01T08:00:00Z',
        nextFetchAt: '2099-01-01T10:00:00Z',
      }),
    );

    expect(value('checked').getAttribute('title')).toBe(
      formatLongDateTime('2026-01-01T09:00:00Z', 'en'),
    );
    expect(value('next').getAttribute('title')).toBe(
      formatLongDateTime('2099-01-01T10:00:00Z', 'en'),
    );
  });

  it('reads a never-fetched feed as "never", with no tooltip', async () => {
    await render(makeSubscription({ lastFetchedAt: null, lastSuccessfulFetchAt: null }));

    expect((value('checked').textContent ?? '').trim()).toBe('never');
    expect(value('checked').getAttribute('title')).toBeNull();
  });

  it('reads a gone feed with no next run as an em dash', async () => {
    await render(makeSubscription({ nextFetchAt: null }));

    expect((value('next').textContent ?? '').trim()).toBe('—');
  });
});
