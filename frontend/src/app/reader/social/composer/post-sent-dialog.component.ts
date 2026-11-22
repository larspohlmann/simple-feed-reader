import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { A11yModule } from '@angular/cdk/a11y';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { LanguageService } from '../../../core/i18n/language.service';
import { networkList } from '../../../core/network-list';
import { ButtonComponent } from '../../../shared/button/button.component';
import { openExternal } from '../../../shared/open-external';
import { OverlayPanelComponent } from '../../../shared/overlay-panel/overlay-panel.component';
import { SentPost } from './composed-post';
import { ComposerSubjectComponent } from './composer-subject.component';

/** Shows the just-published link posts or quote as they went out, each with a way to open it on its network. */
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
  private readonly transloco = inject(TranslocoService);
  private readonly language = inject(LanguageService).lang;
  protected readonly posts = inject<SentPost[]>(DIALOG_DATA);
  protected readonly single = this.posts.length === 1 ? this.posts[0] : null;
  protected readonly firstOpenable = this.posts.find(({ url }) => url !== null);
  protected readonly heading = this.headingFor(this.posts);

  protected open(url: string): void {
    openExternal(url);
    if (this.single !== null) this.ref.close();
  }

  private headingFor(posts: SentPost[]): string {
    if (posts.length === 1) {
      return this.transloco.translate(`social.posted.${posts[0].kind}.${posts[0].network}`);
    }
    const networks = networkList(
      posts.map(({ network }) => network),
      this.language(),
    );
    return this.transloco.translate('social.posted.many', { networks });
  }
}
