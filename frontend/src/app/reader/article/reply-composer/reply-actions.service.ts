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
import { ReplyTarget } from './reply-composer-dialog.component';
import { ReplyComposer } from './reply-composer.service';
import { sentComment } from './sent-comment';

/**
 * Replying and deleting a reply, reflected in the comments panel and the counts row at once: the network's
 * thread takes a while to include a change, and its cached copy waits for the next load.
 */
@Injectable({ providedIn: 'root' })
export class ReplyActions {
  private readonly composer = inject(ReplyComposer);
  private readonly comments = inject(CommentsService);
  private readonly counts = inject(SocialCountsService);
  private readonly api = inject(SocialPostApi);
  private readonly confirm = inject(ConfirmService);
  private readonly toast = inject(ToastService);
  private readonly transloco = inject(TranslocoService);

  /** `parent` is the comment answered; null answers the entry's own post. */
  reply(target: ReplyTarget, parent: EntryCommentDto | null): void {
    this.composer.open(target).subscribe((sent) => {
      if (sent === undefined) return;
      const parentAddress = parent?.post?.address ?? null;
      this.comments.insertReply(target.entryId, parentAddress, sentComment(sent, new Date()));
      if (parentAddress === null) this.counts.stepReplies(target.entryId, true);
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
