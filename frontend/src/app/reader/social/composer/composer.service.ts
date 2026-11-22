import { Injectable, inject } from '@angular/core';
import { Dialog } from '@angular/cdk/dialog';
import { Observable, filter } from 'rxjs';
import { SentPost } from './composed-post';
import { ComposeRequest } from './compose-request';
import { ComposerDialogComponent } from './composer-dialog.component';
import { PostSentDialogComponent } from './post-sent-dialog.component';

/** Opens the composer for a request and shows what went out; what the posts change around it is the caller's. */
@Injectable({ providedIn: 'root' })
export class Composer {
  private readonly dialog = inject(Dialog);

  /** Emits the posts that went out; a closed composer emits nothing. */
  open(request: ComposeRequest): Observable<SentPost[]> {
    return this.dialog
      .open<SentPost[], ComposeRequest>(ComposerDialogComponent, {
        panelClass: 'app-dialog',
        data: request,
      })
      .closed.pipe(filter((posts): posts is SentPost[] => posts !== undefined));
  }

  announce(posts: SentPost[]): void {
    this.dialog.open<void, SentPost[]>(PostSentDialogComponent, {
      panelClass: 'app-dialog',
      data: posts,
    });
  }
}
