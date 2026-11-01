import { Injectable, inject } from '@angular/core';
import { Dialog } from '@angular/cdk/dialog';
import { Observable } from 'rxjs';
import { PublishedReplyDto } from '../../entry-social';
import { ReplyComposerDialogComponent, ReplyTarget } from './reply-composer-dialog.component';

/** Opens the reply composer; it answers the published reply, or nothing when closed. */
@Injectable({ providedIn: 'root' })
export class ReplyComposer {
  private readonly dialog = inject(Dialog);

  open(target: ReplyTarget): Observable<PublishedReplyDto | undefined> {
    return this.dialog.open<PublishedReplyDto, ReplyTarget>(ReplyComposerDialogComponent, {
      panelClass: 'app-dialog',
      data: target,
    }).closed;
  }
}
