import { TestBed } from '@angular/core/testing';
import { HttpErrorResponse } from '@angular/common/http';
import { of, throwError } from 'rxjs';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { anEntryComment } from '../../../../testing/entry-testing';
import { ConfirmService } from '../../../shared/confirm-dialog/confirm.service';
import { ToastService } from '../../../shared/toast/toast.service';
import { EntryCommentDto } from '../../entry-comment';
import { SocialPostApi } from '../../social-post-api';
import { CommentsService } from '../entry-comments/comments.service';
import { SocialCountsService } from '../social-counts/social-counts.service';
import { ReplyTarget, SentReply } from './reply-composer-dialog.component';
import { ReplyComposer } from './reply-composer.service';
import { ReplyActions } from './reply-actions.service';

const ADDRESS = 'https://mastodon.example/users/alice/statuses/200';

describe('ReplyActions', () => {
  let composer: { open: jest.Mock };
  let comments: { insertReply: jest.Mock; removeReply: jest.Mock };
  let counts: { stepReplies: jest.Mock };
  let api: { deleteReply: jest.Mock };
  let confirm: { ask: jest.Mock };
  let toast: { show: jest.Mock };
  let actions: ReplyActions;

  const sent: SentReply = { address: 'https://x/9', url: null, text: 'Hi', authorHandle: '@a' };
  const target: ReplyTarget = {
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
      post: {
        address: ADDRESS,
        counts: { likes: 0, reposts: 0, replies: 0, quotes: null },
        isLiked: false,
        isReposted: false,
        canRepost: true,
        canReply: true,
        isOwn: true,
      },
    });

  beforeEach(() => {
    composer = { open: jest.fn(() => of(sent)) };
    comments = { insertReply: jest.fn(), removeReply: jest.fn() };
    counts = { stepReplies: jest.fn() };
    api = { deleteReply: jest.fn(() => of(undefined)) };
    confirm = { ask: jest.fn(() => of(true)) };
    toast = { show: jest.fn() };
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        { provide: ReplyComposer, useValue: composer },
        { provide: CommentsService, useValue: comments },
        { provide: SocialCountsService, useValue: counts },
        { provide: SocialPostApi, useValue: api },
        { provide: ConfirmService, useValue: confirm },
        { provide: ToastService, useValue: toast },
      ],
    });
    actions = TestBed.inject(ReplyActions);
  });

  it('shows a reply to the post at once and counts it on the post', () => {
    actions.reply(target, null);

    expect(composer.open).toHaveBeenCalledWith(target);
    expect(comments.insertReply).toHaveBeenCalledWith(
      7,
      null,
      expect.objectContaining({ authorHandle: '@a' }),
    );
    expect(counts.stepReplies).toHaveBeenCalledWith(7, true);
  });

  it('shows a reply to a comment under it, which counts it there, not on the post', () => {
    actions.reply({ ...target, address: ADDRESS }, comment(0));

    expect(comments.insertReply).toHaveBeenCalledWith(7, ADDRESS, expect.anything());
    expect(counts.stepReplies).not.toHaveBeenCalled();
  });

  it('changes nothing when the composer closes without sending', () => {
    composer.open.mockReturnValue(of(undefined));

    actions.reply(target, null);

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
