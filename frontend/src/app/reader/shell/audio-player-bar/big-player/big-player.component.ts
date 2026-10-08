import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  Injector,
  WritableSignal,
  afterNextRender,
  computed,
  effect,
  inject,
  signal,
  viewChild,
} from '@angular/core';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { CdkScrollable } from '@angular/cdk/scrolling';
import { Router } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../../shared/icon/icon.component';
import { LanguageService } from '../../../../core/i18n/language.service';
import { AudioPlayerService, AudioTrackEntry, SKIP_SECONDS } from '../../../audio-player.service';
import { scrubFill } from '../../../audio/scrub-fill';
import { formatDuration, relativeTime } from '../../../format';
import { LayoutService } from '../../../layout.service';
import { prefersReducedMotion } from '../../../article/reading/reduced-motion';
import { entryParam } from '../../../query/slug';
import { AudioArtworkComponent } from '../audio-artwork/audio-artwork.component';
import { AudioPlaylistComponent } from '../audio-playlist/audio-playlist.component';

/** What closing the big player asks for next; closing without one just minimises it. */
export type BigPlayerExit = 'playlist';

export const BIG_PLAYER_TITLE_ID = 'big-player-title';

/** AudioSurface owns whether the phone sheet shows the playlist, so it outlasts one opening. */
export interface BigPlayerData {
  playlistShown: WritableSignal<boolean>;
}

/** Far enough that a scroll-ish wobble does not close the sheet, as on the action sheet. */
const SWIPE_DISMISS_DISTANCE = 60;

/**
 * The now-playing view behind the mini bar's artwork (#1442). Like the bar it holds no
 * playback state, so it opens on a paused, rehydrated track too; it closes itself once
 * nothing is loaded.
 */
@Component({
  selector: 'app-big-player',
  imports: [IconComponent, AudioArtworkComponent, AudioPlaylistComponent, TranslocoPipe],
  templateUrl: './big-player.component.html',
  styleUrl: './big-player.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
  hostDirectives: [CdkScrollable],
  host: {
    '(touchstart)': 'onTouchStart($event)',
    '(touchend)': 'onTouchEnd($event)',
  },
})
export class BigPlayerComponent {
  protected readonly player = inject(AudioPlayerService);
  private readonly ref = inject<DialogRef<BigPlayerExit>>(DialogRef);
  private readonly router = inject(Router);
  private readonly language = inject(LanguageService);
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly data = inject<BigPlayerData>(DIALOG_DATA);
  private readonly injector = inject(Injector);
  private readonly extras = viewChild.required<ElementRef<HTMLElement>>('extras');

  protected readonly titleId = BIG_PLAYER_TITLE_ID;
  protected readonly skipStep = SKIP_SECONDS;
  protected readonly format = formatDuration;
  protected readonly dragging = signal(false);
  protected readonly sheet = inject(LayoutService).isPhone;
  protected readonly playlistShown = computed(() => this.sheet() && this.data.playlistShown());

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

  constructor() {
    effect(() => {
      if (!this.player.current()) this.ref.close();
    });
  }

  protected minimise(): void {
    this.ref.close();
  }

  /** The sheet has room for the list under the controls; the card hands over to the bar's panel. */
  protected togglePlaylist(): void {
    if (!this.sheet()) {
      this.ref.close('playlist');
      return;
    }
    this.data.playlistShown.update((shown) => !shown);
    if (this.data.playlistShown()) {
      afterNextRender(
        () =>
          this.extras().nativeElement.scrollIntoView({
            block: 'start',
            behavior: prefersReducedMotion() ? 'auto' : 'smooth',
          }),
        { injector: this.injector },
      );
    }
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

  protected onTouchStart(event: TouchEvent): void {
    const exempt =
      event.target instanceof Element && event.target.closest('.scrubber, app-audio-playlist');
    const scrolled = this.host.nativeElement.scrollTop > 0;
    this.swipeStart =
      event.touches.length === 1 && !exempt && !scrolled ? event.touches[0].clientY : null;
  }

  protected onTouchEnd(event: TouchEvent): void {
    const end = event.changedTouches[0].clientY;
    if (this.swipeStart !== null && end - this.swipeStart > SWIPE_DISMISS_DISTANCE) {
      this.ref.close();
    }
    this.swipeStart = null;
  }
}
