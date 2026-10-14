import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  ElementRef,
  afterRenderEffect,
  inject,
  input,
} from '@angular/core';
import { EntryMediumDto } from '../../entry-medium';
import { ProxiedImageDirective } from '../../../shared/proxied-image/proxied-image.directive';
import { armHlsStreams, releaseHlsStreams } from '../hls-streams';
import { pauseMedia } from '../../list/magazine/blocks/entry-media/pause-media';

@Component({
  selector: 'app-post-media',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ProxiedImageDirective],
  templateUrl: './post-media.component.html',
  styleUrl: './post-media.component.scss',
  host: { '[attr.data-count]': 'media().length', '[style.--ratio]': 'ratio()' },
})
export class PostMediaComponent {
  readonly media = input.required<EntryMediumDto[]>();
  readonly ratio = input.required<number>();
  readonly videoLabel = input.required<string>();

  private scrollAway?: IntersectionObserver;
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef).nativeElement;

  constructor() {
    afterRenderEffect(() => {
      this.media();
      armHlsStreams(this.host);
      this.pauseWhenScrolledAway();
    });
    inject(DestroyRef).onDestroy(() => {
      this.scrollAway?.disconnect();
      pauseMedia(this.host);
      releaseHlsStreams(this.host);
    });
  }

  private pauseWhenScrolledAway(): void {
    const videos = Array.from(this.host.querySelectorAll('video'));
    if (typeof IntersectionObserver === 'undefined' || videos.length === 0) return;
    this.scrollAway ??= new IntersectionObserver((changes) => {
      for (const change of changes) {
        if (!change.isIntersecting && change.target instanceof HTMLVideoElement) {
          change.target.pause();
        }
      }
    });
    for (const video of videos) this.scrollAway.observe(video);
  }
}
