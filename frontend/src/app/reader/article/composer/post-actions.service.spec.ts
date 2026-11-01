import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse } from '@angular/common/http';
import { Dialog } from '@angular/cdk/dialog';
import { Observable, of, throwError } from 'rxjs';
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
import { SentPost } from './composed-post';
import { ComposerDialogComponent } from './composer-dialog.component';
import { PostActions } from './post-actions.service';
import { PostSentDialogComponent } from './post-sent-dialog.component';

const ADDRESS = 'https://mastodon.example/users/alice/statuses/200';

describe('PostActions', () => {
  let composed: Observable<SentPost | undefined>;
  let comments: { insertReply: jest.Mock; removeReply: jest.Mock; countQuote: jest.Mock };
  let counts: { stepReplies: jest.Mock; stepQuotes: jest.Mock };
  let api: { deleteReply: jest.Mock; deletePost: jest.Mock };
  let confirm: { ask: jest.Mock };
  let toast: { show: jest.Mock };
  let dialog: { open: jest.Mock };
  let actions: PostActions;

  const sent: SentPost = {
    address: 'https://x/9',
    url: null,
    kind: 'reply',
    network: 'mastodon',
    text: 'Hi',
    authorHandle: '@a',
    target: { kind: 'post', authorName: 'Ann', authorHandle: '@ann', excerpt: 'Hi', card: null },
  };
  const comment = (depth: number): EntryCommentDto =>
    anEntryComment({
      depth,
      post: aCommentPost({ address: ADDRESS, isOwn: true }),
    });

  const sentOverlays = () =>
    dialog.open.mock.calls.filter(([component]) => component === PostSentDialogComponent);

  beforeEach(() => {
    composed = of(sent);
    comments = { insertReply: jest.fn(), removeReply: jest.fn(), countQuote: jest.fn() };
    counts = { stepReplies: jest.fn(), stepQuotes: jest.fn() };
    api = {
      deleteReply: jest.fn(() => of(undefined)),
      deletePost: jest.fn(() => of({ outcome: 'deleted' })),
    };
    confirm = { ask: jest.fn(() => of(true)) };
    toast = { show: jest.fn() };
    dialog = {
      open: jest.fn((component: unknown) => ({
        closed: component === ComposerDialogComponent ? composed : of(undefined),
      })),
    };
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        { provide: CommentsService, useValue: comments },
        { provide: SocialCountsService, useValue: counts },
        { provide: SocialPostApi, useValue: api },
        { provide: ConfirmService, useValue: confirm },
        { provide: ToastService, useValue: toast },
        { provide: Dialog, useValue: dialog },
      ],
    });
    actions = TestBed.inject(PostActions);
  });

  it('opens the composer as an app dialog for the reply', () => {
    actions.reply(7, 'mastodon', null);

    expect(dialog.open).toHaveBeenCalledWith(ComposerDialogComponent, {
      panelClass: 'app-dialog',
      data: { kind: 'reply', entryId: 7, network: 'mastodon', address: null },
    });
  });

  it('shows a reply to the post at once and counts it on the post', () => {
    actions.reply(7, 'mastodon', null);

    expect(comments.insertReply).toHaveBeenCalledWith(
      7,
      null,
      expect.objectContaining({ authorHandle: '@a' }),
    );
    expect(counts.stepReplies).toHaveBeenCalledWith(7, true);
    expect(sentOverlays()).toEqual([]);
  });

  it('shows a reply to a comment under it, which counts it there, not on the post', () => {
    actions.reply(7, 'mastodon', ADDRESS);

    expect(comments.insertReply).toHaveBeenCalledWith(7, ADDRESS, expect.anything());
    expect(counts.stepReplies).not.toHaveBeenCalled();
  });

  it('counts a quote of the post there and shows what was posted, leaving the thread alone', () => {
    const posted: SentPost = { ...sent, url: 'https://x.test/@a/9', kind: 'quote' };
    composed = of(posted);

    actions.quote(7, 'mastodon', null);

    expect(dialog.open).toHaveBeenCalledWith(
      ComposerDialogComponent,
      expect.objectContaining({ data: expect.objectContaining({ kind: 'quote' }) }),
    );
    expect(comments.insertReply).not.toHaveBeenCalled();
    expect(counts.stepQuotes).toHaveBeenCalledWith(7);
    expect(dialog.open).toHaveBeenCalledWith(
      PostSentDialogComponent,
      expect.objectContaining({ data: posted }),
    );
  });

  it('counts a quote of a reply on that reply', () => {
    actions.quote(7, 'mastodon', ADDRESS);

    expect(counts.stepQuotes).not.toHaveBeenCalled();
    expect(comments.countQuote).toHaveBeenCalledWith(7, ADDRESS);
    expect(sentOverlays()).toHaveLength(1);
  });

  it('shows a link post as posted, leaving the counts and the thread alone', () => {
    const posted: SentPost = {
      ...sent,
      kind: 'link',
      network: 'bluesky',
      target: {
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
    composed = of(posted);

    actions.postLink('bluesky', 'https://example.com/story', 'A story');

    expect(dialog.open).toHaveBeenCalledWith(
      ComposerDialogComponent,
      expect.objectContaining({
        data: {
          kind: 'link',
          network: 'bluesky',
          url: 'https://example.com/story',
          title: 'A story',
        },
      }),
    );
    expect(comments.insertReply).not.toHaveBeenCalled();
    expect(comments.countQuote).not.toHaveBeenCalled();
    expect(counts.stepReplies).not.toHaveBeenCalled();
    expect(counts.stepQuotes).not.toHaveBeenCalled();
    expect(dialog.open).toHaveBeenCalledWith(
      PostSentDialogComponent,
      expect.objectContaining({ data: posted }),
    );
  });

  it('changes nothing when the composer closes without sending', () => {
    composed = of(undefined);

    actions.reply(7, 'mastodon', null);

    expect(comments.insertReply).not.toHaveBeenCalled();
    expect(sentOverlays()).toEqual([]);
  });

  it('deletes a reply only after the reader confirms, then drops it and its count', () => {
    actions.delete(7, 'mastodon', comment(0));

    expect(confirm.ask).toHaveBeenCalledWith(
      expect.objectContaining({
        danger: true,
        message: 'It is deleted on Mastodon as well.',
      }),
    );
    expect(api.deleteReply).toHaveBeenCalledWith(7, ADDRESS);
    expect(comments.removeReply).toHaveBeenCalledWith(7, ADDRESS);
    expect(counts.stepReplies).toHaveBeenCalledWith(7, false);
  });

  it('leaves the post count alone when the deleted reply answered a comment', () => {
    actions.delete(7, 'mastodon', comment(1));

    expect(counts.stepReplies).not.toHaveBeenCalled();
  });

  it('deletes nothing when the reader cancels', () => {
    confirm.ask.mockReturnValue(of(false));

    actions.delete(7, 'mastodon', comment(0));

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

    actions.delete(7, 'mastodon', comment(0));

    expect(comments.removeReply).not.toHaveBeenCalled();
    expect(toast.show).toHaveBeenCalledWith({ message: 'No connected account wrote this reply.' });
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
    expect(toast.show).toHaveBeenCalledWith({ message: 'Bluesky is down.' });
  });
});
