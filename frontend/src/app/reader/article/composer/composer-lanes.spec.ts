import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse } from '@angular/common/http';
import { Subject, of, throwError } from 'rxjs';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import {
  ComposerContextDto,
  PostComposerSubjectDto,
  PublishedPostDto,
  ResponseTargetDto,
} from '../../entry-social';
import { SocialPostApi } from '../../social-post-api';
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

  function initialised(): ComposerLanes {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [ComposerLanes, { provide: SocialPostApi, useValue: api }],
    });
    const lanes = TestBed.inject(ComposerLanes);
    lanes.init(TARGET);
    return lanes;
  }

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

    expect(lanes.primary().accountId()).toBe(5);
    expect(api.composer).toHaveBeenLastCalledWith(TARGET, 5);
  });

  it('send carries the key minted once, across retries', () => {
    api.send.mockReturnValueOnce(throwError(refusal));
    const lanes = initialised();
    const lane = lanes.primary();

    lane.send(DRAFT, 'Hi').subscribe({ error: () => undefined });
    lane.send(DRAFT, 'Hi').subscribe();

    const keys = api.send.mock.calls.map((call) => call[1].idempotencyKey);
    expect(keys).toEqual([lane.idempotencyKey, lane.idempotencyKey]);
    expect(api.send).toHaveBeenLastCalledWith(TARGET, {
      ...DRAFT,
      accountId: 4,
      idempotencyKey: lane.idempotencyKey,
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
});
