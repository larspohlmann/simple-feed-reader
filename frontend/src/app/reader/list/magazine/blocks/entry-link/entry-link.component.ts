import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { EntryKickerLineComponent } from '../../entry-kicker-line.component';
import { EntryMetaComponent } from '../../../entry-meta/entry-meta.component';
import { EntryDuplicatesComponent } from '../../entry-duplicates.component';
import { EntryBlockBase } from '../../entry-block-base';
import { LinkCardComponent } from '../../../../link-card/link-card.component';
import { LinkCardPlaceholderComponent } from '../../../../link-card/link-card-placeholder.component';
import { LinkReaderNavigation } from '../../../../shell/link-reader-navigation.service';
import { OpensEntryDirective } from '../../../opens-entry.directive';

@Component({
  selector: 'app-entry-link',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    OpensEntryDirective,
    EntryKickerLineComponent,
    EntryMetaComponent,
    EntryDuplicatesComponent,
    LinkCardComponent,
    LinkCardPlaceholderComponent,
  ],
  templateUrl: './entry-link.component.html',
  styleUrl: './entry-link.component.scss',
})
export class EntryLinkComponent extends EntryBlockBase {
  private readonly navigation = inject(LinkReaderNavigation);

  protected openInReaderAlone(position: number): void {
    this.navigation.openAlone({ entryId: this.entry().id, position });
  }
}
