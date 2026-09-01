// src/app/reader/magazine/entry-compact.component.ts
import { Component, input } from '@angular/core';
import { EntryKickerLineComponent } from './entry-kicker-line.component';
import { EntryMetaComponent } from '../entry-meta/entry-meta.component';
import { EntryActionsComponent } from '../entry-actions/entry-actions.component';
import { EntryBlockBase } from './entry-block-base';

@Component({
  selector: 'app-entry-compact',
  imports: [EntryKickerLineComponent, EntryMetaComponent, EntryActionsComponent],
  templateUrl: './entry-compact.component.html',
  styleUrl: './entry-compact.component.scss',
})
export class EntryCompactComponent extends EntryBlockBase {
  /** Hidden inside a source group, where the header already names the source
   *  and carries the tag pills — so the per-item pills are suppressed too. */
  readonly showSource = input(true);
}
