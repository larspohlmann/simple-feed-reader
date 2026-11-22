import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse } from '@angular/common/http';
import { Subject, of, throwError } from 'rxjs';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { LinkTargetDto, PostTargetDto, ResponseTargetDto } from '../../entry-social';
import { ComposerContextDto, PostComposerSubjectDto, PublishedPostDto } from './composer-dto';
import { ComposerApi } from './composer-api';
import { PostNetwork, PostNetworks } from '../post-networks.service';
import { ComposerLanes } from './composer-lanes';
import { SentPost } from './composed-post';
import { LemmyCommunityMemory } from './lemmy-community-memory';

const QUOTED: PostComposerSubjectDto = {
  kind: 'post',
  authorName: 'Ann',
  authorHandle: '@ann@hachyderm.io',
  excerpt: 'What do you think?',
  card: null,
};

const TARGET: ResponseTargetDto = {
  kind: 'reply',
  entryId: 7,
  network: 'mastodon',
  address: 'https://hachyderm.io/users/ann/statuses/200',
};

const LINK: LinkTargetDto = {
  kind: 'link',
  network: 'mastodon',
  url: 'https://example.com/story',
  title: 'A long read',
  quote: null,
};

const BOTH: PostNetwork[] = [
  { network: 'bluesky', handles: ['@alice.bsky.social'] },
  { network: 'mastodon', handles: ['@alice@mastodon.example'] },
];

const DRAFT = { text: 'Hi', language: 'en' };

