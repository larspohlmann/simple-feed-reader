import { Injectable, inject } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { Dialog } from '@angular/cdk/dialog';
import { TranslocoService } from '@jsverse/transloco';
import { EMPTY, Observable, catchError, filter, map, switchMap, tap } from 'rxjs';
import { SOCIAL_NETWORK_NAMES, SocialNetworkName } from '../../../core/social-network';
import { parseProblem, problemMessage } from '../../../core/problem';
import { ConfirmData } from '../../../shared/confirm-dialog/confirm-dialog.component';
import { ConfirmService } from '../../../shared/confirm-dialog/confirm.service';
import { ToastService } from '../../../shared/toast/toast.service';
import { EntryCommentDto } from '../../entry-comment';
import { EntryDto } from '../../models';
import { PostTargetDto } from '../../entry-social';
import { SocialPostApi } from '../../social-post-api';
import { CommentsService } from '../entry-comments/comments.service';
import { SocialCountsService } from '../social-counts/social-counts.service';
import { SentPost } from './composed-post';
import { ComposerDialogComponent } from './composer-dialog.component';
import { PostSentDialogComponent } from './post-sent-dialog.component';
import { sentComment } from './sent-comment';

/**
 * Replying, quoting, posting a link, deleting a reply or one's own post, reflected in the comments panel and the
 * counts row at once: the network takes a while to count or thread a change, and the cached copies wait for the next
 * load. A quote or a link post lands outside the thread, so an overlay shows what went out and offers to open it.
 */
@Injectable({ providedIn: 'root' })
export class PostActions {
  private readonly comments = inject(CommentsService);
  private readonly counts = inject(SocialCountsService);
  private readonly api = inject(SocialPostApi);
  private readonly confirm = inject(ConfirmService);
  private readonly dialog = inject(Dialog);
  private readonly toast = inject(ToastService);
  private readonly transloco = inject(TranslocoService);

  /** Replies to the entry's own post, or to the reply in its thread named by `address`. */
  reply(entryId: number, network: SocialNetworkName, address: string | null): void {
    this.respond({ kind: 'reply', entryId, network, address }).subscribe((sent) => {
      this.comments.insertReply(entryId, address, sentComment(sent, new Date()));
      if (address === null) this.counts.stepReplies(entryId, true);
    });
  }

  /** Quotes the entry's own post, or the reply in its thread named by `address`. */
  quote(entryId: number, network: SocialNetworkName, address: string | null): void {
    this.respond({ kind: 'quote', entryId, network, address }).subscribe((sent) => {
      if (address === null) this.counts.stepQuotes(entryId);
      else this.comments.countQuote(entryId, address);
      this.announce(sent);
    });
  }

  postLink(network: SocialNetworkName, url: string, title: string): void {
    this.respond({ kind: 'link', network, url, title }).subscribe((sent) => this.announce(sent));
  }

  delete(entryId: number, network: SocialNetworkName, comment: EntryCommentDto): void {
    const address = comment.post?.address;
    if (address === undefined) return;
    this.confirmedDelete(
      {
        title: this.transloco.translate('reader.deleteReply.title'),
        message: this.transloco.translate('reader.deleteReply.message', {
          network: SOCIAL_NETWORK_NAMES[network],
        }),
        confirmLabel: this.transloco.translate('reader.deleteReply.confirm'),
        danger: true,
      },
      () => this.api.deleteReply(entryId, address),
    ).subscribe(() => {
      this.comments.removeReply(entryId, address);
      if (comment.depth === 0) this.counts.stepReplies(entryId, false);
    });
  }

  /** Deletes the reader's own post after a confirm; emits once the post is gone here, nothing otherwise. */
  deletePost(entry: EntryDto): Observable<void> {
    const network = entry.social?.network;
    if (network === undefined) return EMPTY;
    const params = { network: SOCIAL_NETWORK_NAMES[network] };
    return this.confirmedDelete(
      {
        title: this.deletePostTitle(entry.thread.length),
        message: this.transloco.translate('reader.deletePost.message', params),
        confirmLabel: this.transloco.translate('reader.deletePost.confirm'),
        danger: true,
      },
      () => this.api.deletePost(entry.id),
    ).pipe(
      tap(({ outcome }) => {
        if (outcome === 'alreadyGone') {
          this.toast.show({
            message: this.transloco.translate('reader.deletePost.alreadyGone', params),
          });
        }
      }),
      map(() => undefined),
    );
  }

  /** Runs `remove` once the reader confirms; a failure shows as a toast and emits nothing. */
  private confirmedDelete<T>(question: ConfirmData, remove: () => Observable<T>): Observable<T> {
    return this.confirm.ask(question).pipe(
      filter(Boolean),
      switchMap(remove),
      catchError((error: HttpErrorResponse) => {
        this.toast.show({ message: problemMessage(parseProblem(error)) });
        return EMPTY;
      }),
    );
  }

  /** Opens the composer; it answers the published post, and nothing when closed. */
  private respond(target: PostTargetDto): Observable<SentPost> {
    return this.dialog
      .open<SentPost, PostTargetDto>(ComposerDialogComponent, {
        panelClass: 'app-dialog',
        data: target,
      })
      .closed.pipe(filter((sent): sent is SentPost => sent !== undefined));
  }

  private deletePostTitle(replies: number): string {
    if (replies === 0) return this.transloco.translate('reader.deletePost.title');
    if (replies === 1) return this.transloco.translate('reader.deletePost.titleWithOneReply');
    return this.transloco.translate('reader.deletePost.titleWithReplies', { count: replies });
  }

  private announce(sent: SentPost): void {
    this.dialog.open<void, SentPost>(PostSentDialogComponent, {
      panelClass: 'app-dialog',
      data: sent,
    });
  }
}
