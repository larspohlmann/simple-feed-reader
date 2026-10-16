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
import { SocialSection, socialProfiles } from '../../state/social-sections';
import { makeSubscription } from '../../testing/subscription.factory';
import { SidebarRowActions } from './sidebar-row-actions.service';
import { SidebarSocialSectionComponent } from './sidebar-social-section.component';

const person = (id: number, title: string): SubscriptionDto =>
  makeSubscription({ id, title, social: { network: 'bluesky', accountId: null, kind: 'profile' } });

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
  profiles: socialProfiles([]),
  unreadCount: 3,
  entryCount: 0,
  showsHandles: false,
  accounts: [
    {
      accountId: 3,
      handle: '@alice.bsky.social',
      subscriptions: [
        feed(1, 'home', { title: 'Home@bsky' }),
        feed(2, 'list', { title: 'Writers' }),
      ],
    },
  ],
};

const ALL: Selection = { kind: 'all', id: null, unread: true };

function mount(
  section: SocialSection,
  selection: Selection = ALL,
): ComponentFixture<SidebarSocialSectionComponent> {
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
  fixture.componentRef.setInput('selection', selection);
  fixture.detectChanges();
  return fixture;
}

const element = (fixture: ComponentFixture<SidebarSocialSectionComponent>): HTMLElement =>
  fixture.nativeElement as HTMLElement;

