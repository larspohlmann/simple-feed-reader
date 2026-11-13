import { ComponentFixture, TestBed } from '@angular/core/testing';
import { computed, signal } from '@angular/core';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { SocialNetworkName } from '../../core/social-network';
import { SURFACE_DESCRIPTORS } from '../../reader/feed-exclusions';
import { ManageActions } from '../../reader/feeds/manage/manage-actions.service';
import { TagActions } from '../../reader/feeds/manage/tag-actions.service';
import { SubscriptionDto, TagDto } from '../../reader/models';
import { NETWORK_EXCLUSION_STORES } from '../../reader/state/network-exclusions.store';
import { SavedSearchesStore } from '../../reader/state/saved-searches.store';
import { buildSocialSections } from '../../reader/state/social-sections';
import { NO_UNREAD_TOTALS } from '../../reader/state/unread-totals';
import { SubscriptionsStore } from '../../reader/state/subscriptions.store';
import { TagsStore } from '../../reader/state/tags.store';
import { tagsStoreStub } from '../../reader/testing/tags-store.stub';
import { makeSubscription } from '../../reader/testing/subscription.factory';
import { makeTag } from '../../reader/testing/tag.factory';
import { VisibilitySectionComponent } from './visibility-section.component';

const NEWS = makeTag(1, 'News', { includeInForYou: false });
const LOCAL = makeTag(2, 'Local', { parentId: NEWS.id });
const TECH = makeTag(3, 'Tech');

const SKEET = makeSubscription({
  id: 10,
  title: 'Alice on Bluesky',
  social: { network: 'bluesky', kind: 'profile', accountId: null },
});
const HEISE = makeSubscription({ id: 11, title: 'heise online', tags: [LOCAL, TECH] });
const TAZ = makeSubscription({ id: 12, title: 'taz' });

function networkStore(excluded: SocialNetworkName[] = []) {
  return {
    excludedNetworks: signal<ReadonlySet<SocialNetworkName>>(new Set(excluded)),
    load: jest.fn(),
    toggleNetwork: jest.fn(),
  };
}

