import { Injectable, inject } from '@angular/core';
import { SharingService } from '../../../core/preferences/sharing.service';
import { EntryStateActions } from '../../shell/entry-state-actions.service';
import { ArticleSource } from '../content/article-source.service';
import { ShareRecorder } from './share-recorder';

@Injectable()
export class EntryShareRecorder implements ShareRecorder {
  private readonly article = inject(ArticleSource);
  private readonly actions = inject(EntryStateActions);
  private readonly sharing = inject(SharingService);

  record(): void {
    if (this.sharing.rememberShares()) this.actions.share(this.article.entry());
  }
}
