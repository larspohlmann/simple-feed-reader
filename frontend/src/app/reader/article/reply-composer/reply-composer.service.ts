import { Injectable, inject } from '@angular/core';
import { Dialog } from '@angular/cdk/dialog';
import { Observable } from 'rxjs';
import {
  ReplyComposerDialogComponent,
  ReplyTarget,
  SentReply,
} from './reply-composer-dialog.component';

/** Opens the reply composer; it answers the published reply, or nothing when closed. */
@Injectable({ providedIn: 'root' })
export class ReplyComposer {
  private readonly dialog = inject(Dialog);

  open(target: ReplyTarget): Observable<SentReply | undefined> {
    return this.dialog.open<SentReply, ReplyTarget>(ReplyComposerDialogComponent, {
      panelClass: 'app-dialog',
      data: target,
    }).closed;
  }
}