describe('VisibilitySectionComponent', () => {
  let fixture: ComponentFixture<VisibilitySectionComponent>;
  let element: HTMLElement;
  let manage: Record<string, jest.Mock>;
  let tagActions: { setTagInclusion: jest.Mock };
  let allItemsNetworks: ReturnType<typeof networkStore>;
  let forYouNetworks: ReturnType<typeof networkStore>;
  let trendingNetworks: ReturnType<typeof networkStore>;
  let savedSearches: {
    excludedNetworks: ReturnType<typeof signal<ReadonlySet<SocialNetworkName>>>;
    load: jest.Mock;
    toggleNetwork: jest.Mock;
  };

  function setUp(
    options: {
      excludedFromAllItems?: SocialNetworkName[];
      excludedFromTrending?: SocialNetworkName[];
      subscriptions?: SubscriptionDto[];
      tags?: TagDto[];
    } = {},
  ): void {
    const subscriptions = signal<SubscriptionDto[]>(options.subscriptions ?? [TAZ, SKEET, HEISE]);
    const tags = signal<TagDto[]>(options.tags ?? [NEWS, LOCAL, TECH]);
    manage = {
      setInclusion: jest.fn(),
    };
    tagActions = { setTagInclusion: jest.fn() };
    allItemsNetworks = networkStore(options.excludedFromAllItems);
    forYouNetworks = networkStore();
    trendingNetworks = networkStore(options.excludedFromTrending);
    savedSearches = {
      excludedNetworks: signal<ReadonlySet<SocialNetworkName>>(new Set(['mastodon'])),
      load: jest.fn(),
      toggleNetwork: jest.fn(),
    };
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        { provide: ManageActions, useValue: manage },
        { provide: TagActions, useValue: tagActions },
        {
          provide: NETWORK_EXCLUSION_STORES,
          useValue: {
            allItems: allItemsNetworks,
            forYou: forYouNetworks,
            trending: trendingNetworks,
          },
        },
        { provide: SavedSearchesStore, useValue: savedSearches },
        {
          provide: SubscriptionsStore,
          useValue: {
            subscriptions,
            loading: signal(false),
            load: jest.fn(),
            socialSections: computed(() =>
              buildSocialSections(subscriptions(), [], NO_UNREAD_TOTALS),
            ),
          },
        },
        {
          provide: TagsStore,
          useValue: { ...tagsStoreStub(tags), loading: signal(false), load: jest.fn() },
        },
      ],
    });
    fixture = TestBed.createComponent(VisibilitySectionComponent);
    fixture.detectChanges();
    element = fixture.nativeElement as HTMLElement;
  }

  const rowKeys = (): string[] =>
    [...element.querySelectorAll('app-visibility-row')].map((row) =>
      row.getAttribute('data-test')!.replace(/^row-/, ''),
    );
  const row = (key: string): HTMLElement => {
    const found = element.querySelector<HTMLElement>(`[data-test="row-${key}"]`);
    if (found === null) throw new Error(`No row "${key}"`);
    return found;
  };
  const box = (key: string, column: string): HTMLInputElement =>
    row(key).querySelector<HTMLInputElement>(`input[aria-label$=": ${column}"]`)!;
  const cellOf = (key: string, column: string): HTMLElement =>
    box(key, column).closest<HTMLElement>('.cell')!;
  const expandToggle = (): HTMLButtonElement =>
    element.querySelector<HTMLButtonElement>('[data-test="expand-toggle"] button')!;
  const expandAll = (): void => {
    expandToggle().click();
    fixture.detectChanges();
  };
  const filterBy = (term: string): void => {
    const filter = element.querySelector<HTMLInputElement>('[data-test="feed-filter"]')!;
    filter.value = term;
    filter.dispatchEvent(new Event('input'));
    fixture.detectChanges();
  };

  it('loads every store the matrix reads', () => {
    setUp();

    expect(allItemsNetworks.load).toHaveBeenCalled();
    expect(forYouNetworks.load).toHaveBeenCalled();
    expect(trendingNetworks.load).toHaveBeenCalled();
    expect(savedSearches.load).toHaveBeenCalled();
    expect(TestBed.inject(TagsStore).load).toHaveBeenCalled();
    expect(TestBed.inject(SubscriptionsStore).load).toHaveBeenCalled();
  });

  it('shows tags with their subtags and feeds, then networks with theirs, then the untagged', () => {
    setUp();
    expandAll();

    expect(rowKeys()).toEqual([
      'tag:1',
      'tag:2',
      'tag:2/feed:11',
      'tag:3',
      'tag:3/feed:11',
      'network:bluesky',
      'network:bluesky/kind:profile',
      'network:bluesky/kind:profile/feed:10',
      'untagged',
      'untagged/feed:12',
    ]);
    expect(row('untagged').querySelector('.label')!.textContent!.trim()).toBe('Untagged');
  });

  it('draws one column per inclusion surface, in the switches’ order', () => {
    setUp();
    expandAll();

    const heads = [...element.querySelectorAll('.head-surface')].map((head) =>
      head.textContent!.trim(),
    );
    expect(heads).toEqual(['All items', 'For you', 'Saved searches', 'Trending']);
    expect(heads).toHaveLength(SURFACE_DESCRIPTORS.length);
    expect(row('tag:3/feed:11').querySelectorAll('input')).toHaveLength(SURFACE_DESCRIPTORS.length);
  });

  it('indents each row by its depth', () => {
    setUp();
    expandAll();

    const depths = [
      'tag:1',
      'tag:2',
      'tag:2/feed:11',
      'network:bluesky/kind:profile',
      'network:bluesky/kind:profile/feed:10',
    ].map((key) => row(key).style.getPropertyValue('--visibility-depth'));
    expect(depths).toEqual(['0', '1', '2', '1', '2']);
  });

  it('shows a tag switched off For You unchecked, and dims its subtag naming the parent', () => {
    setUp();
    expandAll();

    expect(box('tag:1', 'For you').checked).toBe(false);
    expect(cellOf('tag:1', 'For you').classList).not.toContain('inherited');
    expect(box('tag:2', 'For you').checked).toBe(true);
    expect(cellOf('tag:2', 'For you').classList).toContain('inherited');
    expect(cellOf('tag:2', 'For you').getAttribute('title')).toBe('Excluded by News');
    expect(cellOf('tag:2', 'All items').classList).not.toContain('inherited');
  });

  it('dims a Bluesky feed under its network while Bluesky is out of All items', () => {
    setUp({ excludedFromAllItems: ['bluesky'] });
    expandAll();

    expect(box('network:bluesky/kind:profile/feed:10', 'All items').checked).toBe(true);
    expect(cellOf('network:bluesky/kind:profile/feed:10', 'All items').classList).toContain(
      'inherited',
    );
    expect(cellOf('network:bluesky/kind:profile/feed:10', 'All items').getAttribute('title')).toBe(
      'Excluded by Bluesky',
    );
    expect(box('network:bluesky', 'All items').checked).toBe(false);
    expect(cellOf('network:bluesky', 'All items').classList).not.toContain('inherited');
  });

  it('lists a feed under each of its tags, every copy writing the same switch', () => {
    setUp();
    expandAll();

    box('tag:2/feed:11', 'For you').click();
    box('tag:3/feed:11', 'For you').click();

    expect(manage['setInclusion']).toHaveBeenNthCalledWith(1, HEISE, 'forYou', false);
    expect(manage['setInclusion']).toHaveBeenNthCalledWith(2, HEISE, 'forYou', false);
  });

  it('writes a network tick through the matching store', () => {
    setUp();

    box('network:bluesky', 'For you').click();
    box('network:bluesky', 'Saved searches').click();

    expect(forYouNetworks.toggleNetwork).toHaveBeenCalledWith('bluesky');
    expect(savedSearches.toggleNetwork).toHaveBeenCalledWith('bluesky');
    expect(allItemsNetworks.toggleNetwork).not.toHaveBeenCalled();
  });

  it('writes a Trending tick through the Trending switches of the network, tag and feed', () => {
    setUp();
    expandAll();

    box('network:bluesky', 'Trending').click();
    box('tag:3', 'Trending').click();
    box('tag:3/feed:11', 'Trending').click();

    expect(trendingNetworks.toggleNetwork).toHaveBeenCalledWith('bluesky');
    expect(tagActions.setTagInclusion).toHaveBeenCalledWith(TECH, 'trending', false);
    expect(manage['setInclusion']).toHaveBeenCalledWith(HEISE, 'trending', false);
  });

  it('dims a Bluesky feed in the Trending column while Bluesky is out of Trending', () => {
    setUp({ excludedFromTrending: ['bluesky'] });
    expandAll();

    expect(box('network:bluesky', 'Trending').checked).toBe(false);
    expect(cellOf('network:bluesky/kind:profile/feed:10', 'Trending').classList).toContain(
      'inherited',
    );
    expect(cellOf('network:bluesky/kind:profile/feed:10', 'All items').classList).not.toContain(
      'inherited',
    );
  });

  it('writes a tag tick through the tag’s own switch', () => {
    setUp();

    box('tag:3', 'Saved searches').click();
    box('tag:1', 'For you').click();

    expect(tagActions.setTagInclusion).toHaveBeenCalledWith(TECH, 'savedSearches', false);
    expect(tagActions.setTagInclusion).toHaveBeenCalledWith(NEWS, 'forYou', true);
  });

  it('keeps a dimmed cell editable, writing the feed’s own flag', () => {
    setUp({ excludedFromAllItems: ['bluesky'] });
    expandAll();

    const dimmed = box('network:bluesky/kind:profile/feed:10', 'All items');
    expect(dimmed.disabled).toBe(false);
    dimmed.click();

    expect(manage['setInclusion']).toHaveBeenCalledWith(SKEET, 'allItems', false);
  });

  it('starts with every parent collapsed, showing only the top-level rows', () => {
    setUp();

    expect(rowKeys()).toEqual(['tag:1', 'tag:3', 'network:bluesky', 'untagged']);
    expect(expandToggle().textContent).toContain('Expand all');
  });

  it('expands every parent, then collapses them all, its label following', () => {
    setUp();

    expandAll();

    expect(rowKeys()).toHaveLength(10);
    expect(expandToggle().textContent).toContain('Collapse all');

    expandAll();

    expect(rowKeys()).toEqual(['tag:1', 'tag:3', 'network:bluesky', 'untagged']);
    expect(expandToggle().textContent).toContain('Expand all');
  });

  it('opens one parent from its chevron, leaving the others closed', () => {
    setUp();
    const expand = row('tag:1').querySelector<HTMLButtonElement>('[data-test="expand"]')!;

    expand.click();
    fixture.detectChanges();

    expect(expand.getAttribute('aria-expanded')).toBe('true');
    expect(rowKeys()).toEqual(['tag:1', 'tag:2', 'tag:3', 'network:bluesky', 'untagged']);
  });

  it('opens a network onto its feed kinds, and a kind onto its feeds', () => {
    setUp();
    const expandRow = (key: string): void => {
      row(key).querySelector<HTMLButtonElement>('[data-test="expand"]')!.click();
      fixture.detectChanges();
    };

    expandRow('network:bluesky');

    expect(rowKeys()).toContain('network:bluesky/kind:profile');
    expect(rowKeys()).not.toContain('network:bluesky/kind:profile/feed:10');
    expect(row('network:bluesky/kind:profile').querySelector('.label')!.textContent!.trim()).toBe(
      'Feeds',
    );
    expect(row('network:bluesky/kind:profile').querySelectorAll('input')).toHaveLength(0);

    expandRow('network:bluesky/kind:profile');

    expect(rowKeys()).toContain('network:bluesky/kind:profile/feed:10');
  });

  it('shows a matching network feed through its collapsed kind', () => {
    setUp();

    filterBy('alice');

    expect(rowKeys()).toEqual([
      'network:bluesky',
      'network:bluesky/kind:profile',
      'network:bluesky/kind:profile/feed:10',
    ]);
  });

  it('collapses a parent’s descendants only', () => {
    setUp();
    expandAll();

    row('tag:1').querySelector<HTMLButtonElement>('[data-test="expand"]')!.click();
    fixture.detectChanges();

    expect(rowKeys()).toEqual([
      'tag:1',
      'tag:3',
      'tag:3/feed:11',
      'network:bluesky',
      'network:bluesky/kind:profile',
      'network:bluesky/kind:profile/feed:10',
      'untagged',
      'untagged/feed:12',
    ]);
  });

  it('gives a feed row no chevron', () => {
    setUp();
    expandAll();

    expect(row('untagged/feed:12').querySelector('[data-test="expand"]')).toBeNull();
  });

  it('shows the matching feeds through collapsed parents, and the reader’s own state once cleared', () => {
    setUp();

    filterBy('HEISE');

    expect(rowKeys()).toEqual(['tag:1', 'tag:2', 'tag:2/feed:11', 'tag:3', 'tag:3/feed:11']);

    filterBy('');

    expect(rowKeys()).toEqual(['tag:1', 'tag:3', 'network:bluesky', 'untagged']);
  });

  it('hides Expand all while a filter is set, and keeps the reader’s own collapse state', () => {
    setUp();
    row('tag:1').querySelector<HTMLButtonElement>('[data-test="expand"]')!.click();
    fixture.detectChanges();

    filterBy('heise');

    expect(element.querySelector('[data-test="expand-toggle"]')).toBeNull();

    filterBy('');

    expect(rowKeys()).toEqual(['tag:1', 'tag:2', 'tag:3', 'network:bluesky', 'untagged']);
    expect(expandToggle().textContent).toContain('Expand all');
  });

  it('offers no Expand all when nothing has rows beneath it', () => {
    setUp({ subscriptions: [], tags: [] });

    expect(element.querySelector('[data-test="expand-toggle"]')).toBeNull();
  });

  it('says so when the filter leaves no feed', () => {
    setUp();

    filterBy('nothing like it');

    expect(rowKeys()).toEqual([]);
    expect(element.querySelector('[data-test="no-feed-matches"]')?.textContent).toContain(
      'nothing like it',
    );
  });
});
