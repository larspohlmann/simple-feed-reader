import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { API_BASE_URL } from '../../core/api';
import { RefreshService } from '../../reader/state/refresh.service';
import { SubscriptionsStore } from '../../reader/state/subscriptions.store';
import { TagsStore } from '../../reader/state/tags.store';
import { CONFIRMATION_DURATION_MS, ToastService } from '../../shared/toast/toast.service';
import { SocialNetworkName } from '../../core/social-network';
import { SocialImportDialogComponent } from './social-import-dialog.component';
import { CollectionCandidate, FollowCandidate, ImportCandidates } from './social-import.service';

const BASE = 'https://api.test';
const CANDIDATES = `${BASE}/api/me/social-accounts/4/import-candidates`;
const IMPORT = `${BASE}/api/me/social-accounts/4/import`;

function follow(overrides: Partial<FollowCandidate> = {}): FollowCandidate {
  return {
    identity: 'did:plc:bob',
    handle: '@bob.bsky.social',
    displayName: 'Bob',
    feedUrl: 'https://bsky.app/profile/did:plc:bob/rss',
    state: 'new',
    subscriptionId: null,
    ...overrides,
  };
}

const CAROL = follow({
  identity: 'did:plc:carol',
  handle: '@carol.bsky.social',
  displayName: 'Carol',
  feedUrl: 'https://bsky.app/profile/did:plc:carol/rss',
  state: 'subscribed',
  subscriptionId: 9,
});
const DAVE = follow({
  identity: 'did:plc:dave',
  handle: 'did:plc:dave',
  displayName: 'Dave’s posts',
  feedUrl: 'https://bsky.app/profile/did:plc:dave/rss',
  state: 'no_longer_followed',
  subscriptionId: 10,
});

function collection(overrides: Partial<CollectionCandidate> = {}): CollectionCandidate {
  return {
    kind: 'list',
    id: 'at://list/a',
    name: 'News',
    state: 'new',
    subscriptionId: null,
    ...overrides,
  };
}

const HOME = collection({ kind: 'home', id: null, name: 'Home' });
const DISCOVER = collection({ kind: 'custom_feed', id: 'at://feed/a', name: 'Discover' });

function candidates(overrides: Partial<ImportCandidates> = {}): ImportCandidates {
  return { follows: [], lists: [], customFeeds: [], truncated: false, ...overrides };
}

