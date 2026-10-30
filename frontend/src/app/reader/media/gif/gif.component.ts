import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  ElementRef,
  computed,
  effect,
  inject,
  input,
  signal,
  untracked,
  viewChild,
} from '@angular/core';
import { translateSignal } from '@jsverse/transloco';
import { EntryMediumDto } from '../../entry-medium';
import { ProxiedImageDirective } from '../../../shared/proxied-image/proxied-image.directive';
import { NearViewportMedia, keepNear } from '../near-viewport-media';
import { ReleasableVideo } from '../releasable-video';

const NEAR_VIEWPORT = '200px';

/** Plays near the viewport whatever the list autoplay setting, outside its one-at-a-time turn. */
@Component({
  selector: 'app-gif',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ProxiedImageDirective],
  templateUrl: './gif.component.html',
  styleUrl: './gif.component.scss',
  host: {
    role: 'button',
    tabindex: '0',
    '[attr.aria-label]': 'label()',
    '[attr.aria-pressed]': 'wanted()',
    '(click)': 'toggle($event)',
    '(keydown.enter)': 'toggle($event)',
    '(keydown.space)': 'toggle($event)',
  },
})
export class GifComponent {
  readonly medium = input.required<EntryMediumDto>();

  protected readonly wanted = signal(true);
  private readonly watchesViewport = typeof IntersectionObserver !== 'undefined';
  private readonly nearViewport = signal(!this.watchesViewport);
  protected readonly playing = computed(() => this.wanted() && this.nearViewport());
  private readonly fallbackLabel = translateSignal('reader.media.gif');
  protected readonly label = computed(() => this.medium().alt || this.fallbackLabel());
  private readonly clip = viewChild<ElementRef<HTMLVideoElement>>('clip');
  private readonly nearViewportMedia = inject(NearViewportMedia, { optional: true });
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef).nativeElement;
  private releasable: ReleasableVideo | null = null;

  constructor() {
    this.watchViewport(this.host);
    effect(() => {
      const clip = this.clip()?.nativeElement;
      if (!clip) return;
      if (this.playing()) clip.play().catch(() => undefined);
      else clip.pause();
    });
    effect(() => {
      const clip = this.clip()?.nativeElement;
      if (!clip || this.releasable?.element === clip) return;
      this.releasable = new ReleasableVideo(clip, untracked(this.medium).url);
      untracked(() => this.attachWhenNear());
    });
    inject(DestroyRef).onDestroy(() => {
      this.nearViewportMedia?.unwatch(this.host);
      this.releasable?.release();
    });
  }

  protected toggle(event: Event): void {
    event.preventDefault();
    event.stopPropagation();
    this.wanted.update((wanted) => !wanted);
  }

  private watchViewport(host: HTMLElement): void {
    if (!this.watchesViewport) return;
    const observer = new IntersectionObserver(
      (changes) => {
        for (const change of changes) this.nearViewport.set(change.isIntersecting);
      },
      { rootMargin: NEAR_VIEWPORT },
    );
    observer.observe(host);
    inject(DestroyRef).onDestroy(() => observer.disconnect());
  }

  private attachWhenNear(): void {
    const video = this.releasable;
    if (video === null) return;
    keepNear(this.nearViewportMedia, this.host, {
      approach: () => {
        video.attach();
        if (this.playing()) video.element.play().catch(() => undefined);
      },
      leave: () => video.release(),
    });
  }
}
