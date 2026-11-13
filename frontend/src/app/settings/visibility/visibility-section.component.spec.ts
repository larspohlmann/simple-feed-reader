import { ComponentFixture, TestBed } from '@angular/core/testing';
import { computed, signal } from '@angular/core';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { SocialNetworkName } from '../../core/social-network';
import { ManageActions } from '../../reader/feeds/manage/manage-actions.service';
import { TagActions } from '../../reader/feeds/manage/tag-actions.service';
import { SubscriptionDto, TagDto } from '../../reader/models';
import { AllItemsNetworksStore } from '../../reader/state/all-items-networks.store';
import { ForYouNetworksStore } from '../../reader/state/for-you-networks.store';
import { SavedSearchesStore } from '../../reader/state/saved-searches.store';
import { buildSocialSections } from '../../reader/state/social-sections';
import { SubscriptionsStore } from '../../reader/state/subscriptions.store';
import { TagsStore } from '../../reader/state/tags.store';
import { NO_UNREAD_TOTALS } from '../../reader/state/unread-totals';
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
const HEISE = makeSubscription({ id: 11, title: 'heise online' });
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
  let savedSearches: {
    includedNetworks: ReturnType<typeof signal<ReadonlySet<SocialNetworkName>>>;
    load: jest.Mock;
    toggleNetwork: jest.Mock;
  };

  function setUp(options: { excludedFromAllItems?: SocialNetworkName[] } = {}): void {
    const subscriptions = signal<SubscriptionDto[]>([TAZ, SKEET, HEISE]);
    const tags = signal<TagDto[]>([NEWS, LOCAL, TECH]);
    manage = {
      setIncludeInAllItems: jest.fn(),
      setIncludeInForYou: jest.fn(),
      setIncludeInSavedSearches: jest.fn(),
    };
    tagActions = { setTagInclusion: jest.fn() };
    allItemsNetworks = networkStore(options.excludedFromAllItems);
    forYouNetworks = networkStore();
    savedSearches = {
      includedNetworks: signal<ReadonlySet<SocialNetworkName>>(new Set(['bluesky'])),
      load: jest.fn(),
      toggleNetwork: jest.fn(),
    };
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        { provide: ManageActions, useValue: manage },
        { provide: TagActions, useValue: tagActions },
        { provide: AllItemsNetworksStore, useValue: allItemsNetworks },
        { provide: ForYouNetworksStore, useValue: forYouNetworks },
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
        { provide: TagsStore, useValue: { tags, loading: signal(false), load: jest.fn() } },
      ],
    });
    fixture = TestBed.createComponent(VisibilitySectionComponent);
    fixture.detectChanges();
    element = fixture.nativeElement as HTMLElement;
  }

  const box = (label: string): HTMLInputElement => {
    const found = element.querySelector<HTMLInputElement>(`input[aria-label="${label}"]`);
    if (found === null) throw new Error(`No box labelled "${label}"`);
    return found;
  };
  const cellOf = (label: string): HTMLElement => box(label).closest<HTMLElement>('.cell')!;
  const rowNames = (group: string): string[] =>
    [...element.querySelectorAll(`[data-test="${group}"] app-visibility-row .label`)].map((label) =>
      label.textContent!.trim(),
    );

  it('loads every store the matrix reads', () => {
    setUp();

    expect(allItemsNetworks.load).toHaveBeenCalled();
    expect(forYouNetworks.load).toHaveBeenCalled();
    expect(savedSearches.load).toHaveBeenCalled();
    expect(TestBed.inject(TagsStore).load).toHaveBeenCalled();
    expect(TestBed.inject(SubscriptionsStore).load).toHaveBeenCalled();
  });

  it('renders networks, tags and feeds, in that order', () => {
    setUp();

    const heads = [...element.querySelectorAll('.group-head')].map((head) =>
      head.textContent!.trim(),
    );
    expect(heads).toEqual(['Networks', 'Tags', 'Feeds']);
    expect(rowNames('networks')).toEqual(['Bluesky']);
    expect(rowNames('tags')).toEqual(['News', 'Local', 'Tech']);
    expect(rowNames('feeds')).toEqual(['Alice on Bluesky', 'heise online', 'taz']);
  });

  it('indents a subtag beneath its parent', () => {
    setUp();

    const rows = [...element.querySelectorAll('[data-test="tags"] app-visibility-row')];
    expect(rows.map((row) => row.classList.contains('subtag'))).toEqual([false, true, false]);
  });

  it('shows a tag switched off For You unchecked, and dims its subtag naming the parent', () => {
    setUp();

    expect(box('News: For you').checked).toBe(false);
    expect(cellOf('News: For you').classList).not.toContain('inherited');
    expect(box('Local: For you').checked).toBe(true);
    expect(cellOf('Local: For you').classList).toContain('inherited');
    expect(cellOf('Local: For you').getAttribute('title')).toBe('Excluded by News');
    expect(cellOf('Local: All items').classList).not.toContain('inherited');
  });

  it('shows a Bluesky feed checked but dimmed while Bluesky is out of All items', () => {
    setUp({ excludedFromAllItems: ['bluesky'] });

    expect(box('Alice on Bluesky: All items').checked).toBe(true);
    expect(cellOf('Alice on Bluesky: All items').classList).toContain('inherited');
    expect(cellOf('Alice on Bluesky: All items').getAttribute('title')).toBe('Excluded by Bluesky');
    expect(box('Bluesky: All items').checked).toBe(false);
    expect(cellOf('Bluesky: All items').classList).not.toContain('inherited');
  });

  it('writes a feed tick through the feed’s own switch', () => {
    setUp();

    box('heise online: For you').click();

    expect(manage['setIncludeInForYou']).toHaveBeenCalledWith(HEISE, false);
  });

  it('writes a network tick through the matching store', () => {
    setUp();

    box('Bluesky: For you').click();
    box('Bluesky: Saved searches').click();

    expect(forYouNetworks.toggleNetwork).toHaveBeenCalledWith('bluesky');
    expect(savedSearches.toggleNetwork).toHaveBeenCalledWith('bluesky');
    expect(allItemsNetworks.toggleNetwork).not.toHaveBeenCalled();
  });

  it('writes a tag tick through the tag’s own switch', () => {
    setUp();

    box('Tech: Saved searches').click();
    box('News: For you').click();

    expect(tagActions.setTagInclusion).toHaveBeenCalledWith(TECH, 'savedSearches', false);
    expect(tagActions.setTagInclusion).toHaveBeenCalledWith(NEWS, 'forYou', true);
  });

  it('keeps a dimmed cell editable, writing the feed’s own flag', () => {
    setUp({ excludedFromAllItems: ['bluesky'] });

    const dimmed = box('Alice on Bluesky: All items');
    expect(dimmed.disabled).toBe(false);
    dimmed.click();

    expect(manage['setIncludeInAllItems']).toHaveBeenCalledWith(SKEET, false);
  });

  it('narrows the feeds by title, leaving networks and tags alone', () => {
    setUp();
    const filter = element.querySelector<HTMLInputElement>('[data-test="feed-filter"]')!;

    filter.value = 'HEISE';
    filter.dispatchEvent(new Event('input'));
    fixture.detectChanges();

    expect(rowNames('feeds')).toEqual(['heise online']);
    expect(rowNames('networks')).toEqual(['Bluesky']);
    expect(rowNames('tags')).toEqual(['News', 'Local', 'Tech']);
  });

  it('says so when the filter leaves no feed', () => {
    setUp();
    const filter = element.querySelector<HTMLInputElement>('[data-test="feed-filter"]')!;

    filter.value = 'nothing like it';
    filter.dispatchEvent(new Event('input'));
    fixture.detectChanges();

    expect(rowNames('feeds')).toEqual([]);
    expect(element.querySelector('[data-test="no-feed-matches"]')?.textContent).toContain(
      'nothing like it',
    );
  });
});