describe('ComposerLanes', () => {
  let api: { context: jest.Mock; send: jest.Mock };
  let memory: { recall: jest.Mock; remember: jest.Mock };

  const context = (overrides: Partial<ComposerContextDto> = {}): ComposerContextDto => ({
    kind: 'reply',
    accounts: [
      { id: 4, handle: '@alice@mastodon.example' },
      { id: 5, handle: '@bea@mastodon.example' },
    ],
    accountId: 4,
    canSend: true,
    maxCharacters: 500,
    visibility: 'unlisted',
    text: '@ann@hachyderm.io ',
    subject: QUOTED,
    lemmy: null,
    ...overrides,
  });

  const refusal = () =>
    new HttpErrorResponse({
      status: 409,
      error: { type: 'social_post_refused', title: 'Refused', detail: 'Mastodon said no' },
    });

  function initialised(
    target: PostTargetDto = TARGET,
    connected: PostNetwork[] = [],
  ): ComposerLanes {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        ComposerLanes,
        { provide: ComposerApi, useValue: api },
        { provide: PostNetworks, useValue: { connected: signal(connected) } },
        { provide: LemmyCommunityMemory, useValue: memory },
      ],
    });
    const lanes = TestBed.inject(ComposerLanes);
    lanes.init(target);
    return lanes;
  }

  const networksOf = (lanes: readonly { network: string }[]) => lanes.map(({ network }) => network);
  const laneOf = (lanes: ComposerLanes, network: string) =>
    lanes.lanes().find((lane) => lane.network === network)!;

  beforeEach(() => {
    api = {
      context: jest.fn(() => of(context())),
      send: jest.fn(() => of<PublishedPostDto>({ address: 'https://x/1', url: null })),
    };
    memory = { recall: jest.fn(() => null), remember: jest.fn() };
  });

  it("loads the target's context on init", () => {
    const lanes = initialised();

    expect(api.context).toHaveBeenCalledWith(TARGET, undefined);
    expect(lanes.lanes()).toHaveLength(1);
    expect(lanes.primary().network).toBe('mastodon');
    expect(lanes.primary().context()).toEqual(context());
    expect(lanes.primary().visibility()).toBe('unlisted');
  });

  it('a newer load drops the one on the wire', () => {
    const slow = new Subject<ComposerContextDto>();
    api.context.mockReturnValueOnce(slow);
    const lanes = initialised();
    expect(lanes.primary().state()).toEqual({ status: 'loading' });

    lanes.primary().load();

    expect(slow.observed).toBe(false);
    expect(lanes.primary().context()).toEqual(context());
  });

  it('choosing an account reloads with it', () => {
    const lanes = initialised();

    lanes.primary().chooseAccount(5);

    expect(api.context).toHaveBeenLastCalledWith(TARGET, 5);
  });

  it('send carries the key minted once, across retries', () => {
    api.send.mockReturnValueOnce(throwError(refusal));
    const lanes = initialised();
    const lane = lanes.primary();

    lane.send(DRAFT, 'Hi').subscribe({ error: () => undefined });
    lane.send(DRAFT, 'Hi').subscribe();

    const [first, retried] = api.send.mock.calls.map((call) => call[1].idempotencyKey);
    expect(retried).toBe(first);
    expect(api.send).toHaveBeenLastCalledWith(TARGET, {
      ...DRAFT,
      accountId: 4,
      idempotencyKey: expect.any(String),
      visibility: 'unlisted',
    });
    expect(lane.sendError()).toBeNull();
    expect(lane.sent()).toEqual({
      address: 'https://x/1',
      url: null,
      kind: 'reply',
      network: 'mastodon',
      text: 'Hi',
      authorHandle: '@alice@mastodon.example',
      target: QUOTED,
    });
  });

  it('a failed send keeps sent null and sets sendError', () => {
    api.send.mockReturnValue(throwError(refusal));
    const lane = initialised().primary();
    const failed = jest.fn();

    lane.send(DRAFT, 'Hi').subscribe({ error: failed });

    expect(failed).toHaveBeenCalled();
    expect(lane.sent()).toBeNull();
    expect(lane.sendError()).toContain('Mastodon said no');
  });

  it.each([
    [
      'social_network_unavailable',
      "lemmy.world isn't responding right now — it's probably overloaded. Nothing was sent; try again in a minute.",
    ],
    [
      'social_write_outcome_unknown',
      'lemmy.world had trouble with your post, so it may or may not have been published. Check before sending it again.',
    ],
  ])('a send failing with %s names the service and whether it was sent', (type, message) => {
    api.send.mockReturnValue(
      throwError(
        () =>
          new HttpErrorResponse({
            status: 502,
            error: { type, title: 'x', status: 502, detail: 'English', serviceName: 'lemmy.world' },
          }),
      ),
    );
    const lane = initialised().primary();

    lane.send(DRAFT, 'Hi').subscribe({ error: () => undefined });

    expect(lane.sendError()).toBe(message);
  });

  it('a social_network_unavailable send without a service name shows the problem detail', () => {
    api.send.mockReturnValue(
      throwError(
        () =>
          new HttpErrorResponse({
            status: 502,
            error: {
              type: 'social_network_unavailable',
              title: 'x',
              status: 502,
              detail: 'The network is down',
            },
          }),
      ),
    );
    const lane = initialised().primary();

    lane.send(DRAFT, 'Hi').subscribe({ error: () => undefined });

    expect(lane.sendError()).toBe('The network is down');
  });

  it('deactivating a lane clears its send error', () => {
    api.send.mockReturnValue(throwError(refusal));
    const lane = initialised().primary();
    lane.send(DRAFT, 'Hi').subscribe({ error: () => undefined });

    lane.deactivate();

    expect(lane.sendError()).toBeNull();
  });

  it('keeps a chosen visibility over the next account default', () => {
    const lane = initialised().primary();
    lane.chooseVisibility('private');
    api.context.mockReturnValue(of(context({ accountId: 5, visibility: 'public' })));

    lane.chooseAccount(5);

    expect(lane.visibility()).toBe('private');
  });

  it('drops a load still on the wire when the composer goes', () => {
    const slow = new Subject<ComposerContextDto>();
    api.context.mockReturnValueOnce(slow);
    initialised();
    expect(slow.observed).toBe(true);

    TestBed.resetTestingModule();

    expect(slow.observed).toBe(false);
  });

  it('gives a reply one lane, whatever networks are connected', () => {
    const lanes = initialised(TARGET, BOTH);

    expect(networksOf(lanes.lanes())).toEqual(['mastodon']);
    expect(lanes.showsPills()).toBe(false);
  });

  describe('for a link post', () => {
    it('builds a lane per connected link network, the picked one active and loading', () => {
      const lanes = initialised(LINK, BOTH);

      expect(networksOf(lanes.lanes())).toEqual(['bluesky', 'mastodon']);
      expect(networksOf(lanes.active())).toEqual(['mastodon']);
      expect(lanes.showsPills()).toBe(true);
      expect(api.context).toHaveBeenCalledTimes(1);
      expect(api.context).toHaveBeenCalledWith(LINK, undefined);
      expect(laneOf(lanes, 'bluesky').target).toEqual({ ...LINK, network: 'bluesky' });
    });

    it('keeps the picked lane when nothing else is connected', () => {
      const lanes = initialised(LINK, []);

      expect(networksOf(lanes.lanes())).toEqual(['mastodon']);
      expect(lanes.showsPills()).toBe(false);
    });

    it('loads a lane once, when it is first activated', () => {
      const lanes = initialised(LINK, BOTH);

      lanes.toggle('bluesky');
      lanes.toggle('bluesky');
      lanes.toggle('bluesky');

      expect(networksOf(lanes.active())).toEqual(['bluesky', 'mastodon']);
      expect(api.context).toHaveBeenCalledTimes(2);
      expect(api.context).toHaveBeenLastCalledWith({ ...LINK, network: 'bluesky' }, undefined);
    });

    it('refuses to switch off the last active lane', () => {
      const lanes = initialised(LINK, BOTH);

      lanes.toggle('mastodon');

      expect(networksOf(lanes.active())).toEqual(['mastodon']);
    });

    it('makes the first active lane primary once the picked one is off', () => {
      const lanes = initialised(LINK, BOTH);
      lanes.toggle('bluesky');
      expect(lanes.primary().network).toBe('mastodon');

      lanes.toggle('mastodon');

      expect(lanes.primary().network).toBe('bluesky');
      expect(lanes.picked().network).toBe('mastodon');
    });

    it('turns a lane off whose load fails while another is active', () => {
      const lanes = initialised(LINK, BOTH);
      api.context.mockReturnValueOnce(throwError(refusal));

      lanes.toggle('bluesky');

      expect(laneOf(lanes, 'bluesky').active()).toBe(false);
      expect(laneOf(lanes, 'bluesky').loadError()).toContain('Mastodon said no');
    });

    it('keeps the only active lane on when its load fails', () => {
      api.context.mockReturnValueOnce(throwError(refusal));
      const lanes = initialised(LINK, BOTH);

      expect(lanes.primary().active()).toBe(true);
      expect(lanes.primary().loadError()).toContain('Mastodon said no');
    });

    it('reloads a failed lane when it is activated again', () => {
      const lanes = initialised(LINK, BOTH);
      api.context.mockReturnValueOnce(throwError(refusal));
      lanes.toggle('bluesky');

      lanes.toggle('bluesky');

      expect(laneOf(lanes, 'bluesky').active()).toBe(true);
      expect(laneOf(lanes, 'bluesky').context()).toEqual(context());
    });

    it('drops the load of a lane switched off mid-load, and loads again on its return', () => {
      const lanes = initialised(LINK, BOTH);
      const slow = new Subject<ComposerContextDto>();
      api.context.mockReturnValueOnce(slow);
      lanes.toggle('bluesky');

      lanes.toggle('bluesky');

      expect(slow.observed).toBe(false);
      expect(laneOf(lanes, 'bluesky').context()).toBeNull();
      lanes.toggle('bluesky');
      expect(api.context).toHaveBeenCalledTimes(3);
    });

    it('lets only the Mastodon lane carry its content warning', () => {
      const lanes = initialised(LINK, BOTH);
      api.context.mockReturnValueOnce(of(context({ kind: 'link', visibility: null })));
      lanes.toggle('bluesky');
      lanes.primary().contentWarning.set(' Ending ');
      laneOf(lanes, 'bluesky').contentWarning.set('Stray');

      laneOf(lanes, 'mastodon').send(DRAFT, 'Hi').subscribe();
      laneOf(lanes, 'bluesky').send(DRAFT, 'Hi').subscribe();

      expect(api.send.mock.calls[0][1]).toMatchObject({ contentWarning: 'Ending' });
      expect(api.send.mock.calls[1][1]).not.toHaveProperty('contentWarning');
    });

    it('sends every pending lane, answering once all have, and leaves a sent lane as it is', () => {
      const lanes = initialised(LINK, BOTH);
      lanes.toggle('bluesky');
      api.send.mockImplementation((posted: PostTargetDto) =>
        posted.network === 'mastodon'
          ? of<PublishedPostDto>({ address: 'https://x/1', url: null })
          : throwError(refusal),
      );
      const answered = jest.fn();

      lanes.sendAll(DRAFT, 'Hi').subscribe({ complete: answered });

      expect(answered).toHaveBeenCalled();
      expect(networksOf(lanes.sentPosts())).toEqual(['mastodon']);
      expect(networksOf(lanes.pending())).toEqual(['bluesky']);
      lanes.toggle('mastodon');
      expect(laneOf(lanes, 'mastodon').active()).toBe(true);
    });
  });

  describe('the Lemmy lane', () => {
    const ON_LEMMY: LinkTargetDto = { ...LINK, network: 'lemmy' };
    const COMMUNITIES = [
      { id: 41, handle: '!php@programming.dev' },
      { id: 42, handle: '!rss@lemmy.world' },
    ];
    const lemmyContext = (
      lemmy: Partial<NonNullable<ComposerContextDto['lemmy']>> = {},
      overrides: Partial<ComposerContextDto> = {},
    ): ComposerContextDto =>
      context({
        kind: 'link',
        accounts: [
          { id: 8, handle: '@alice@lemmy.world' },
          { id: 9, handle: '@alice@programming.dev' },
        ],
        accountId: 8,
        maxCharacters: 10000,
        visibility: null,
        text: '',
        lemmy: {
          communities: COMMUNITIES,
          title: 'A long read',
          minTitleCharacters: 3,
          maxTitleCharacters: 200,
          ...lemmy,
        },
        ...overrides,
      });

    function lemmyLane() {
      return initialised(ON_LEMMY).primary();
    }

    it('seeds the title from the context and the community from memory', () => {
      memory.recall.mockReturnValue(42);
      api.context.mockReturnValue(of(lemmyContext()));

      const lane = lemmyLane();

      expect(memory.recall).toHaveBeenCalledWith(8);
      expect(lane.title()).toBe('A long read');
      expect(lane.community()).toBe(42);
      expect(lane.fieldsReady()).toBe(true);
    });

    it('drops a remembered community the account no longer offers', () => {
      memory.recall.mockReturnValue(99);
      api.context.mockReturnValue(of(lemmyContext()));

      const lane = lemmyLane();

      expect(lane.community()).toBeNull();
      expect(lane.fieldsReady()).toBe(false);
    });

    it("keeps a typed title on a switch, and resets the community to the next account's", () => {
      memory.recall.mockImplementation((accountId: number) => (accountId === 8 ? 41 : 42));
      api.context.mockReturnValue(of(lemmyContext()));
      const lane = lemmyLane();
      lane.writeTitle('Mine');
      api.context.mockReturnValue(of(lemmyContext({ title: 'Theirs' }, { accountId: 9 })));

      lane.chooseAccount(9);

      expect(lane.community()).toBe(42);
      expect(lane.title()).toBe('Mine');
    });

    it('reseeds an untouched title from the next account on a switch', () => {
      memory.recall.mockReturnValue(41);
      api.context.mockReturnValue(of(lemmyContext()));
      const lane = lemmyLane();
      api.context.mockReturnValue(of(lemmyContext({ title: 'Theirs' }, { accountId: 9 })));

      lane.chooseAccount(9);

      expect(lane.title()).toBe('Theirs');
    });

    it('collapses a line break in a typed title to one space, as Lemmy titles hold none', () => {
      api.context.mockReturnValue(of(lemmyContext()));
      const lane = lemmyLane();

      lane.writeTitle('A long \r\n  read\nabout\rnothing');

      expect(lane.title()).toBe('A long read about nothing');
    });

    it('is not ready with a title of only whitespace', () => {
      memory.recall.mockReturnValue(41);
      api.context.mockReturnValue(of(lemmyContext()));
      const lane = lemmyLane();

      lane.writeTitle('   ');

      expect(lane.fieldsReady()).toBe(false);
    });

    it("is not ready with a title under Lemmy's three characters once trimmed", () => {
      memory.recall.mockReturnValue(41);
      api.context.mockReturnValue(of(lemmyContext()));
      const lane = lemmyLane();

      lane.writeTitle(' ab ');
      expect(lane.fieldsReady()).toBe(false);

      lane.writeTitle(' abc ');
      expect(lane.fieldsReady()).toBe(true);
    });

    it('is not ready with a title over the limit, counting code points', () => {
      memory.recall.mockReturnValue(41);
      api.context.mockReturnValue(of(lemmyContext()));
      const lane = lemmyLane();

      lane.writeTitle('😀'.repeat(200));
      expect(lane.fieldsReady()).toBe(true);
      expect(lane.titleLength()).toBe(200);

      lane.writeTitle('😀'.repeat(201));
      expect(lane.fieldsReady()).toBe(false);
    });

    it('waits for a title the context left empty', () => {
      memory.recall.mockReturnValue(41);
      api.context.mockReturnValue(of(lemmyContext({ title: '' })));
      const lane = lemmyLane();
      expect(lane.fieldsReady()).toBe(false);

      lane.writeTitle('Typed');

      expect(lane.fieldsReady()).toBe(true);
    });

    it('sends the trimmed title and the community, and remembers the community', () => {
      memory.recall.mockReturnValue(41);
      api.context.mockReturnValue(of(lemmyContext()));
      const lane = lemmyLane();
      lane.community.set(42);
      lane.writeTitle(' A long read ');

      lane.send(DRAFT, 'Hi').subscribe();

      expect(api.send).toHaveBeenCalledWith(ON_LEMMY, {
        ...DRAFT,
        accountId: 8,
        idempotencyKey: expect.any(String),
        title: 'A long read',
        communityId: 42,
      });
      expect(memory.remember).toHaveBeenCalledWith(8, 42);
    });

    it('shows what it posted by its title, not its body', () => {
      memory.recall.mockReturnValue(41);
      api.context.mockReturnValue(of(lemmyContext()));
      const lane = lemmyLane();
      lane.writeTitle(' A long read ');
      let sent: SentPost | undefined;

      lane.send(DRAFT, 'The body').subscribe((post) => (sent = post));

      expect(sent?.text).toBe('A long read');
    });

    it('remembers nothing when the send fails', () => {
      memory.recall.mockReturnValue(41);
      api.context.mockReturnValue(of(lemmyContext()));
      api.send.mockReturnValue(throwError(refusal));
      const lane = lemmyLane();

      lane.send(DRAFT, 'Hi').subscribe({ error: () => undefined });

      expect(memory.remember).not.toHaveBeenCalled();
    });

    it('leaves a lane without Lemmy fields always ready, sending neither title nor community', () => {
      const lanes = initialised(LINK, BOTH);
      lanes.toggle('bluesky');
      const bluesky = laneOf(lanes, 'bluesky');

      bluesky.send(DRAFT, 'Hi').subscribe();

      expect(bluesky.fieldsReady()).toBe(true);
      expect(api.send.mock.calls[0][1]).not.toHaveProperty('title');
      expect(api.send.mock.calls[0][1]).not.toHaveProperty('communityId');
      expect(memory.recall).not.toHaveBeenCalled();
      expect(memory.remember).not.toHaveBeenCalled();
    });
  });
});