describe('SidebarSocialSectionComponent', () => {
  beforeEach(() => localStorage.clear());
  afterEach(() => localStorage.clear());

  it('titles the section by its network and lists each feed by its display title', () => {
    const root = element(mount(ONE_ACCOUNT));

    expect(root.querySelector('.section-link')?.textContent).toContain('Bluesky');
    const rows = [...root.querySelectorAll('app-sidebar-feed-row')].map(
      (row) => row.textContent ?? '',
    );
    expect(rows[0]).toContain('Home@bsky');
    expect(rows[1]).toContain('Writers');
    expect(root.querySelector('.account-handle')).toBeNull();
  });

  it('keeps a renamed Home under the name it was given', () => {
    const renamed: SocialSection = {
      network: 'bluesky',
      unreadCount: 0,
      entryCount: 0,
      showsHandles: false,
      profiles: socialProfiles([]),
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
      unreadCount: 0,
      entryCount: 0,
      showsHandles: true,
      profiles: socialProfiles([]),
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

    (element(fixture).querySelector('.section-chevron') as HTMLButtonElement).click();
    fixture.detectChanges();

    expect(element(fixture).querySelectorAll('app-sidebar-feed-row').length).toBe(0);
    expect(localStorage.getItem('sfr.bluesky.collapsed')).toBe('true');
    expect(localStorage.getItem('sfr.mastodon.collapsed')).toBeNull();
    expect(element(mount(ONE_ACCOUNT)).querySelectorAll('app-sidebar-feed-row').length).toBe(0);
  });

  it('leaves out the heading of an account whose handle is unknown', () => {
    const unknownHandle: SocialSection = {
      network: 'bluesky',
      unreadCount: 0,
      entryCount: 0,
      showsHandles: true,
      profiles: socialProfiles([]),
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
      unreadCount: 0,
      entryCount: 0,
      showsHandles: false,
      profiles: socialProfiles([]),
      accounts: [{ accountId: 3, handle: null, subscriptions: [excluded(1)] }],
    };

    expect(element(mount(section)).querySelector('.feed-exclusion-marker')).toBeNull();
  });

  it('lists profile feeds under a People heading after the private feeds', () => {
    const section: SocialSection = {
      network: 'bluesky',
      unreadCount: 0,
      entryCount: 0,
      showsHandles: false,
      accounts: ONE_ACCOUNT.accounts,
      profiles: socialProfiles([person(7, 'Amy'), person(8, 'Zed')]),
    };

    const root = element(mount(section));

    expect(root.querySelector('.people-heading')?.textContent).toContain('People');
    const names = [...root.querySelectorAll('app-sidebar-feed-row .nav > span:not(.count)')].map(
      (name) => name.textContent,
    );
    expect(names).toEqual(['Home@bsky', 'Writers', 'Amy', 'Zed']);
  });

  it('shows no People heading without profile feeds', () => {
    expect(element(mount(ONE_ACCOUNT)).querySelector('.people-heading')).toBeNull();
  });

  it('shows a network that has only people, without an account heading', () => {
    const section: SocialSection = {
      network: 'mastodon',
      unreadCount: 0,
      entryCount: 0,
      showsHandles: false,
      accounts: [],
      profiles: socialProfiles([person(7, 'Amy')]),
    };

    const root = element(mount(section));

    expect(root.querySelector('.section-link')?.textContent).toContain('Mastodon');
    expect(root.querySelector('.account-handle')).toBeNull();
    expect(root.querySelectorAll('app-sidebar-feed-row').length).toBe(1);
  });

  it('keeps the exclusion marker on an excluded profile feed, which is ordinary RSS', () => {
    const excluded = { ...person(7, 'Amy'), includeInAllItems: false };
    const section: SocialSection = {
      network: 'bluesky',
      accounts: [],
      profiles: socialProfiles([excluded]),
      unreadCount: 0,
      entryCount: 0,
      showsHandles: false,
    };

    expect(element(mount(section)).querySelector('.feed-exclusion-marker')).not.toBeNull();
  });

  it("links the title to the network's combined view", () => {
    const link = element(mount(ONE_ACCOUNT)).querySelector('a.section-link') as HTMLAnchorElement;

    expect(link.getAttribute('href')).toContain('network=bluesky');
  });

  it('opens the view from the title without collapsing the section', () => {
    const fixture = mount(ONE_ACCOUNT);

    (element(fixture).querySelector('a.section-link') as HTMLAnchorElement).click();
    fixture.detectChanges();

    expect(element(fixture).querySelectorAll('app-sidebar-feed-row').length).toBe(2);
    expect(localStorage.getItem('sfr.bluesky.collapsed')).toBeNull();
  });

  it("marks the title active while the network's view is open", () => {
    const bluesky: Selection = { kind: 'network', id: null, unread: true, network: 'bluesky' };
    const mastodon: Selection = { ...bluesky, network: 'mastodon' };

    expect(
      element(mount(ONE_ACCOUNT, bluesky)).querySelector('.section-link.active'),
    ).not.toBeNull();
    expect(element(mount(ONE_ACCOUNT, mastodon)).querySelector('.section-link.active')).toBeNull();
  });

  it("shows the network's unread count between the title and the chevron", () => {
    const head = element(mount(ONE_ACCOUNT)).querySelector('.section-head') as HTMLElement;

    expect(head.querySelector('.section-link .section-count')?.textContent?.trim()).toBe('3');
    expect(head.lastElementChild?.classList).toContain('section-chevron');
  });

  it('shows no count for a network with nothing unread', () => {
    const quiet: SocialSection = { ...ONE_ACCOUNT, unreadCount: 0 };

    expect(element(mount(quiet)).querySelector('.section-count')).toBeNull();
  });

  describe('row avatars', () => {
    const avatarOf = (subscription: SubscriptionDto): string | null => {
      const section: SocialSection = {
        network: 'bluesky',
        accounts: [],
        profiles: socialProfiles([subscription]),
        unreadCount: 0,
        entryCount: 0,
        showsHandles: false,
      };
      const row = element(mount(section)).querySelector('app-sidebar-feed-row') as HTMLElement;
      return row.querySelector('img.avatar')?.getAttribute('src') ?? null;
    };

    it("shows a profile feed's image as a round avatar", () => {
      const amy = { ...person(7, 'Amy'), imageUrl: 'https://cdn.example/amy.jpg' };

      expect(avatarOf(amy)).toBe('https://cdn.example/amy.jpg');
    });

    it("shows a list's image as its avatar", () => {
      expect(avatarOf(feed(2, 'list', { imageUrl: 'https://cdn.example/list.jpg' }))).toBe(
        'https://cdn.example/list.jpg',
      );
    });

    it('keeps the favicon for a Home timeline', () => {
      expect(avatarOf(feed(1, 'home', { imageUrl: 'https://cdn.example/me.jpg' }))).toBeNull();
    });

    it("never shows an ordinary feed's channel logo as an avatar", () => {
      expect(
        avatarOf(makeSubscription({ id: 9, imageUrl: 'https://example.com/logo.png' })),
      ).toBeNull();
    });

    it('falls back to the favicon without an image', () => {
      const amy = { ...person(7, 'Amy'), faviconUrl: 'https://bsky.app/favicon.ico' };
      const section: SocialSection = {
        network: 'bluesky',
        accounts: [],
        profiles: socialProfiles([amy]),
        unreadCount: 0,
        entryCount: 0,
        showsHandles: false,
      };
      const row = element(mount(section)).querySelector('app-sidebar-feed-row') as HTMLElement;

      expect(row.querySelector('img.avatar')).toBeNull();
      expect(row.querySelector('img.favicon')?.getAttribute('src')).toBe(
        'https://bsky.app/favicon.ico',
      );
    });
  });
});