describe('SocialImportDialogComponent', () => {
  let http: HttpTestingController;
  const close = jest.fn();
  const subscriptionsStub = { load: jest.fn() };
  const tagsStub = { load: jest.fn() };
  const refreshStub = { run: jest.fn() };
  const toastStub = { show: jest.fn() };

  function mount(
    network: SocialNetworkName = 'bluesky',
  ): ComponentFixture<SocialImportDialogComponent> {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [SocialImportDialogComponent, provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: BASE },
        { provide: DIALOG_DATA, useValue: { accountId: 4, network, handle: '@alice.bsky.social' } },
        { provide: DialogRef, useValue: { close } },
        { provide: SubscriptionsStore, useValue: subscriptionsStub },
        { provide: TagsStore, useValue: tagsStub },
        { provide: RefreshService, useValue: refreshStub },
        { provide: ToastService, useValue: toastStub },
      ],
    });
    http = TestBed.inject(HttpTestingController);
    const fixture = TestBed.createComponent(SocialImportDialogComponent);
    fixture.detectChanges();
    return fixture;
  }

  function loaded(
    body: ImportCandidates,
    network: SocialNetworkName = 'bluesky',
  ): ComponentFixture<SocialImportDialogComponent> {
    const fixture = mount(network);
    http.expectOne(CANDIDATES).flush(body);
    fixture.detectChanges();
    return fixture;
  }

  const byTestId = <T extends HTMLElement>(
    fixture: ComponentFixture<SocialImportDialogComponent>,
    testId: string,
  ): T | null =>
    (fixture.nativeElement as HTMLElement).querySelector<T>(`[data-testid="${testId}"]`);

  const checkboxOf = (
    fixture: ComponentFixture<SocialImportDialogComponent>,
    identity: string,
  ): HTMLInputElement =>
    byTestId(fixture, `social-import-follow-${identity}`)?.querySelector(
      'input[type="checkbox"]',
    ) as HTMLInputElement;

  const collectionBox = (
    fixture: ComponentFixture<SocialImportDialogComponent>,
    key: string,
  ): HTMLInputElement =>
    byTestId(fixture, `social-import-collection-${key}`)?.querySelector(
      'input[type="checkbox"]',
    ) as HTMLInputElement;

  function tick(fixture: ComponentFixture<SocialImportDialogComponent>, identity: string): void {
    checkboxOf(fixture, identity).click();
    fixture.detectChanges();
  }

  function press(fixture: ComponentFixture<SocialImportDialogComponent>, testId: string): void {
    byTestId(fixture, testId)?.querySelector('button')?.click();
    fixture.detectChanges();
  }

  function filterBy(fixture: ComponentFixture<SocialImportDialogComponent>, text: string): void {
    const input = byTestId<HTMLInputElement>(fixture, 'social-import-filter') as HTMLInputElement;
    input.value = text;
    input.dispatchEvent(new Event('input'));
    fixture.detectChanges();
  }

  beforeEach(() => {
    close.mockReset();
    subscriptionsStub.load.mockReset();
    tagsStub.load.mockReset();
    refreshStub.run.mockReset();
    toastStub.show.mockReset();
  });

  afterEach(() => http.verify());

  it('shows a spinner until the candidates arrive', () => {
    const fixture = mount();
    expect(byTestId(fixture, 'social-import-loading')).not.toBeNull();

    http.expectOne(CANDIDATES).flush(candidates());
    fixture.detectChanges();

    expect(byTestId(fixture, 'social-import-loading')).toBeNull();
  });

  it('labels each group with its count', () => {
    const fixture = loaded(candidates({ follows: [follow(), CAROL], lists: [collection()] }));

    expect(byTestId(fixture, 'social-import-follows')?.textContent).toContain(
      'Accounts you follow (2)',
    );
    expect(byTestId(fixture, 'social-import-lists')?.textContent).toContain('List (1)');
    expect(byTestId(fixture, 'social-import-feeds')?.textContent).toContain('Custom feeds (0)');
  });

  it('has no custom feeds group on Mastodon', () => {
    const fixture = loaded(candidates(), 'mastodon');

    expect(byTestId(fixture, 'social-import-feeds')).toBeNull();
  });

  it('marks each follow with its state and shows subscribed ones checked and fixed', () => {
    const fixture = loaded(candidates({ follows: [follow(), CAROL, DAVE] }));

    expect(checkboxOf(fixture, 'did:plc:bob').checked).toBe(false);
    expect(checkboxOf(fixture, 'did:plc:bob').disabled).toBe(false);
    expect(checkboxOf(fixture, 'did:plc:carol').checked).toBe(true);
    expect(checkboxOf(fixture, 'did:plc:carol').disabled).toBe(true);
    expect(checkboxOf(fixture, 'did:plc:dave').checked).toBe(false);
    const text = byTestId(fixture, 'social-import-follows')?.textContent ?? '';
    expect(text).toContain('New');
    expect(text).toContain('Subscribed');
    expect(text).toContain('No longer followed');
  });

  it('offers lists and custom feeds as checkboxes, the added ones checked and fixed', () => {
    const fixture = loaded(
      candidates({
        lists: [HOME, collection({ state: 'subscribed', subscriptionId: 12 })],
        customFeeds: [DISCOVER],
      }),
    );

    const lists = byTestId(fixture, 'social-import-lists') as HTMLElement;
    expect(lists.textContent).toContain('Home timeline');
    expect(lists.textContent).not.toContain('arrives with private timeline feeds');
    expect(collectionBox(fixture, 'home:').checked).toBe(false);
    expect(collectionBox(fixture, 'list:at://list/a').checked).toBe(true);
    expect(collectionBox(fixture, 'list:at://list/a').disabled).toBe(true);
    expect(collectionBox(fixture, 'list:at://list/a').getAttribute('aria-label')).toBe(
      'Already added: News',
    );
    expect(collectionBox(fixture, 'custom_feed:at://feed/a').getAttribute('aria-label')).toBe(
      'Add Discover',
    );
  });

  it('selects follows only with Select all new, and stays off when only collections are new', () => {
    const withFollow = loaded(candidates({ follows: [follow()], lists: [HOME] }));
    press(withFollow, 'social-import-select-new');
    expect(checkboxOf(withFollow, 'did:plc:bob').checked).toBe(true);
    expect(collectionBox(withFollow, 'home:').checked).toBe(false);
    expect(
      byTestId(withFollow, 'social-import-select-new')?.querySelector('button')?.disabled,
    ).toBe(false);

    const collectionsOnly = loaded(candidates({ lists: [HOME], customFeeds: [DISCOVER] }));
    expect(
      byTestId(collectionsOnly, 'social-import-select-new')?.querySelector('button')?.disabled,
    ).toBe(true);
  });

  it('does not toggle an already added collection', () => {
    const added = collection({ state: 'subscribed', subscriptionId: 12 });
    const fixture = loaded(candidates({ lists: [added] }));

    (
      fixture.componentInstance as unknown as { toggleCollection(item: CollectionCandidate): void }
    ).toggleCollection(added);
    fixture.detectChanges();

    expect(collectionBox(fixture, 'list:at://list/a').checked).toBe(true);
    expect(byTestId(fixture, 'social-import-apply')?.querySelector('button')?.disabled).toBe(true);
  });

  it('filters collections by the translated Home name', () => {
    const fixture = loaded(candidates({ lists: [HOME, collection()] }));

    filterBy(fixture, 'timeline');

    expect(byTestId(fixture, 'social-import-collection-home:')).not.toBeNull();
    expect(byTestId(fixture, 'social-import-collection-list:at://list/a')).toBeNull();
  });

  it('enables Apply for a selection made only of collections', () => {
    const fixture = loaded(candidates({ lists: [HOME] }));
    const applyButton = (): HTMLButtonElement | null | undefined =>
      byTestId(fixture, 'social-import-apply')?.querySelector('button');
    expect(applyButton()?.disabled).toBe(true);

    collectionBox(fixture, 'home:').click();
    fixture.detectChanges();

    expect(applyButton()?.disabled).toBe(false);
  });

  it('imports the chosen collections and refreshes the reader', () => {
    const fixture = loaded(candidates({ lists: [HOME], customFeeds: [DISCOVER] }));
    collectionBox(fixture, 'home:').click();
    collectionBox(fixture, 'custom_feed:at://feed/a').click();
    fixture.detectChanges();

    press(fixture, 'social-import-apply');
    const post = http.expectOne(IMPORT);
    expect(post.request.body).toEqual({
      subscribe: [],
      unsubscribe: [],
      collections: [
        { kind: 'home', id: null, name: 'Home' },
        { kind: 'custom_feed', id: 'at://feed/a', name: 'Discover' },
      ],
    });
    post.flush({ imported: 2, alreadySubscribed: 0, invalid: 0, skippedOverLimit: 0, removed: 0 });

    expect(subscriptionsStub.load).toHaveBeenCalled();
    expect(refreshStub.run).toHaveBeenCalled();
  });

  it('filters by handle or display name', () => {
    const fixture = loaded(candidates({ follows: [follow(), CAROL] }));

    filterBy(fixture, 'caro');

    expect(byTestId(fixture, 'social-import-follow-did:plc:bob')).toBeNull();
    expect(byTestId(fixture, 'social-import-follow-did:plc:carol')).not.toBeNull();
  });

  it('warns when the follow list was cut at the cap', () => {
    const fixture = loaded(candidates({ truncated: true }));

    expect(byTestId(fixture, 'social-import-truncated')?.textContent).toContain('first 5,000');
  });

  it('keeps Import disabled until something is chosen', () => {
    const fixture = loaded(candidates({ follows: [follow()] }));
    const apply = () =>
      byTestId(fixture, 'social-import-apply')?.querySelector('button') as HTMLButtonElement;

    expect(apply().disabled).toBe(true);
    tick(fixture, 'did:plc:bob');
    expect(apply().disabled).toBe(false);
  });

  it('selects every visible new follow at once', () => {
    const erin = follow({
      identity: 'did:plc:erin',
      handle: '@erin.bsky.social',
      displayName: 'Erin',
      feedUrl: 'https://bsky.app/profile/did:plc:erin/rss',
    });
    const fixture = loaded(candidates({ follows: [follow(), erin, CAROL] }));
    filterBy(fixture, 'bob');

    press(fixture, 'social-import-select-new');
    filterBy(fixture, '');
    press(fixture, 'social-import-apply');

    const post = http.expectOne(IMPORT);
    expect(post.request.body).toEqual({
      subscribe: [{ feedUrl: 'https://bsky.app/profile/did:plc:bob/rss', title: 'Bob' }],
      unsubscribe: [],
      collections: [],
    });
    post.flush({ imported: 1, alreadySubscribed: 0, invalid: 0, skippedOverLimit: 0, removed: 0 });
  });

  it('imports the chosen follows, unsubscribes the chosen leftovers and reloads the reader', () => {
    const nameless = follow({
      identity: 'did:plc:fay',
      handle: '@fay.bsky.social',
      displayName: null,
      feedUrl: 'https://bsky.app/profile/did:plc:fay/rss',
    });
    const fixture = loaded(candidates({ follows: [follow(), nameless, DAVE] }));
    tick(fixture, 'did:plc:bob');
    tick(fixture, 'did:plc:fay');
    tick(fixture, 'did:plc:dave');

    press(fixture, 'social-import-apply');
    const post = http.expectOne(IMPORT);
    expect(post.request.body).toEqual({
      subscribe: [
        { feedUrl: 'https://bsky.app/profile/did:plc:bob/rss', title: 'Bob' },
        { feedUrl: 'https://bsky.app/profile/did:plc:fay/rss', title: null },
      ],
      unsubscribe: [10],
      collections: [],
    });
    const result = {
      imported: 2,
      alreadySubscribed: 0,
      invalid: 0,
      skippedOverLimit: 1,
      removed: 1,
    };
    post.flush(result);

    expect(toastStub.show).toHaveBeenCalledWith({
      message: '2 added, 1 removed, 0 already subscribed. 1 not added: subscription limit reached.',
      durationMs: CONFIRMATION_DURATION_MS,
    });
    expect(subscriptionsStub.load).toHaveBeenCalled();
    expect(tagsStub.load).toHaveBeenCalled();
    expect(refreshStub.run).toHaveBeenCalled();
    expect(close).toHaveBeenCalledWith(result);
  });

  it('skips the refresh when nothing new was added', () => {
    const fixture = loaded(candidates({ follows: [DAVE] }));
    tick(fixture, 'did:plc:dave');

    press(fixture, 'social-import-apply');
    http
      .expectOne(IMPORT)
      .flush({ imported: 0, alreadySubscribed: 0, invalid: 0, skippedOverLimit: 0, removed: 1 });

    expect(refreshStub.run).not.toHaveBeenCalled();
    expect(subscriptionsStub.load).toHaveBeenCalled();
  });

  it('shows the problem and retries the load', () => {
    const fixture = mount();
    http.expectOne(CANDIDATES).flush(
      {
        type: 'social_credentials_rejected',
        title: 'x',
        status: 422,
        detail: 'Bluesky refused this handle and app password.',
      },
      { status: 422, statusText: 'Unprocessable Entity' },
    );
    fixture.detectChanges();

    const banner = byTestId(fixture, 'social-import-failure') as HTMLElement;
    expect(banner.textContent).toContain('Bluesky refused this handle and app password.');
    (banner.querySelector('button.action') as HTMLButtonElement).click();
    fixture.detectChanges();

    http.expectOne(CANDIDATES).flush(candidates());
  });

  it('closes without a result on cancel', () => {
    const fixture = loaded(candidates());

    press(fixture, 'social-import-cancel');

    expect(close).toHaveBeenCalledWith(undefined);
  });

  it('names the handle on the checkbox of every row state', () => {
    const fixture = loaded(candidates({ follows: [follow(), CAROL, DAVE] }));

    expect(checkboxOf(fixture, 'did:plc:bob').getAttribute('aria-label')).toBe(
      'Subscribe to @bob.bsky.social',
    );
    expect(checkboxOf(fixture, 'did:plc:carol').getAttribute('aria-label')).toBe(
      'Already subscribed to @carol.bsky.social',
    );
    expect(checkboxOf(fixture, 'did:plc:dave').getAttribute('aria-label')).toBe(
      'Unsubscribe from did:plc:dave',
    );
  });

  it('stays open and shows the problem without a retry when the import fails', () => {
    const fixture = loaded(candidates({ follows: [follow()] }));
    tick(fixture, 'did:plc:bob');

    press(fixture, 'social-import-apply');
    http.expectOne(IMPORT).flush(
      {
        type: 'social_network_unreachable',
        title: 'x',
        status: 502,
        detail: 'Bluesky could not be reached.',
      },
      { status: 502, statusText: 'Bad Gateway' },
    );
    fixture.detectChanges();

    const banner = byTestId(fixture, 'social-import-failure') as HTMLElement;
    expect(banner.textContent).toContain('Bluesky could not be reached.');
    expect(banner.querySelector('button.action')).toBeNull();
    expect(toastStub.show).not.toHaveBeenCalled();
    expect(close).not.toHaveBeenCalled();
  });

  it('keeps Cancel disabled while the import is in flight', () => {
    const fixture = loaded(candidates({ follows: [follow()] }));
    const cancel = () =>
      byTestId(fixture, 'social-import-cancel')?.querySelector('button') as HTMLButtonElement;
    tick(fixture, 'did:plc:bob');

    press(fixture, 'social-import-apply');

    expect(cancel().disabled).toBe(true);
    http
      .expectOne(IMPORT)
      .flush({ imported: 1, alreadySubscribed: 0, invalid: 0, skippedOverLimit: 0, removed: 0 });
  });
});
