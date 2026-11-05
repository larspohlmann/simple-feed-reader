import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { EntryKickerLineComponent } from '../../entry-kicker-line.component';
import { EntryMetaComponent } from '../../../entry-meta/entry-meta.component';
import { EntryActionsComponent } from '../../../../entry/entry-actions/entry-actions.component';
import { EntryPillsComponent } from '../../../../entry/entry-pills/entry-pills.component';
import { MoreSourcesComponent } from '../../more-sources.component';
import { EntryBlockBase } from '../../entry-block-base';
import { showsShortPill } from '../../../preview-image';
import { OpensEntryDirective } from '../../../opens-entry.directive';

@Component({
  selector: 'app-entry-compact',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    OpensEntryDirective,
    EntryKickerLineComponent,
    EntryMetaComponent,
    EntryActionsComponent,
    EntryPillsComponent,
    MoreSourcesComponent,
  ],
  templateUrl: './entry-compact.component.html',
  styleUrl: './entry-compact.component.scss',
})
export class EntryCompactComponent extends EntryBlockBase {
  /** Hidden inside a source group, where the header already names the source
   *  and carries the tag pills — so the per-item pills are suppressed too. */
  readonly showSource = input(true);
  readonly shortPill = computed(() => showsShortPill(this.entry(), false));
}
