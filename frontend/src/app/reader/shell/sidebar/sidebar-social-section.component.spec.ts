import { signal } from '@angular/core';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { API_BASE_URL } from '../../../core/api';
import { ManageActions } from '../../feeds/manage/manage-actions.service';
import { LayoutService } from '../../layout.service';
import { SubscriptionDto } from '../../models';
import { Selection } from '../../query/query';
import { SocialSection } from '../../state/social-sections';
import { makeSubscription } from '../../testing/subscription.factory';
import { SidebarRowActions } from './sidebar-row-actions.service';
import { SidebarSocialSectionComponent } from './sidebar-social-section.component';

const feed = (
  id: number,
  kind: 'home' | 'list',
  over: Partial<SubscriptionDto> = {},
): SubscriptionDto =>
  makeSubscription({
    id,
    title: 'Feed',
    unreadCount: id,
    social: { network: 'bluesky', accountId: 3, kind },
    ...over,
  });

const ONE_ACCOUNT: SocialSection = {
  network: 'bluesky',
  accounts: [
    {
      accountId: 3,
      handle: '@alice.bsky.social',
      subscriptions: [
        feed(1, 'home', { title: 'Timeline' }),
        feed(2, 'list', { title: 'Writers' }),
      ],
    },
  ],
};

function mount(section: SocialSection): ComponentFixture<SidebarSocialSectionComponent> {
  TestBed.resetTestingModule();
  TestBed.configureTestingModule({
    imports: [SidebarSocialSectionComponent, provideTranslocoTesting()],
    providers: [
      provideRouter([{ path: '**', children: [] }]),
      provideHttpClient(),
      provideHttpClientTesting(),
      { provide: API_BASE_URL, useValue: 'https://api.test' },
      { provide: LayoutService, useValue: { isCoarse: signal(false), isNarrow: signal(false) } },
      { provide: ManageActions, useValue: {} },
      {
        provide: SidebarRowActions,
        useValue: {
          menuFor: signal<string | null>(null),
          toggleMenu: jest.fn(),
          closeMenu: jest.fn(),
          openFeedSheet: jest.fn(),
          exclusionTitle: () => 'Not in All items',
        },
      },
    ],
  });
  const fixture = TestBed.createComponent(SidebarSocialSectionComponent);
  fixture.componentRef.setInput('section', section);
  fixture.componentRef.setInput('selection', { kind: 'all', id: null, unread: true } as Selection);
  fixture.detectChanges();
  return fixture;
}

const element = (fixture: ComponentFixture<SidebarSocialSectionComponent>): HTMLElement =>
  fixture.nativeElement as HTMLElement;

describe('SidebarSocialSectionComponent', () => {
  beforeEach(() => localStorage.clear());
  afterEach(() => localStorage.clear());

  it('titles the section by its network and names an unrenamed Home through i18n', () => {
    const root = element(mount(ONE_ACCOUNT));

    expect(root.querySelector('.section-toggle')?.textContent).toContain('Bluesky');
    const rows = [...root.querySelectorAll('app-sidebar-feed-row')].map(
      (row) => row.textContent ?? '',
    );
    expect(rows[0]).toContain('Home@bsky');
    expect(rows[0]).not.toContain('Timeline');
    expect(rows[1]).toContain('Writers');
    expect(root.querySelector('.account-handle')).toBeNull();
  });

  it('keeps a renamed Home under the name it was given', () => {
    const renamed: SocialSection = {
      network: 'bluesky',
      accounts: [
        {
          accountId: 3,
          handle: null,
          subscriptions: [feed(1, 'home', { title: 'Main', customTitle: 'Main' })],
        },
      ],
    };

    expect(element(mount(renamed)).querySelector('app-sidebar-feed-row')?.textContent).toContain(
      'Main',
    );
  });

  it('heads each account with its handle when the network has several', () => {
    const twoAccounts: SocialSection = {
      network: 'bluesky',
      accounts: [
        {
          accountId: 3,
          handle: '@alice.bsky.social',
          subscriptions: [feed(1, 'home', { title: 'Home' })],
        },
        {
          accountId: 4,
          handle: '@work.bsky.social',
          subscriptions: [
            feed(2, 'home', {
              title: 'Home',
              social: { network: 'bluesky', accountId: 4, kind: 'home' },
            }),
          ],
        },
      ],
    };

    const handles = [...element(mount(twoAccounts)).querySelectorAll('.account-handle')].map(
      (handle) => handle.textContent?.trim(),
    );
    expect(handles).toEqual(['@alice.bsky.social', '@work.bsky.social']);
  });

  it("shows each feed's unread count", () => {
    const counts = [...element(mount(ONE_ACCOUNT)).querySelectorAll('.count')].map((count) =>
      count.textContent?.trim(),
    );

    expect(counts).toEqual(['1', '2']);
  });

  it('collapses and remembers it for its own network only', () => {
    const fixture = mount(ONE_ACCOUNT);

    (element(fixture).querySelector('.section-toggle') as HTMLButtonElement).click();
    fixture.detectChanges();

    expect(element(fixture).querySelectorAll('app-sidebar-feed-row').length).toBe(0);
    expect(localStorage.getItem('sfr.bluesky.collapsed')).toBe('true');
    expect(localStorage.getItem('sfr.mastodon.collapsed')).toBeNull();
    expect(element(mount(ONE_ACCOUNT)).querySelectorAll('app-sidebar-feed-row').length).toBe(0);
  });

  it('leaves out the heading of an account whose handle is unknown', () => {
    const unknownHandle: SocialSection = {
      network: 'bluesky',
      accounts: [
        { accountId: 3, handle: null, subscriptions: [feed(1, 'home')] },
        { accountId: 4, handle: '@work.bsky.social', subscriptions: [feed(2, 'home')] },
      ],
    };

    const handles = [...element(mount(unknownHandle)).querySelectorAll('.account-handle')].map(
      (handle) => handle.textContent?.trim(),
    );
    expect(handles).toEqual(['@work.bsky.social']);
  });

  it('marks no private feed as excluded, since the section already says what they are', () => {
    const excluded = (id: number) =>
      feed(id, 'list', { includeInAllItems: false, includeInForYou: false });
    const section: SocialSection = {
      network: 'bluesky',
      accounts: [{ accountId: 3, handle: null, subscriptions: [excluded(1)] }],
    };

    expect(element(mount(section)).querySelector('.feed-exclusion-marker')).toBeNull();
  });
});
