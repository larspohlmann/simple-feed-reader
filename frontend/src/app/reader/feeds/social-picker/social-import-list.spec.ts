import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { DIALOG_DATA } from '@angular/cdk/dialog';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { SocialNetworkName } from '../../../core/social-network';
import { ConfirmData } from '../../../shared/confirm-dialog/confirm-dialog.component';
import { ConfirmService } from '../../../shared/confirm-dialog/confirm.service';
import {
  CAROL,
  DAVE,
  DISCOVER,
  HOME,
  candidates,
  collection,
  follow,
  manyFollows,
} from '../../testing/import-candidates.factory';
import { SocialImportList } from './social-import-list';
import { ImportRow, followKey } from './social-import-rows';
import {
  ImportCandidates,
  SocialImportSelection,
  SocialImportService,
} from './social-import.service';

describe('SocialImportList', () => {
  const confirmStub = {
    confirmThen: jest.fn((_data: ConfirmData, action: () => void) => action()),
  };

  function listOf(body: ImportCandidates | null, network: SocialNetworkName = 'bluesky') {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        SocialImportList,
        { provide: DIALOG_DATA, useValue: { accountId: 4, network, handle: '@alice.bsky.social' } },
        { provide: SocialImportService, useValue: { candidates: signal(body) } },
        { provide: ConfirmService, useValue: confirmStub },
      ],
    });
    return TestBed.inject(SocialImportList);
  }

  function rowOf(list: SocialImportList, key: string): ImportRow {
    const row = list.visibleRows().find((candidate) => candidate.key === key);
    if (!row) throw new Error(`no row ${key}`);
    return row;
  }

  function applied(list: SocialImportList): SocialImportSelection | null {
    let sent: SocialImportSelection | null = null;
    list.apply((selection) => (sent = selection));
    return sent;
  }

  beforeEach(() => confirmStub.confirmThen.mockClear());

  it('shows nothing of the list until the candidates arrive, nor on the Search tab', () => {
    expect(listOf(null).visible()).toBe(false);
    expect(listOf(null).tabCounts()).toEqual({});

    const list = listOf(candidates());
    expect(list.visible()).toBe(true);
    list.tab.set('search');
    expect(list.visible()).toBe(false);
    expect(list.listTab()).toBeNull();
  });

  it('offers followed tags instead of custom feeds on Mastodon', () => {
    expect(listOf(null, 'mastodon').tabs).toEqual(['accounts', 'lists', 'tags', 'search']);
    expect(listOf(null).tabs).toEqual(['accounts', 'lists', 'customFeeds', 'search']);
  });

  it('counts each tab by the filter, on the Search tab too', () => {
    const list = listOf(candidates({ follows: [follow(), CAROL], lists: [collection()] }));
    list.filter.set('carol');
    list.tab.set('search');

    expect(list.tabCounts()).toEqual({ accounts: 1, lists: 0, customFeeds: 0, tags: 0 });
  });

  it('filters by handle or display name, and collections by the translated Home name', () => {
    const list = listOf(candidates({ follows: [follow(), CAROL], lists: [HOME, collection()] }));
    list.filter.set('caro');
    expect(list.visibleRows().map((row) => row.key)).toEqual([followKey(CAROL)]);

    list.tab.set('lists');
    list.filter.set('timeline');
    expect(list.visibleRows().map((row) => row.key)).toEqual(['home:']);
  });

  it('falls back to All on a tab that cannot show the chosen state', () => {
    const list = listOf(candidates({ follows: [DAVE], lists: [HOME] }));
    list.stateFilter.set('no_longer_followed');
    expect(list.activeFilter()).toBe('no_longer_followed');

    list.tab.set('lists');

    expect(list.activeFilter()).toBe('all');
  });

  it('pages the rows a hundred at a time, and starts over on another state', () => {
    const list = listOf(candidates({ follows: manyFollows(250) }));
    expect(list.renderedRows()).toHaveLength(100);
    list.showMore();
    list.showMore();
    expect(list.renderedRows()).toHaveLength(250);
    expect(list.nextPageSize()).toBeLessThanOrEqual(0);

    list.stateFilter.set('new');

    expect(list.renderedRows()).toHaveLength(100);
  });

  it('selects the new rows of the open tab only, rendered or not', () => {
    const list = listOf(candidates({ follows: manyFollows(150), lists: [HOME] }));

    list.selectAllNew();

    expect(list.chosen().size).toBe(150);
    expect(list.chosen().has('home:')).toBe(false);
  });

  it('selects only the visible new follows', () => {
    const erin = follow({
      identity: 'did:plc:erin',
      handle: '@erin.bsky.social',
      displayName: 'Erin',
      feedUrl: 'https://bsky.app/profile/did:plc:erin/rss',
    });
    const list = listOf(candidates({ follows: [follow(), erin, CAROL] }));
    list.filter.set('bob');

    list.selectAllNew();
    list.filter.set('');

    expect(applied(list)).toEqual({
      subscribe: [{ identity: 'did:plc:bob', title: 'Bob' }],
      unsubscribe: [],
      collections: [],
    });
  });

  it('sends the ticked follows, collections and tags without asking when nothing goes', () => {
    const caturday = collection({ kind: 'tag', id: 'caturday', name: '#Caturday' });
    const list = listOf(
      candidates({ follows: [follow()], lists: [HOME], customFeeds: [DISCOVER], tags: [caturday] }),
    );
    list.toggle(rowOf(list, followKey(follow())));
    list.tab.set('lists');
    list.toggle(rowOf(list, 'home:'));
    list.tab.set('customFeeds');
    list.toggle(rowOf(list, 'custom_feed:at://feed/a'));

    expect(applied(list)).toEqual({
      subscribe: [{ identity: 'did:plc:bob', title: 'Bob' }],
      unsubscribe: [],
      collections: [
        { kind: 'home', id: null, name: 'Home' },
        { kind: 'custom_feed', id: 'at://feed/a', name: 'Discover' },
      ],
    });
    expect(confirmStub.confirmThen).not.toHaveBeenCalled();
  });

  it('unsubscribes an unticked subscribed list, after asking by name and naming the saved feed', () => {
    const list = listOf(
      candidates({ lists: [collection({ state: 'subscribed', subscriptionId: 12 })] }),
    );
    list.tab.set('lists');
    list.toggle(rowOf(list, 'list:at://list/a'));

    expect(applied(list)).toEqual({ subscribe: [], unsubscribe: [12], collections: [] });
    const [data] = confirmStub.confirmThen.mock.calls[0];
    expect(data.message).toContain('News');
    expect(data.message).toContain('Favourites and kept posts move to Saved from Bluesky.');
  });

  it('unsubscribes an unticked follow and Home after asking', () => {
    const home = collection({
      kind: 'home',
      id: null,
      name: 'Home',
      state: 'subscribed',
      subscriptionId: 11,
    });
    const list = listOf(candidates({ follows: [CAROL], lists: [home] }));
    list.toggle(rowOf(list, followKey(CAROL)));

    expect(applied(list)).toEqual({ subscribe: [], unsubscribe: [9], collections: [] });
    expect(confirmStub.confirmThen.mock.calls[0][0].message).toContain('Carol');

    list.toggle(rowOf(list, followKey(CAROL)));
    list.tab.set('lists');
    list.toggle(rowOf(list, 'home:'));

    expect(applied(list)).toEqual({ subscribe: [], unsubscribe: [11], collections: [] });
    expect(confirmStub.confirmThen.mock.calls[1][0].message).toContain(
      'Remove Home timeline and its entries',
    );
  });

  it('asks by count when several feeds go', () => {
    const list = listOf(
      candidates({
        follows: [CAROL, DAVE],
        lists: [collection({ state: 'subscribed', subscriptionId: 12 })],
      }),
    );
    list.toggle(rowOf(list, followKey(CAROL)));
    list.toggle(rowOf(list, followKey(DAVE)));
    list.tab.set('lists');
    list.toggle(rowOf(list, 'list:at://list/a'));

    expect(applied(list)).toEqual({ subscribe: [], unsubscribe: [9, 10, 12], collections: [] });
    expect(confirmStub.confirmThen.mock.calls[0][0].message).toContain(
      'Remove these 3 feeds and their entries from your feeds?',
    );
  });

  it('sends nothing when the removal is not confirmed', () => {
    confirmStub.confirmThen.mockImplementationOnce(() => undefined);
    const list = listOf(candidates({ follows: [CAROL] }));
    list.toggle(rowOf(list, followKey(CAROL)));

    expect(applied(list)).toBeNull();
  });
});
