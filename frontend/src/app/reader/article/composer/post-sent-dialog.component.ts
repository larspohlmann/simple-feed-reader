import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { A11yModule } from '@angular/cdk/a11y';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { TranslocoPipe } from '@jsverse/transloco';
import { ButtonComponent } from '../../../shared/button/button.component';
import { openExternal } from '../../../shared/open-external';
import { OverlayPanelComponent } from '../../../shared/overlay-panel/overlay-panel.component';
import { SentPost } from './composed-post';
import { ComposerSubjectComponent } from './composer-subject.component';

/** Shows a just-published link post or quote as it went out, with a way to open it on the network. */
@Component({
  selector: 'app-post-sent-dialog',
  imports: [
    A11yModule,
    ButtonComponent,
    ComposerSubjectComponent,
    OverlayPanelComponent,
    TranslocoPipe,
  ],
  templateUrl: './post-sent-dialog.component.html',
  styleUrl: './post-sent-dialog.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PostSentDialogComponent {
  protected readonly ref = inject(DialogRef);
  protected readonly posted = inject<SentPost>(DIALOG_DATA);
  protected readonly headingKey = `reader.posted.${this.posted.kind}.${this.posted.network}`;

  protected open(url: string): void {
    openExternal(url);
    this.ref.close();
  }
}
