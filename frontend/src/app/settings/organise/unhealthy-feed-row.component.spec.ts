import { ComponentFixture, TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { LanguageService } from '../../core/language.service';
import { makeSubscription } from '../../reader/testing/subscription.factory';
import { UnhealthyFeedRowComponent } from './unhealthy-feed-row.component';

const GONE_FEED = makeSubscription({
  title: 'Dead Blog',
  status: 'gone',
  lastFetchedAt: '2026-08-01T00:00:00Z',
  lastSuccessfulFetchAt: '2026-07-01T00:00:00Z',
  consecutiveFailures: 5,
  lastErrorMessage: 'HTTP 410 Gone',
});

describe('UnhealthyFeedRowComponent', () => {
  let fixture: ComponentFixture<UnhealthyFeedRowComponent>;

  async function render(subscription = GONE_FEED) {
    await TestBed.configureTestingModule({
      imports: [UnhealthyFeedRowComponent, provideTranslocoTesting()],
      providers: [{ provide: LanguageService, useValue: { lang: () => 'en' } }],
    }).compileComponents();

    fixture = TestBed.createComponent(UnhealthyFeedRowComponent);
    fixture.componentRef.setInput('subscription', subscription);
    fixture.detectChanges();
  }

  it('shows the Dead pill and the "no longer available" reason for a gone feed', async () => {
    await render();

    const text = fixture.nativeElement.textContent as string;
    expect(text).toContain('Dead');
    expect(text).toContain('No longer available');
  });

  it('shows the Failing pill for an erroring feed', async () => {
    await render(makeSubscription({ status: 'erroring', consecutiveFailures: 3 }));

    const text = fixture.nativeElement.textContent as string;
    expect(text).toContain('Failing');
  });

  it('emits retry when the Retry button is clicked', async () => {
    await render();
    const retried = jest.fn();
    fixture.componentInstance.retry.subscribe(retried);

    fixture.debugElement.query(By.css('[data-test="retry"]')).nativeElement.click();

    expect(retried).toHaveBeenCalled();
  });

  it('emits unsubscribe when the Unsubscribe button is clicked', async () => {
    await render();
    const unsubscribed = jest.fn();
    fixture.componentInstance.unsubscribe.subscribe(unsubscribed);

    fixture.debugElement.query(By.css('[data-test="unsubscribe"]')).nativeElement.click();

    expect(unsubscribed).toHaveBeenCalled();
  });

  it('does not toggle the details disclosure when a control is clicked', async () => {
    await render();

    fixture.debugElement.query(By.css('[data-test="retry"]')).nativeElement.click();
    fixture.detectChanges();

    const details = fixture.debugElement.query(By.css('details'))
      .nativeElement as HTMLDetailsElement;
    expect(details.open).toBe(false);
  });

  it('toggles the details disclosure when the row body is clicked', async () => {
    await render();

    fixture.debugElement.query(By.css('summary')).nativeElement.click();
    fixture.detectChanges();

    const details = fixture.debugElement.query(By.css('details'))
      .nativeElement as HTMLDetailsElement;
    expect(details.open).toBe(true);
  });

  it('shows the raw technical error inside the details body', async () => {
    await render();

    const text = fixture.nativeElement.textContent as string;
    expect(text).toContain('HTTP 410 Gone');
  });

  it('hides a details row whose value is null', async () => {
    await render(
      makeSubscription({
        status: 'gone',
        lastFetchedAt: null,
        lastSuccessfulFetchAt: null,
        lastErrorMessage: null,
      }),
    );

    expect(fixture.debugElement.query(By.css('.technical'))).toBeNull();
  });
});
