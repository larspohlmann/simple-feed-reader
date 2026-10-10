import { ChangeDetectionStrategy, Component, computed, linkedSignal } from '@angular/core';
import { EntryKickerLineComponent } from '../../entry-kicker-line.component';
import { EntryMetaComponent } from '../../../entry-meta/entry-meta.component';
import { EntryDuplicatesComponent } from '../../entry-duplicates.component';
import { EntryBlockBase } from '../../entry-block-base';
import { ProxiedImageDirective } from '../../../../../shared/proxied-image/proxied-image.directive';

@Component({
  selector: 'app-entry-link',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    EntryKickerLineComponent,
    EntryMetaComponent,
    EntryDuplicatesComponent,
    ProxiedImageDirective,
  ],
  templateUrl: './entry-link.component.html',
  styleUrl: './entry-link.component.scss',
})
export class EntryLinkComponent extends EntryBlockBase {
  readonly card = computed(() => this.entry().linkCards[0]);
  readonly imageFailed = linkedSignal({ source: this.card, computation: () => false });
}
