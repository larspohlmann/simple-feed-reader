import { Injectable, inject } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { TranslocoService } from '@jsverse/transloco';
import { filter, switchMap } from 'rxjs';
import { SOCIAL_NETWORK_NAMES, SocialNetworkName } from '../../../core/social-network';
import { parseProblem, problemMessage } from '../../../core/problem';
import { ConfirmService } from '../../../shared/confirm-dialog/confirm.service';
import { ToastService } from '../../../shared/toast/toast.service';
import { EntryCommentDto } from '../../entry-comment';
import { SocialPostApi } from '../../social-post-api';
import { CommentsService } from '../entry-comments/comments.service';
import { SocialCountsService } from '../social-counts/social-counts.service';
import { ComposeTarget, ResponseComposeTarget, SentPost } from './composer-dialog.component';
import { PostComposer } from './post-composer.service';
import { sentComment } from './sent-comment';

/**
 * Replying, quoting, posting a link and deleting a reply, reflected in the comments panel and the counts row at
 * once: the network takes a while to count or thread a change, and the cached copies wait for the next load. A quote
 * or a link post lands outside the thread, so a toast offers to open it on the network.
 */
@Injectable({ providedIn: 'root' })
export class ResponseActions {
  private readonly composer = inject(PostComposer);
  private readonly comments = inject(CommentsService);
  private readonly counts = inject(SocialCountsService);
  private readonly api = inject(SocialPostApi);
  private readonly confirm = inject(ConfirmService);
  private readonly toast = inject(ToastService);
  private readonly transloco = inject(TranslocoService);

  respond(target: ComposeTarget): void {
    this.composer.open(target).subscribe((sent) => {
      if (sent === undefined) return;
      if (target.kind === 'link') this.announce('reader.linkPosted', sent.url);
      else if (target.kind === 'quote') this.quoted(target, sent);
      else this.replied(target, sent);
    });
  }

  private replied(target: ResponseComposeTarget, sent: SentPost): void {
    this.comments.insertReply(target.entryId, target.address, sentComment(sent, new Date()));
    if (target.address === null) this.counts.stepReplies(target.entryId, true);
  }

  private quoted(target: ResponseComposeTarget, sent: SentPost): void {
    if (target.address === null) this.counts.stepQuotes(target.entryId);
    else this.comments.countQuote(target.entryId, target.address);
    this.announce('reader.quoteSent', sent.url);
  }

  private announce(messageKey: string, url: string | null): void {
    this.toast.show({
      message: this.transloco.translate(messageKey),
      ...(url === null
        ? {}
        : {
            actionLabel: this.transloco.translate('reader.openPost'),
            action: () => window.open(url, '_blank', 'noopener'),
          }),
    });
  }

  delete(entryId: number, network: SocialNetworkName, comment: EntryCommentDto): void {
    const address = comment.post?.address;
    if (address === undefined) return;
    this.confirm
      .ask({
        title: this.transloco.translate('reader.deleteReply.title'),
        message: this.transloco.translate('reader.deleteReply.message', {
          network: SOCIAL_NETWORK_NAMES[network],
        }),
        confirmLabel: this.transloco.translate('reader.deleteReply.confirm'),
        danger: true,
      })
      .pipe(
        filter(Boolean),
        switchMap(() => this.api.deleteReply(entryId, address)),
      )
      .subscribe({
        next: () => {
          this.comments.removeReply(entryId, address);
          if (comment.depth === 0) this.counts.stepReplies(entryId, false);
        },
        error: (error: HttpErrorResponse) =>
          this.toast.show({ message: problemMessage(parseProblem(error)) }),
      });
  }
}
