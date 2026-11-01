import { Injectable, inject } from '@angular/core';
import { Dialog } from '@angular/cdk/dialog';
import { Observable } from 'rxjs';
import { ComposerDialogComponent, ComposeTarget, SentPost } from './composer-dialog.component';

/** Opens the composer; it answers the published post, or nothing when closed. */
@Injectable({ providedIn: 'root' })
export class PostComposer {
  private readonly dialog = inject(Dialog);

  open(target: ComposeTarget): Observable<SentPost | undefined> {
    return this.dialog.open<SentPost, ComposeTarget>(ComposerDialogComponent, {
      panelClass: 'app-dialog',
      data: target,
    }).closed;
  }
}
