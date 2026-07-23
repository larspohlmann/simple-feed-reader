import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../core/api';
import { FeedsSectionComponent } from './feeds-section.component';
import { SubscriptionsStore } from '../reader/subscriptions.store';
import { ManageActions } from '../reader/manage/manage-actions.service';
import { SubscriptionDto } from '../reader/models';

const sub = (id: number, over: Partial<SubscriptionDto> = {}): SubscriptionDto => ({
  id,
  title: `Feed ${id}`,
  customTitle: null,
  feedUrl: 'https://x/rss',
  siteUrl: 'https://x',
  status: 'active',
  sourceFormat: 'xml',
  createdAt: 'x',
  position: 0,
  tags: [],
  unreadCount: 0,
  ...over,
});

describe('FeedsSectionComponent', () => {
  const edit = jest.fn();
  const unsubscribe = jest.fn();

  function mount(subs: SubscriptionDto[]) {
    const store = { subscriptions: () => subs, loading: () => false, error: () => null };
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: SubscriptionsStore, useValue: store },
        { provide: ManageActions, useValue: { editSubscription: edit, unsubscribe } },
      ],
    });
    const f = TestBed.createComponent(FeedsSectionComponent);
    f.detectChanges();
    return f;
  }

  beforeEach(() => {
    edit.mockReset();
    unsubscribe.mockReset();
  });

  it('lists feeds sorted by title with a status badge', () => {
    const el: HTMLElement = mount([
      sub(2, { title: 'Zed' }),
      sub(1, { title: 'Alpha', status: 'gone' }),
    ]).nativeElement;
    const rows = el.querySelectorAll('.feed');
    expect(rows.length).toBe(2);
    expect(rows[0].textContent).toContain('Alpha');
    expect(el.textContent).toContain('gone');
  });

  it('shows an empty state when there are no feeds', () => {
    const el: HTMLElement = mount([]).nativeElement;
    expect(el.textContent).toContain('No feeds yet');
  });

  it('invokes ManageActions on edit and unsubscribe', () => {
    const f = mount([sub(1)]);
    const buttons = (f.nativeElement as HTMLElement).querySelectorAll('.feed button');
    (buttons[0] as HTMLButtonElement).click();
    (buttons[1] as HTMLButtonElement).click();
    expect(edit).toHaveBeenCalled();
    expect(unsubscribe).toHaveBeenCalled();
  });
});
