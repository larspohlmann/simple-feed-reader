import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  ElementRef,
  afterRenderEffect,
  inject,
  input,
} from '@angular/core';
import { EntryMediumDto, isGif } from '../../entry-medium';
import { GifComponent } from '../gif/gif.component';
import { ProxiedImageDirective } from '../../../shared/proxied-image/proxied-image.directive';
import { armHlsStreams, releaseHlsStreams } from '../hls-streams';
import { PLAYER_VIDEOS } from '../player-videos';
import { pauseMedia } from '../../list/magazine/blocks/entry-media/pause-media';
import { ListAutoplayCoordinator } from '../autoplay/list-autoplay-coordinator';
import { VideoAutoplayPlayer } from '../autoplay/video-autoplay-player';
import { UnlockedVideoElements } from '../autoplay/unlocked-video-elements';

@Component({
  selector: 'app-post-media',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [GifComponent, ProxiedImageDirective],
  templateUrl: './post-media.component.html',
  styleUrl: './post-media.component.scss',
  host: { '[attr.data-count]': 'media().length', '[style.--ratio]': 'ratio()' },
})
export class PostMediaComponent {
  readonly media = input.required<EntryMediumDto[]>();
  readonly ratio = input.required<number>();
  readonly videoLabel = input.required<string>();
  protected readonly isGif = isGif;

  private scrollAway?: IntersectionObserver;
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef).nativeElement;

  private readonly autoplay = inject(ListAutoplayCoordinator, { optional: true });
  private readonly unlockedVideos = inject(UnlockedVideoElements, { optional: true });
  private readonly players = new Map<HTMLVideoElement, VideoAutoplayPlayer>();

  constructor() {
    afterRenderEffect(() => {
      this.mountVideos(this.media(), this.videoLabel());
      armHlsStreams(this.host);
      const videos = Array.from(this.host.querySelectorAll<HTMLVideoElement>(PLAYER_VIDEOS));
      this.pauseWhenScrolledAway(videos);
      this.registerForAutoplay(videos);
    });
    inject(DestroyRef).onDestroy(() => {
      this.scrollAway?.disconnect();
      pauseMedia(this.host);
      releaseHlsStreams(this.host);
      this.unregisterVideosWhere(() => true);
    });
  }

  private mountVideos(media: EntryMediumDto[], label: string): void {
    const clips = media.filter((medium) => medium.kind === 'video');
    this.host.querySelectorAll('.video-slot').forEach((slot, index) => {
      const video = slot.querySelector('video') ?? slot.appendChild(this.newVideo(clips[index]));
      video.setAttribute('aria-label', label);
    });
  }

  private newVideo(clip: EntryMediumDto): HTMLVideoElement {
    const video = this.unlockedVideos?.take() ?? document.createElement('video');
    video.controls = true;
    video.setAttribute('playsinline', '');
    video.setAttribute('preload', 'none');
    video.setAttribute('src', clip.url);
    if (clip.previewImageUrl) video.setAttribute('poster', clip.previewImageUrl);
    return video;
  }

  private pauseWhenScrolledAway(videos: HTMLVideoElement[]): void {
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

  private registerForAutoplay(videos: HTMLVideoElement[]): void {
    const autoplay = this.autoplay;
    if (autoplay === null) return;
    const rendered = new Set(videos);
    this.unregisterVideosWhere((video) => !rendered.has(video));
    for (const video of videos) {
      if (this.players.has(video)) continue;
      const player = new VideoAutoplayPlayer(video, autoplay);
      this.players.set(video, player);
      autoplay.register(player);
    }
  }

  private unregisterVideosWhere(shouldUnregister: (video: HTMLVideoElement) => boolean): void {
    for (const [video, player] of this.players) {
      if (!shouldUnregister(video)) continue;
      this.autoplay?.unregister(player);
      player.dispose();
      this.players.delete(video);
    }
  }
}
