import { TestBed } from '@angular/core/testing';
import { Subject } from 'rxjs';
import { TokenStore } from '../../core/auth/token.store';
import { SentPost } from './composer/composed-post';
import { PostRequest } from './composer/compose-request';
import { Composer } from './composer/composer.service';
import { TextPostComposer } from './text-post-composer.service';

describe('TextPostComposer', () => {
  let closed: Subject<SentPost[]>;
  let composer: { open: jest.Mock; announce: jest.Mock };
  const sent = [{ network: 'mastodon', url: 'https://mastodon.example/@alice/1' }] as SentPost[];

  function make(): TextPostComposer {
    TestBed.configureTestingModule({ providers: [{ provide: Composer, useValue: composer }] });
    return TestBed.inject(TextPostComposer);
  }

  const lastRequest = (): PostRequest => composer.open.mock.calls.at(-1)![0] as PostRequest;

  beforeEach(() => {
    localStorage.clear();
    closed = new Subject<SentPost[]>();
    composer = { open: jest.fn(() => closed), announce: jest.fn() };
  });

  it('opens a text post on the network asked for, with the session draft', () => {
    make().open('mastodon');

    expect(lastRequest()).toEqual(expect.objectContaining({ kind: 'post', network: 'mastodon' }));
    expect(lastRequest().draft!()).toBe('');
  });

  it('keeps the draft for the next composer, whichever network opens it, while nothing went out', () => {
    const textPosts = make();
    textPosts.open('mastodon');
    lastRequest().draft!.set('Half a thought');
    closed.complete();

    closed = new Subject<SentPost[]>();
    textPosts.open('bluesky');

    expect(lastRequest().network).toBe('bluesky');
    expect(lastRequest().draft!()).toBe('Half a thought');
    expect(composer.announce).not.toHaveBeenCalled();
  });

  it('clears the draft and shows what went out once a post reached a network', () => {
    make().open('mastodon');
    const draft = lastRequest().draft!;
    draft.set('Just a thought');

    closed.next(sent);

    expect(draft()).toBe('');
    expect(composer.announce).toHaveBeenCalledWith(sent);
  });

  it('forgets the draft when the signed-in identity changes', () => {
    const textPosts = make();
    TestBed.tick();
    textPosts.open('mastodon');
    const draft = lastRequest().draft!;
    draft.set('Not for the next user');

    TestBed.inject(TokenStore).set('next-user-jwt');
    TestBed.tick();

    expect(draft()).toBe('');
  });
});
