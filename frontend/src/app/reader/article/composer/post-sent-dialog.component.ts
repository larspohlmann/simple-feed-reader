import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { A11yModule } from '@angular/cdk/a11y';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { TranslocoPipe } from '@jsverse/transloco';
import { ButtonComponent } from '../../../shared/button/button.component';
import { OverlayPanelComponent } from '../../../shared/overlay-panel/overlay-panel.component';
import { ComposeTargetComponent } from './compose-target.component';
import { ComposeTarget, SentPost } from './composer-dialog.component';

/** A post published outside the thread, and what it shared or quoted. */
export interface PostSent {
  target: ComposeTarget;
  sent: SentPost;
}

/** Shows a just-published link post or quote as it went out, with a way to open it on the network. */
@Component({
  selector: 'app-post-sent-dialog',
  imports: [
    A11yModule,
    ButtonComponent,
    ComposeTargetComponent,
    OverlayPanelComponent,
    TranslocoPipe,
  ],
  templateUrl: './post-sent-dialog.component.html',
  styleUrl: './post-sent-dialog.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PostSentDialogComponent {
  protected readonly ref = inject(DialogRef);
  protected readonly posted = inject<PostSent>(DIALOG_DATA);
  protected readonly headingKey = `reader.posted.${this.posted.target.kind}.${this.posted.target.network}`;

  protected open(url: string): void {
    window.open(url, '_blank', 'noopener');
    this.ref.close();
  }
}
