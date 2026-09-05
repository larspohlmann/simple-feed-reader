import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { DialogRef, DIALOG_DATA } from '@angular/cdk/dialog';
import { of } from 'rxjs';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { LanguageService } from '../../core/language.service';
import { ManageActions } from '../../reader/manage/manage-actions.service';
import { makeSubscription } from '../../reader/testing/subscription.factory';
import { SubscriptionsStore } from '../../reader/subscriptions.store';
import { SubscriptionDto } from '../../reader/models';
import { HealthErrorDialogComponent } from './health-error-dialog.component';

const FEED = makeSubscription({
  feedId: 50,
  title: 'konkret',
  status: 'erroring',
  lastSuccessfulFetchAt: '2026-07-01T09:00:00Z',
  lastFetchedAt: '2026-08-01T09:00:00Z',
  consecutiveFailures: 10,
  lastErrorMessage: 'Document is not well-formed XML',
});

describe('HealthErrorDialogComponent', () => {
  let fixture: ComponentFixture<HealthErrorDialogComponent>;
  const close = jest.fn();
  const retryFeed = jest.fn();
  const unsubscribe = jest.fn();

  async function render(subscriptions: SubscriptionDto[] = [FEED]) {
    retryFeed.mockReturnValue(of(true));
    await TestBed.configureTestingModule({
      imports: [HealthErrorDialogComponent, provideTranslocoTesting()],
      providers: [
        { provide: DIALOG_DATA, useValue: { feedId: 50, title: 'konkret' } },
        { provide: DialogRef, useValue: { close } },
        { provide: SubscriptionsStore, useValue: { subscriptions: signal(subscriptions) } },
        { provide: ManageActions, useValue: { retryFeed, unsubscribe } },
        { provide: LanguageService, useValue: { lang: () => 'en' } },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(HealthErrorDialogComponent);
    fixture.detectChanges();
  }

  function click(dataTest: string) {
    fixture.debugElement.query(By.css(`[data-test="${dataTest}"] button`)).nativeElement.click();
  }

  it('leads with the raw error, shows the title, the Failing status and the facts', async () => {
    await render();

    const text = fixture.nativeElement.textContent as string;
    expect(text).toContain('konkret');
    expect(text).toContain('Failing');
    expect(fixture.debugElement.query(By.css('.hero-code')).nativeElement.textContent).toContain(
      'Document is not well-formed XML',
    );
    // The failure streak shows as a stat figure, and the facts grid carries the URL.
    expect(fixture.debugElement.queryAll(By.css('.stat')).length).toBe(2);
    expect(fixture.debugElement.query(By.css('.feed-url'))).not.toBeNull();
  });

  it('reads the feed live from the store by id', async () => {
    // The dialog was opened with feedId 50 but the store also holds other feeds.
    await render([makeSubscription({ feedId: 1, title: 'other' }), FEED]);

    expect(fixture.nativeElement.textContent as string).toContain('konkret');
  });

  it('retries through ManageActions and stays open while the feed still fails', async () => {
    await render();

    click('dialog-retry');

    expect(retryFeed).toHaveBeenCalledWith(FEED);
    expect(close).not.toHaveBeenCalled();
  });

  it('closes after a retry that recovers the feed', async () => {
    await render();
    retryFeed.mockReturnValue(of(false));

    click('dialog-retry');

    expect(close).toHaveBeenCalled();
  });

  it('closes, then unsubscribes through ManageActions', async () => {
    await render();

    click('dialog-unsubscribe');

    expect(close).toHaveBeenCalled();
    expect(unsubscribe).toHaveBeenCalledWith(FEED);
  });
});
