import { ChangeDetectionStrategy, Component, computed } from '@angular/core';
import { EntryKickerLineComponent } from '../../entry-kicker-line.component';
import { EntryMetaComponent } from '../../../entry-meta/entry-meta.component';
import { EntryDuplicatesComponent } from '../../entry-duplicates.component';
import { EntryBlockBase } from '../../entry-block-base';
import { OpensEntryDirective } from '../../../opens-entry.directive';

@Component({
  selector: 'app-entry-quote',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    OpensEntryDirective,
    EntryKickerLineComponent,
    EntryMetaComponent,
    EntryDuplicatesComponent,
  ],
  templateUrl: './entry-quote.component.html',
  styleUrl: './entry-quote.component.scss',
})
export class EntryQuoteComponent extends EntryBlockBase {
  /** The first sentence, never a mid-word clamp; an abbreviation such as "e.g. " ends it early. */
  readonly lead = computed(() => {
    const text = this.snippet();
    const stop = text.search(/[.!?](\s|$)/);
    return stop === -1 ? text : text.slice(0, stop + 1);
  });
}
