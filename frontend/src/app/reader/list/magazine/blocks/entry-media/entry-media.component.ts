import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  afterRenderEffect,
  computed,
  inject,
} from '@angular/core';
import { EntryKickerLineComponent } from '../../entry-kicker-line.component';
import { EntryMetaComponent } from '../../../entry-meta/entry-meta.component';
import { EntryDuplicatesComponent } from '../../entry-duplicates.component';
import { EntryBlockBase } from '../../entry-block-base';
import { postMedia } from '../../magazine-slot-fit';
import { EntryMediumDto } from '../../../../models';
import { ProxiedImageDirective } from '../../../../../shared/proxied-image/proxied-image.directive';
import { attachHlsStreams } from '../../../../article/decorators/hls-streams';

/** The backend's title for a post with no text of its own (`ParsedTitleModel::UNTITLED`). */
const UNTITLED = '(untitled)';
const UNKNOWN_RATIO = 16 / 9;

@Component({
  selector: 'app-entry-media',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    EntryKickerLineComponent,
    EntryMetaComponent,
    EntryDuplicatesComponent,
    ProxiedImageDirective,
  ],
  templateUrl: './entry-media.component.html',
  styleUrl: './entry-media.component.scss',
})
export class EntryMediaComponent extends EntryBlockBase {
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef).nativeElement;

  readonly media = computed(() => postMedia(this.entry()));
  readonly caption = computed(() => (this.entry().title === UNTITLED ? '' : this.entry().excerpt));

  constructor() {
    super();
    afterRenderEffect(() => {
      this.media();
      attachHlsStreams(this.host);
    });
  }

  protected ratio(medium: EntryMediumDto): number {
    return medium.width && medium.height ? medium.width / medium.height : UNKNOWN_RATIO;
  }
}
