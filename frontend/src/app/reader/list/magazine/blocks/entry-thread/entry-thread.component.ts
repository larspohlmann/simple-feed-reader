import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { EntryKickerLineComponent } from '../../entry-kicker-line.component';
import { EntryMetaComponent } from '../../../entry-meta/entry-meta.component';
import { EntryDuplicatesComponent } from '../../entry-duplicates.component';
import { EntryBlockBase } from '../../entry-block-base';
import { LinkCardComponent } from '../../../../link-card/link-card.component';
import { LinkReaderNavigation } from '../../../../shell/link-reader-navigation.service';
import { OpensEntryDirective } from '../../../opens-entry.directive';
import { ThreadLineComponent } from '../../../../../shared/thread-line/thread-line.component';
import { ThreadLinePostComponent } from '../../../../../shared/thread-line/thread-line-post.component';
import { ThreadCountComponent } from '../../../../../shared/thread-line/thread-count.component';

@Component({
  selector: 'app-entry-thread',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    OpensEntryDirective,
    EntryKickerLineComponent,
    EntryMetaComponent,
    EntryDuplicatesComponent,
    LinkCardComponent,
    ThreadLineComponent,
    ThreadLinePostComponent,
    ThreadCountComponent,
  ],
  templateUrl: './entry-thread.component.html',
  styleUrl: './entry-thread.component.scss',
})
export class EntryThreadComponent extends EntryBlockBase {
  private readonly navigation = inject(LinkReaderNavigation);

  protected readonly total = computed(() => this.entry().thread.length + 1);

  protected openInReaderAlone(position: number): void {
    this.navigation.openAlone({ entryId: this.entry().id, position });
  }
}
