import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse } from '@angular/common/http';
import { Dialog } from '@angular/cdk/dialog';
import { of, throwError } from 'rxjs';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { aCommentPost, anEntryComment } from '../../../../testing/entry-testing';
import { ConfirmService } from '../../../shared/confirm-dialog/confirm.service';
import { ToastService } from '../../../shared/toast/toast.service';
import { EntryCommentDto } from '../../entry-comment';
import { SocialPostApi } from '../../social-post-api';
import { CommentsService } from '../entry-comments/comments.service';
import { SocialCountsService } from '../social-counts/social-counts.service';
import { LinkComposeTarget, ResponseComposeTarget, SentPost } from './composer-dialog.component';
import { PostComposer } from './post-composer.service';
import { PostSentDialogComponent } from './post-sent-dialog.component';
import { ResponseActions } from './response-actions.service';

const ADDRESS = 'https://mastodon.example/users/alice/statuses/200';

describe('ResponseActions', () => {
  let composer: { open: jest.Mock };
  let comments: { insertReply: jest.Mock; removeReply: jest.Mock; countQuote: jest.Mock };
  let counts: { stepReplies: jest.Mock; stepQuotes: jest.Mock };
  let api: { deleteReply: jest.Mock };
  let confirm: { ask: jest.Mock };
  let toast: { show: jest.Mock };
  let dialog: { open: jest.Mock };
  let actions: ResponseActions;

  const target: ResponseComposeTarget = {
    kind: 'reply',
    entryId: 7,
    network: 'mastodon',
    address: null,
  };
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

  beforeEach(() => {
    composer = { open: jest.fn(() => of(sent)) };
    comments = { insertReply: jest.fn(), removeReply: jest.fn(), countQuote: jest.fn() };
    counts = { stepReplies: jest.fn(), stepQuotes: jest.fn() };
    api = { deleteReply: jest.fn(() => of(undefined)) };
    confirm = { ask: jest.fn(() => of(true)) };
    toast = { show: jest.fn() };
    dialog = { open: jest.fn() };
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        { provide: PostComposer, useValue: composer },
        { provide: CommentsService, useValue: comments },
        { provide: SocialCountsService, useValue: counts },
        { provide: SocialPostApi, useValue: api },
        { provide: ConfirmService, useValue: confirm },
        { provide: ToastService, useValue: toast },
        { provide: Dialog, useValue: dialog },
      ],
    });
    actions = TestBed.inject(ResponseActions);
  });

  it('shows a reply to the post at once and counts it on the post', () => {
    actions.respond(target);

    expect(composer.open).toHaveBeenCalledWith(target);
    expect(comments.insertReply).toHaveBeenCalledWith(
      7,
      null,
      expect.objectContaining({ authorHandle: '@a' }),
    );
    expect(counts.stepReplies).toHaveBeenCalledWith(7, true);
    expect(dialog.open).not.toHaveBeenCalled();
  });

  it('shows a reply to a comment under it, which counts it there, not on the post', () => {
    actions.respond({ ...target, address: ADDRESS });

    expect(comments.insertReply).toHaveBeenCalledWith(7, ADDRESS, expect.anything());
    expect(counts.stepReplies).not.toHaveBeenCalled();
  });

  it('counts a quote of the post there and shows what was posted, leaving the thread alone', () => {
    const quote: ResponseComposeTarget = { ...target, kind: 'quote' };
    const posted: SentPost = { ...sent, url: 'https://x.test/@a/9', kind: 'quote' };
    composer.open.mockReturnValue(of(posted));

    actions.respond(quote);

    expect(comments.insertReply).not.toHaveBeenCalled();
    expect(counts.stepQuotes).toHaveBeenCalledWith(7);
    expect(dialog.open).toHaveBeenCalledWith(
      PostSentDialogComponent,
      expect.objectContaining({ data: posted }),
    );
  });

  it('counts a quote of a reply on that reply', () => {
    actions.respond({ ...target, kind: 'quote', address: ADDRESS });

    expect(counts.stepQuotes).not.toHaveBeenCalled();
    expect(comments.countQuote).toHaveBeenCalledWith(7, ADDRESS);
    expect(dialog.open).toHaveBeenCalledTimes(1);
  });

  it('shows a link post as posted, leaving the counts and the thread alone', () => {
    const link: LinkComposeTarget = {
      kind: 'link',
      network: 'bluesky',
      url: 'https://example.com/story',
      prefill: 'A story',
    };
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
    composer.open.mockReturnValue(of(posted));

    actions.respond(link);

    expect(composer.open).toHaveBeenCalledWith(link);
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
    composer.open.mockReturnValue(of(undefined));

    actions.respond(target);

    expect(comments.insertReply).not.toHaveBeenCalled();
    expect(dialog.open).not.toHaveBeenCalled();
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
});
