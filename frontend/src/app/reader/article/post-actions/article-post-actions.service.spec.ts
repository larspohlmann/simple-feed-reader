import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse } from '@angular/common/http';
import { Observable, filter, of, throwError } from 'rxjs';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { aCommentPost, anEntry, anEntryComment } from '../../../../testing/entry-testing';
import { aThreadPost } from '../../../../testing/thread-testing';
import { ConfirmService } from '../../../shared/confirm-dialog/confirm.service';
import { ToastService } from '../../../shared/toast/toast.service';
import { EntryCommentDto } from '../../entry-comment';
import { EntryDto } from '../../models';
import { SocialPostApi } from '../../social-post-api';
import { CommentsService } from '../entry-comments/comments.service';
import { SocialCountsService } from '../social-counts/social-counts.service';
import { NetworkShares } from '../share/network-shares.service';
import { SentPost } from '../../social/composer/composed-post';
import { Composer } from '../../social/composer/composer.service';
import { ArticlePostActions } from './article-post-actions.service';

const ADDRESS = 'https://mastodon.example/users/alice/statuses/200';

describe('ArticlePostActions', () => {
  let composed: Observable<SentPost[] | undefined>;
  let comments: { insertReply: jest.Mock; removeReply: jest.Mock; countQuote: jest.Mock };
  let counts: { stepReplies: jest.Mock; stepQuotes: jest.Mock };
  let shares: { reflect: jest.Mock };
  let api: { deleteReply: jest.Mock; deletePost: jest.Mock };
  let confirm: { ask: jest.Mock };
  let toast: { show: jest.Mock; showProblem: jest.Mock };
  let composer: { open: jest.Mock; announce: jest.Mock };
  let actions: ArticlePostActions;

  const item = { id: 7, canonicalItemId: 3 };
  const sent: SentPost = {
    address: 'https://x/9',
    url: null,
    kind: 'reply',
    network: 'mastodon',
    text: 'Hi',
    authorHandle: '@a',
    subject: { kind: 'post', authorName: 'Ann', authorHandle: '@ann', excerpt: 'Hi', card: null },
  };
  const comment = (depth: number): EntryCommentDto =>
    anEntryComment({
      depth,
      post: aCommentPost({ address: ADDRESS, isOwn: true }),
    });

  beforeEach(() => {
    composed = of([sent]);
    comments = { insertReply: jest.fn(), removeReply: jest.fn(), countQuote: jest.fn() };
    counts = { stepReplies: jest.fn(), stepQuotes: jest.fn() };
    shares = { reflect: jest.fn() };
    api = {
      deleteReply: jest.fn(() => of(undefined)),
      deletePost: jest.fn(() => of({ outcome: 'deleted' })),
    };
    confirm = { ask: jest.fn(() => of(true)) };
    toast = { show: jest.fn(), showProblem: jest.fn() };
    composer = {
      open: jest.fn(() =>
        composed.pipe(filter((posts): posts is SentPost[] => posts !== undefined)),
      ),
      announce: jest.fn(),
    };
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        { provide: CommentsService, useValue: comments },
        { provide: SocialCountsService, useValue: counts },
        { provide: NetworkShares, useValue: shares },
        { provide: SocialPostApi, useValue: api },
        { provide: ConfirmService, useValue: confirm },
        { provide: ToastService, useValue: toast },
        { provide: Composer, useValue: composer },
      ],
    });
    actions = TestBed.inject(ArticlePostActions);
  });

  it('opens the composer for the reply', () => {
    actions.reply(item, 'mastodon', null);

    expect(composer.open).toHaveBeenCalledWith({
      kind: 'reply',
      entryId: 7,
      network: 'mastodon',
      address: null,
    });
  });

  it('shows a reply to the post at once and counts it on the post', () => {
    actions.reply(item, 'mastodon', null);

    expect(comments.insertReply).toHaveBeenCalledWith(
      item,
      null,
      expect.objectContaining({ authorHandle: '@a' }),
    );
    expect(counts.stepReplies).toHaveBeenCalledWith(item, true);
    expect(composer.announce).not.toHaveBeenCalled();
  });

  it('shows a reply to a comment under it, which counts it there, not on the post', () => {
    actions.reply(item, 'mastodon', ADDRESS);

    expect(comments.insertReply).toHaveBeenCalledWith(item, ADDRESS, expect.anything());
    expect(counts.stepReplies).not.toHaveBeenCalled();
  });

  it('counts a quote of the post there and shows what was posted, leaving the thread alone', () => {
    const posted: SentPost = { ...sent, url: 'https://x.test/@a/9', kind: 'quote' };
    composed = of([posted]);

    actions.quote(item, 'mastodon', null);

    expect(composer.open).toHaveBeenCalledWith(expect.objectContaining({ kind: 'quote' }));
    expect(comments.insertReply).not.toHaveBeenCalled();
    expect(counts.stepQuotes).toHaveBeenCalledWith(item);
    expect(shares.reflect).toHaveBeenCalledWith(7);
    expect(composer.announce).toHaveBeenCalledWith([posted]);
  });

  it('counts a quote of a reply on that reply', () => {
    actions.quote(item, 'mastodon', ADDRESS);

    expect(counts.stepQuotes).not.toHaveBeenCalled();
    expect(comments.countQuote).toHaveBeenCalledWith(item, ADDRESS);
    expect(shares.reflect).not.toHaveBeenCalled();
    expect(composer.announce).toHaveBeenCalledTimes(1);
  });

  it('shares nothing when the quote composer closes unsent', () => {
    composed = of(undefined);

    actions.quote(item, 'mastodon', null);

    expect(shares.reflect).not.toHaveBeenCalled();
  });

  it('shows a link post as posted, leaving the counts and the thread alone', () => {
    const posted: SentPost = {
      ...sent,
      kind: 'link',
      network: 'bluesky',
      subject: {
        kind: 'link',
        card: {
          url: 'https://example.com/story',
          title: 'A story',
          description: null,
          imageUrl: null,
          siteName: 'example.com',
        },
      },
    };
    composed = of([posted]);

    const sentPosts: SentPost[][] = [];
    actions
      .postLink('bluesky', { url: 'https://example.com/story', title: 'A story', quote: null })
      .subscribe((sent) => sentPosts.push(sent));
    expect(sentPosts).toEqual([[posted]]);

    expect(composer.open).toHaveBeenCalledWith({
      kind: 'link',
      network: 'bluesky',
      url: 'https://example.com/story',
      title: 'A story',
      quote: null,
    });
    expect(comments.insertReply).not.toHaveBeenCalled();
    expect(comments.countQuote).not.toHaveBeenCalled();
    expect(counts.stepReplies).not.toHaveBeenCalled();
    expect(counts.stepQuotes).not.toHaveBeenCalled();
    expect(composer.announce).toHaveBeenCalledWith([posted]);
  });

  it('opens the link composer on the quote the reader selected', () => {
    const link = {
      url: 'https://example.com/story#:~:text=A%20line',
      title: 'A story',
      quote: 'A line',
    };

    actions.postLink('mastodon', link).subscribe();

    expect(composer.open).toHaveBeenCalledWith({ kind: 'link', network: 'mastodon', ...link });
  });

  it('changes nothing when the composer closes without sending', () => {
    composed = of(undefined);

    actions.reply(item, 'mastodon', null);

    expect(comments.insertReply).not.toHaveBeenCalled();
    expect(composer.announce).not.toHaveBeenCalled();
  });

  it('deletes a reply only after the reader confirms, then drops it and its count', () => {
    actions.delete(item, 'mastodon', comment(0));

    expect(confirm.ask).toHaveBeenCalledWith(
      expect.objectContaining({
        danger: true,
        message: 'It is deleted on Mastodon as well.',
      }),
    );
    expect(api.deleteReply).toHaveBeenCalledWith(7, ADDRESS);
    expect(comments.removeReply).toHaveBeenCalledWith(item, ADDRESS);
    expect(counts.stepReplies).toHaveBeenCalledWith(item, false);
  });

  it('leaves the post count alone when the deleted reply answered a comment', () => {
    actions.delete(item, 'mastodon', comment(1));

    expect(counts.stepReplies).not.toHaveBeenCalled();
  });

  it('deletes nothing when the reader cancels', () => {
    confirm.ask.mockReturnValue(of(false));

    actions.delete(item, 'mastodon', comment(0));

    expect(api.deleteReply).not.toHaveBeenCalled();
  });

  it('keeps the reply and says why when the network refuses', () => {
    api.deleteReply.mockReturnValue(
      throwError(
        () =>
          new HttpErrorResponse({
            status: 409,
            error: {
              type: 'social_post_refused',
              title: 'x',
              detail: 'No connected account wrote this reply.',
            },
          }),
      ),
    );

    actions.delete(item, 'mastodon', comment(0));

    expect(comments.removeReply).not.toHaveBeenCalled();
    expect(toast.showProblem).toHaveBeenCalledWith(
      expect.objectContaining({ detail: 'No connected account wrote this reply.' }),
    );
  });

  const ownPost = (replies: number): EntryDto =>
    anEntry({
      id: 9,
      social: { network: 'bluesky', isLiked: false, canLike: true, isOwn: true },
      thread: Array.from({ length: replies }, () => aThreadPost()),
    });

  it.each([
    [0, 'Delete this post?'],
    [1, 'Delete this post and its reply?'],
    [3, 'Delete this post and its 3 replies?'],
  ])('asks before deleting a post with %i replies', (replies, title) => {
    let gone = false;
    actions.deletePost(ownPost(replies)).subscribe(() => (gone = true));

    expect(confirm.ask).toHaveBeenCalledWith(
      expect.objectContaining({
        title,
        message: 'It is deleted on Bluesky as well.',
        danger: true,
      }),
    );
    expect(api.deletePost).toHaveBeenCalledWith(9);
    expect(gone).toBe(true);
    expect(toast.show).not.toHaveBeenCalled();
  });

  it('says so when the network no longer had the post, and still lets it go', () => {
    api.deletePost.mockReturnValue(of({ outcome: 'alreadyGone' }));
    let gone = false;

    actions.deletePost(ownPost(0)).subscribe(() => (gone = true));

    expect(gone).toBe(true);
    expect(toast.show).toHaveBeenCalledWith({
      message: 'Already deleted on Bluesky — removed here.',
    });
  });

  it('deletes nothing when the reader cancels', () => {
    confirm.ask.mockReturnValue(of(false));
    let gone = false;

    actions.deletePost(ownPost(0)).subscribe(() => (gone = true));

    expect(api.deletePost).not.toHaveBeenCalled();
    expect(gone).toBe(false);
  });

  it('keeps the post and says why when the network refuses', () => {
    api.deletePost.mockReturnValue(
      throwError(
        () =>
          new HttpErrorResponse({
            status: 502,
            error: { type: 'social_network_unreachable', title: 'x', detail: 'Bluesky is down.' },
          }),
      ),
    );
    let gone = false;

    actions.deletePost(ownPost(0)).subscribe(() => (gone = true));

    expect(gone).toBe(false);
    expect(toast.showProblem).toHaveBeenCalledWith(
      expect.objectContaining({ detail: 'Bluesky is down.' }),
    );
  });

  describe('with a composer that sent two posts', () => {
    const second: SentPost = { ...sent, kind: 'link', network: 'bluesky', address: 'at://2' };
    const first: SentPost = { ...sent, kind: 'link', address: 'https://x/10' };
    const link = { url: 'https://example.com/story', title: 'A story', quote: null };

    it('postLink emits the list and shows one overlay for it', () => {
      composed = of([first, second]);
      const emitted: SentPost[][] = [];

      actions.postLink('mastodon', link).subscribe((posts) => emitted.push(posts));

      expect(emitted).toEqual([[first, second]]);
      expect(composer.announce).toHaveBeenCalledTimes(1);
      expect(composer.announce).toHaveBeenCalledWith([first, second]);
    });

    it('reply and quote still use the single post', () => {
      composed = of([{ ...first, kind: 'reply' }, second]);

      actions.reply(item, 'mastodon', null);

      expect(comments.insertReply).toHaveBeenCalledTimes(1);
      expect(comments.insertReply).toHaveBeenCalledWith(
        item,
        null,
        expect.objectContaining({ post: expect.objectContaining({ address: 'https://x/10' }) }),
      );
    });
  });
});
