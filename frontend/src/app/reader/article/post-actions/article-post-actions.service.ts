import { Injectable, inject } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { TranslocoService } from '@jsverse/transloco';
import { EMPTY, Observable, catchError, filter, map, switchMap, tap } from 'rxjs';
import { SOCIAL_NETWORK_NAMES, SocialNetworkName } from '../../../core/social-network';
import { parseProblem } from '../../../core/problem';
import { ConfirmData } from '../../../shared/confirm-dialog/confirm-dialog.component';
import { ConfirmService } from '../../../shared/confirm-dialog/confirm.service';
import { ToastService } from '../../../shared/toast/toast.service';
import { EntryCommentDto } from '../../entry-comment';
import { EntryDto } from '../../models';
import { ItemRef } from '../../state/item-key';
import { SocialPostApi } from '../../social-post-api';
import { CommentsService } from '../entry-comments/comments.service';
import { SocialCountsService } from '../social-counts/social-counts.service';
import { NetworkShares } from '../share/network-shares.service';
import { SentPost } from '../../social/composer/composed-post';
import { SharedLinkDto } from '../../social/composer/compose-request';
import { Composer } from '../../social/composer/composer.service';
import { sentComment } from './sent-comment';

/**
 * Replying, quoting, posting a link, deleting a reply or one's own post, reflected in the comments panel and the
 * counts row at once: the network takes a while to count or thread a change, and the cached copies wait for the next
 * load. A quote or a link post lands outside the thread, so an overlay shows what went out and offers to open it.
 */
@Injectable({ providedIn: 'root' })
export class ArticlePostActions {
  private readonly comments = inject(CommentsService);
  private readonly counts = inject(SocialCountsService);
  private readonly shares = inject(NetworkShares);
  private readonly api = inject(SocialPostApi);
  private readonly confirm = inject(ConfirmService);
  private readonly composer = inject(Composer);
  private readonly toast = inject(ToastService);
  private readonly transloco = inject(TranslocoService);

  /** Replies to the entry's own post, or to the reply in its thread named by `address`. */
  reply(item: ItemRef, network: SocialNetworkName, address: string | null): void {
    this.composer
      .open({ kind: 'reply', entryId: item.id, network, address })
      .subscribe(([sent]) => {
        this.comments.insertReply(item, address, sentComment(sent, new Date()));
        if (address === null) this.counts.stepReplies(item, true);
      });
  }

  /** Quotes the entry's own post, or the reply in its thread named by `address`. */
  quote(item: ItemRef, network: SocialNetworkName, address: string | null): void {
    this.composer.open({ kind: 'quote', entryId: item.id, network, address }).subscribe((posts) => {
      if (address === null) this.quotedPost(item);
      else this.comments.countQuote(item, address);
      this.composer.announce(posts);
    });
  }

  /** Emits the posts that went out; a closed composer emits nothing. */
  postLink(network: SocialNetworkName, link: SharedLinkDto): Observable<SentPost[]> {
    return this.composer
      .open({ kind: 'link', network, ...link })
      .pipe(tap((posts) => this.composer.announce(posts)));
  }

  delete(item: ItemRef, network: SocialNetworkName, comment: EntryCommentDto): void {
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
      () => this.api.deleteReply(item.id, address),
    ).subscribe(() => {
      this.comments.removeReply(item, address);
      if (comment.depth === 0) this.counts.stepReplies(item, false);
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
        this.toast.showProblem(parseProblem(error));
        return EMPTY;
      }),
    );
  }

  private quotedPost(item: ItemRef): void {
    this.counts.stepQuotes(item);
    this.shares.reflect(item.id);
  }

  private deletePostTitle(replies: number): string {
    if (replies === 0) return this.transloco.translate('reader.deletePost.title');
    if (replies === 1) return this.transloco.translate('reader.deletePost.titleWithOneReply');
    return this.transloco.translate('reader.deletePost.titleWithReplies', { count: replies });
  }
}
