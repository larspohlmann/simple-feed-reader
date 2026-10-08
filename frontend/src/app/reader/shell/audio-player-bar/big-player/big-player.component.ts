import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  computed,
  effect,
  inject,
  signal,
} from '@angular/core';
import { DialogRef } from '@angular/cdk/dialog';
import { Router } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../../shared/icon/icon.component';
import { LanguageService } from '../../../../core/i18n/language.service';
import { AudioPlayerService, AudioTrackEntry, SKIP_SECONDS } from '../../../audio-player.service';
import { scrubFill } from '../../../audio/scrub-fill';
import { formatDuration, relativeTime } from '../../../format';
import { entryParam } from '../../../query/slug';
import { AudioArtworkComponent } from '../audio-artwork/audio-artwork.component';

/** What closing the big player asks for next; closing without one just minimises it. */
export type BigPlayerExit = 'playlist';

export const BIG_PLAYER_TITLE_ID = 'big-player-title';

/** Far enough that a scroll-ish wobble does not close the sheet, as on the action sheet. */
const SWIPE_DISMISS_DISTANCE = 60;

/**
 * The now-playing view behind the mini bar's artwork (#1442). Like the bar it holds no
 * playback state, so it opens on a paused, rehydrated track too; it closes itself once
 * nothing is loaded.
 */
@Component({
  selector: 'app-big-player',
  imports: [IconComponent, AudioArtworkComponent, TranslocoPipe],
  templateUrl: './big-player.component.html',
  styleUrl: './big-player.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: {
    '(touchstart)': 'onTouchStart($event)',
    '(touchmove)': 'onTouchMove($event)',
    '(touchend)': 'onTouchEnd()',
  },
})
export class BigPlayerComponent {
  protected readonly player = inject(AudioPlayerService);
  private readonly ref = inject<DialogRef<BigPlayerExit>>(DialogRef);
  private readonly router = inject(Router);
  private readonly language = inject(LanguageService);
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);

  protected readonly titleId = BIG_PLAYER_TITLE_ID;
  protected readonly skipStep = SKIP_SECONDS;
  protected readonly format = formatDuration;
  protected readonly dragging = signal(false);

  protected readonly fill = computed(() =>
    scrubFill(this.player.position(), this.player.buffered(), this.player.duration()),
  );
  protected readonly remaining = computed(() =>
    formatDuration(Math.max(0, this.player.duration() - this.player.position())),
  );
  protected readonly published = computed(() => {
    const entry = this.player.current()?.entry;
    return entry ? relativeTime(entry.publishedAt, this.language.lang()) : '';
  });

  private swipeStart: number | null = null;
  private swipeDistance = 0;

  constructor() {
    effect(() => {
      if (!this.player.current()) this.ref.close();
    });
  }

  protected minimise(): void {
    this.ref.close();
  }

  protected showPlaylist(): void {
    this.ref.close('playlist');
  }

  protected openArticle(entry: AudioTrackEntry): void {
    const url = this.router.parseUrl(this.router.url);
    url.queryParams = { ...url.queryParams, entry: entryParam(entry.id, entry.title) };
    void this.router.navigateByUrl(url);
    this.ref.close();
  }

  protected onScrub(event: Event): void {
    this.player.seek((event.target as HTMLInputElement).valueAsNumber);
  }

  onTouchStart(event: TouchEvent): void {
    const onScrubber = event.target instanceof Element && event.target.closest('.scrubber');
    const scrolled = this.host.nativeElement.scrollTop > 0;
    this.swipeStart =
      event.touches.length === 1 && !onScrubber && !scrolled ? event.touches[0].clientY : null;
    this.swipeDistance = 0;
  }

  onTouchMove(event: TouchEvent): void {
    if (this.swipeStart !== null && event.touches.length === 1) {
      this.swipeDistance = event.touches[0].clientY - this.swipeStart;
    }
  }

  onTouchEnd(): void {
    if (this.swipeStart !== null && this.swipeDistance > SWIPE_DISMISS_DISTANCE) this.ref.close();
    this.swipeStart = null;
  }
}
