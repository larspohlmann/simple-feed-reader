import { ComponentFixture, TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { LanguageService } from '../../core/language.service';
import { makeSubscription } from '../../reader/testing/subscription.factory';
import { SubscriptionDto } from '../../reader/models';
import { FeedHealthFactsComponent } from './feed-health-facts.component';

const BROKEN = makeSubscription({
  status: 'gone',
  feedUrl: 'https://feed.example/rss',
  lastFetchedAt: '2026-08-01T09:05:00Z',
  lastSuccessfulFetchAt: '2026-07-01T09:05:00Z',
  consecutiveFailures: 4,
  lastErrorMessage: 'HTTP 410 Gone',
});

describe('FeedHealthFactsComponent', () => {
  let fixture: ComponentFixture<FeedHealthFactsComponent>;

  async function render(subscription: SubscriptionDto = BROKEN) {
    await TestBed.configureTestingModule({
      imports: [FeedHealthFactsComponent, provideTranslocoTesting()],
      providers: [{ provide: LanguageService, useValue: { lang: () => 'en' } }],
    }).compileComponents();

    fixture = TestBed.createComponent(FeedHealthFactsComponent);
    fixture.componentRef.setInput('subscription', subscription);
    fixture.detectChanges();
  }

  it('links the feed URL as an external link', async () => {
    await render();

    const link = fixture.debugElement.query(By.css('.feed-url')).nativeElement as HTMLAnchorElement;
    expect(link.getAttribute('href')).toBe('https://feed.example/rss');
    expect(link.textContent).toContain('https://feed.example/rss');
    expect(link.target).toBe('_blank');
    expect(link.rel).toContain('noopener');
  });

  it('shows a clock time on the last-success and last-attempt values', async () => {
    await render();

    // The feed-url value holds a link and the failure streak a bare number, so
    // the two timestamp values are the only ones carrying an HH:MM clock.
    const timed = fixture.debugElement
      .queryAll(By.css('.facts dd'))
      .map((el) => (el.nativeElement.textContent as string).trim())
      .filter((text) => /\d{1,2}:\d{2}/.test(text));
    expect(timed).toHaveLength(2);
  });

  it('omits a fact whose value is null', async () => {
    await render(
      makeSubscription({
        status: 'gone',
        lastFetchedAt: null,
        lastSuccessfulFetchAt: null,
        consecutiveFailures: 0,
      }),
    );

    // Only the always-present Feed URL row survives.
    expect(fixture.debugElement.queryAll(By.css('.facts dt'))).toHaveLength(1);
  });
});
