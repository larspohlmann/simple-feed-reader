import { ChangeDetectionStrategy, Component } from '@angular/core';
import { EntryKickerLineComponent } from './entry-kicker-line.component';
import { EntryMetaComponent } from '../entry-meta/entry-meta.component';
import { EntryDuplicatesComponent } from './entry-duplicates.component';
import { EntryImageBlockBase } from './entry-image-block-base';

@Component({
  selector: 'app-entry-wide',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [EntryKickerLineComponent, EntryMetaComponent, EntryDuplicatesComponent],
  templateUrl: './entry-wide.component.html',
  styleUrl: './entry-wide.component.scss',
})
export class EntryWideComponent extends EntryImageBlockBase {}
