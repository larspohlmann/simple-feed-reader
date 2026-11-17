import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse } from '@angular/common/http';
import { Subject, of, throwError } from 'rxjs';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import {
  ComposerContextDto,
  LinkTargetDto,
  PostComposerSubjectDto,
  PostTargetDto,
  PublishedPostDto,
  ResponseTargetDto,
} from '../../entry-social';
import { SocialPostApi } from '../../social-post-api';
import { PostNetwork, PostNetworks } from '../share/post-networks.service';
import { ComposerLanes } from './composer-lanes';

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
  let api: { composer: jest.Mock; send: jest.Mock };

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
    target: QUOTED,
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
        { provide: SocialPostApi, useValue: api },
        { provide: PostNetworks, useValue: { connected: signal(connected) } },
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
      composer: jest.fn(() => of(context())),
      send: jest.fn(() => of<PublishedPostDto>({ address: 'https://x/1', url: null })),
    };
  });

  it("loads the target's context on init", () => {
    const lanes = initialised();

    expect(api.composer).toHaveBeenCalledWith(TARGET, undefined);
    expect(lanes.lanes()).toHaveLength(1);
    expect(lanes.primary().network).toBe('mastodon');
    expect(lanes.primary().context()).toEqual(context());
    expect(lanes.primary().visibility()).toBe('unlisted');
  });

  it('a newer load drops the one on the wire', () => {
    const slow = new Subject<ComposerContextDto>();
    api.composer.mockReturnValueOnce(slow);
    const lanes = initialised();
    expect(lanes.primary().state()).toEqual({ status: 'loading' });

    lanes.primary().load();

    expect(slow.observed).toBe(false);
    expect(lanes.primary().context()).toEqual(context());
  });

  it('choosing an account reloads with it', () => {
    const lanes = initialised();

    lanes.primary().chooseAccount(5);

    expect(api.composer).toHaveBeenLastCalledWith(TARGET, 5);
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
    api.composer.mockReturnValue(of(context({ accountId: 5, visibility: 'public' })));

    lane.chooseAccount(5);

    expect(lane.visibility()).toBe('private');
  });

  it('drops a load still on the wire when the composer goes', () => {
    const slow = new Subject<ComposerContextDto>();
    api.composer.mockReturnValueOnce(slow);
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
      expect(api.composer).toHaveBeenCalledTimes(1);
      expect(api.composer).toHaveBeenCalledWith(LINK, undefined);
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
      expect(api.composer).toHaveBeenCalledTimes(2);
      expect(api.composer).toHaveBeenLastCalledWith({ ...LINK, network: 'bluesky' }, undefined);
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
      api.composer.mockReturnValueOnce(throwError(refusal));

      lanes.toggle('bluesky');

      expect(laneOf(lanes, 'bluesky').active()).toBe(false);
      expect(laneOf(lanes, 'bluesky').loadError()).toContain('Mastodon said no');
    });

    it('keeps the only active lane on when its load fails', () => {
      api.composer.mockReturnValueOnce(throwError(refusal));
      const lanes = initialised(LINK, BOTH);

      expect(lanes.primary().active()).toBe(true);
      expect(lanes.primary().loadError()).toContain('Mastodon said no');
    });

    it('reloads a failed lane when it is activated again', () => {
      const lanes = initialised(LINK, BOTH);
      api.composer.mockReturnValueOnce(throwError(refusal));
      lanes.toggle('bluesky');

      lanes.toggle('bluesky');

      expect(laneOf(lanes, 'bluesky').active()).toBe(true);
      expect(laneOf(lanes, 'bluesky').context()).toEqual(context());
    });

    it('drops the load of a lane switched off mid-load, and loads again on its return', () => {
      const lanes = initialised(LINK, BOTH);
      const slow = new Subject<ComposerContextDto>();
      api.composer.mockReturnValueOnce(slow);
      lanes.toggle('bluesky');

      lanes.toggle('bluesky');

      expect(slow.observed).toBe(false);
      expect(laneOf(lanes, 'bluesky').context()).toBeNull();
      lanes.toggle('bluesky');
      expect(api.composer).toHaveBeenCalledTimes(3);
    });

    it('lets only the Mastodon lane carry its content warning', () => {
      const lanes = initialised(LINK, BOTH);
      api.composer.mockReturnValueOnce(of(context({ kind: 'link', visibility: null })));
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
});
