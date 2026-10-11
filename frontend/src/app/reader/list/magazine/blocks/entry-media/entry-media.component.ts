import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  ElementRef,
  afterRenderEffect,
  computed,
  inject,
  signal,
} from '@angular/core';
import { DomSanitizer } from '@angular/platform-browser';
import { TranslocoPipe } from '@jsverse/transloco';
import { EntryKickerLineComponent } from '../../entry-kicker-line.component';
import { EntryMetaComponent } from '../../../entry-meta/entry-meta.component';
import { EntryDuplicatesComponent } from '../../entry-duplicates.component';
import { EntryBlockBase } from '../../entry-block-base';
import { postMedia } from '../../magazine-slot-fit';
import { EntryMediumDto } from '../../../../models';
import { ProxiedImageDirective } from '../../../../../shared/proxied-image/proxied-image.directive';
import { attachHlsStreams, destroyStreamsIn } from '../../../../article/decorators/hls-streams';
import { pauseMedia } from './pause-media';
import { shortPlayerUrl } from './short-embed';

const UNKNOWN_RATIO = 16 / 9;

@Component({
  selector: 'app-entry-media',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    EntryKickerLineComponent,
    EntryMetaComponent,
    EntryDuplicatesComponent,
    ProxiedImageDirective,
    TranslocoPipe,
  ],
  templateUrl: './entry-media.component.html',
  styleUrl: './entry-media.component.scss',
  host: { '(media-pause)': 'stopShort()' },
})
export class EntryMediaComponent extends EntryBlockBase {
  private scrollAway?: IntersectionObserver;
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef).nativeElement;

  private readonly sanitizer = inject(DomSanitizer);

  readonly media = computed(() => postMedia(this.entry()));
  readonly caption = computed(() =>
    this.entry().titleDerived ? this.entry().excerpt : this.entry().title,
  );
  // shortPlayerUrl admits only the allow-listed player, so the URL can be trusted as a frame source.
  readonly shortPlayer = computed(() => {
    const url = shortPlayerUrl(this.entry().isShort ? this.entry().url : null);
    return url ? this.sanitizer.bypassSecurityTrustResourceUrl(url) : null;
  });
  protected readonly playingShort = signal(false);

  constructor() {
    super();
    afterRenderEffect(() => {
      this.media();
      this.playingShort();
      attachHlsStreams(this.host);
      this.pauseWhenScrolledAway();
    });
    inject(DestroyRef).onDestroy(() => {
      this.scrollAway?.disconnect();
      pauseMedia(this.host);
      destroyStreamsIn(this.host);
    });
  }

  private pauseWhenScrolledAway(): void {
    if (typeof IntersectionObserver === 'undefined') return;
    this.scrollAway ??= new IntersectionObserver((changes) => {
      for (const change of changes) {
        if (change.isIntersecting) continue;
        if (change.target instanceof HTMLVideoElement) change.target.pause();
        else this.stopShort();
      }
    });
    for (const target of Array.from(this.host.querySelectorAll('video, .short'))) {
      this.scrollAway.observe(target);
    }
  }

  protected playShort(event: Event): void {
    event.stopPropagation();
    this.playingShort.set(true);
  }

  protected stopShort(): void {
    this.playingShort.set(false);
  }

  protected ratio(medium: EntryMediumDto): number {
    const entry = this.entry();
    if (medium.url === entry.imageUrl && entry.imageAspectRatio) return entry.imageAspectRatio;
    return medium.width && medium.height ? medium.width / medium.height : UNKNOWN_RATIO;
  }
}
