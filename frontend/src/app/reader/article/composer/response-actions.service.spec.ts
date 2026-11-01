import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse } from '@angular/common/http';
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
import { ResponseActions } from './response-actions.service';

const ADDRESS = 'https://mastodon.example/users/alice/statuses/200';

describe('ResponseActions', () => {
  let composer: { open: jest.Mock };
  let comments: { insertReply: jest.Mock; removeReply: jest.Mock; countQuote: jest.Mock };
  let counts: { stepReplies: jest.Mock; stepQuotes: jest.Mock };
  let api: { deleteReply: jest.Mock };
  let confirm: { ask: jest.Mock };
  let toast: { show: jest.Mock };
  let actions: ResponseActions;

  const sent: SentPost = { address: 'https://x/9', url: null, text: 'Hi', authorHandle: '@a' };
  const target: ResponseComposeTarget = {
    kind: 'reply',
    entryId: 7,
    network: 'mastodon',
    address: null,
    authorName: null,
    authorHandle: null,
    excerpt: '',
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
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        { provide: PostComposer, useValue: composer },
        { provide: CommentsService, useValue: comments },
        { provide: SocialCountsService, useValue: counts },
        { provide: SocialPostApi, useValue: api },
        { provide: ConfirmService, useValue: confirm },
        { provide: ToastService, useValue: toast },
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
  });

  it('shows a reply to a comment under it, which counts it there, not on the post', () => {
    actions.respond({ ...target, address: ADDRESS });

    expect(comments.insertReply).toHaveBeenCalledWith(7, ADDRESS, expect.anything());
    expect(counts.stepReplies).not.toHaveBeenCalled();
  });

  it('counts a quote of the post there and offers to open it, leaving the thread alone', () => {
    composer.open.mockReturnValue(of({ ...sent, url: 'https://x.test/@a/9' }));
    const open = jest.spyOn(window, 'open').mockImplementation(() => null);

    actions.respond({ ...target, kind: 'quote' });

    expect(comments.insertReply).not.toHaveBeenCalled();
    expect(counts.stepQuotes).toHaveBeenCalledWith(7);
    const shown = toast.show.mock.calls[0][0];
    expect(shown).toMatchObject({ message: 'Quote posted', actionLabel: 'Open' });
    shown.action();
    expect(open).toHaveBeenCalledWith('https://x.test/@a/9', '_blank', 'noopener');
    open.mockRestore();
  });

  it('counts a quote of a reply on that reply and offers no link the network did not name', () => {
    actions.respond({ ...target, kind: 'quote', address: ADDRESS });

    expect(counts.stepQuotes).not.toHaveBeenCalled();
    expect(comments.countQuote).toHaveBeenCalledWith(7, ADDRESS);
    expect(toast.show).toHaveBeenCalledWith({ message: 'Quote posted' });
  });

  it('offers to open a link post, leaving the counts and the thread alone', () => {
    const link: LinkComposeTarget = {
      kind: 'link',
      network: 'bluesky',
      url: 'https://example.com/story',
      title: 'A story',
      site: 'example.com',
      imageUrl: null,
      prefill: 'A story',
    };
    composer.open.mockReturnValue(of({ ...sent, url: 'https://bsky.app/profile/a/post/9' }));
    const open = jest.spyOn(window, 'open').mockImplementation(() => null);

    actions.respond(link);

    expect(composer.open).toHaveBeenCalledWith(link);
    expect(comments.insertReply).not.toHaveBeenCalled();
    expect(comments.countQuote).not.toHaveBeenCalled();
    expect(counts.stepReplies).not.toHaveBeenCalled();
    expect(counts.stepQuotes).not.toHaveBeenCalled();
    const shown = toast.show.mock.calls[0][0];
    expect(shown).toMatchObject({ message: 'Link posted', actionLabel: 'Open' });
    shown.action();
    expect(open).toHaveBeenCalledWith('https://bsky.app/profile/a/post/9', '_blank', 'noopener');
    open.mockRestore();
  });

  it('changes nothing when the composer closes without sending', () => {
    composer.open.mockReturnValue(of(undefined));

    actions.respond(target);

    expect(comments.insertReply).not.toHaveBeenCalled();
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
