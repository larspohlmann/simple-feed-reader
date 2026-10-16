import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  ElementRef,
  NgZone,
  afterNextRender,
  computed,
  effect,
  inject,
  input,
  linkedSignal,
  signal,
} from '@angular/core';
import { NgTemplateOutlet } from '@angular/common';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { IconButtonDirective } from '../../../shared/icon-button/icon-button.directive';
import { ListActionDirective } from '../../../shared/list-action/list-action.directive';
import { SkeletonComponent } from '../../../shared/skeleton/skeleton.component';
import { WarningBoxComponent } from '../../../shared/warning-box/warning-box.component';
import { ProxiedImageDirective } from '../../../shared/proxied-image/proxied-image.directive';
import { avatarToneOf } from '../../../shared/avatar-tone';
import { initialsOf } from '../../../shared/initials';
import { LanguageService } from '../../../core/i18n/language.service';
import { SOCIAL_NETWORK_NAMES } from '../../../core/social-network';
import { CommentsService } from './comments.service';
import { SocialCountsService } from '../social-counts/social-counts.service';
import { relativeTime } from '../../format';
import { EntryDto } from '../../models';
import { EntryCommentDto } from '../../entry-comment';
import { mediumRatio } from '../../entry-medium';
import { observeOnSight } from '../../list/paging';
import { PostMediaComponent } from '../../media/post-media/post-media.component';
import { QuotedPostComponent } from '../../quoted-post/quoted-post.component';
import { LinkCardComponent } from '../../link-card/link-card.component';
import { ListAutoplayCoordinator } from '../../media/autoplay/list-autoplay-coordinator';
import { UnlockedVideoElements } from '../../media/autoplay/unlocked-video-elements';

type CommentsEntry = Pick<EntryDto, 'id' | 'comments' | 'discussionUrl' | 'social'>;

const DEEPEST_INDENT = 5;

@Component({
  selector: 'app-entry-comments',
  imports: [
    NgTemplateOutlet,
    TranslocoPipe,
    IconComponent,
    IconButtonDirective,
    ListActionDirective,
    SkeletonComponent,
    WarningBoxComponent,
    ProxiedImageDirective,
    PostMediaComponent,
    QuotedPostComponent,
    LinkCardComponent,
  ],
  templateUrl: './entry-comments.component.html',
  styleUrl: './entry-comments.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
  providers: [ListAutoplayCoordinator, UnlockedVideoElements],
})
export class EntryCommentsComponent {
  readonly entry = input.required<CommentsEntry>();
  /** The pane whose viewport the section loads on sight of. */
  readonly scroller = input.required<HTMLElement>();

  private readonly service = inject(CommentsService);
  private readonly socialCounts = inject(SocialCountsService);
  private readonly language = inject(LanguageService);
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly zone = inject(NgZone);
  private readonly destroyRef = inject(DestroyRef);
  private readonly autoplay = inject(ListAutoplayCoordinator);
  private readonly now = signal(Date.now());
  private readonly entryId = computed(() => this.entry().id);
  private readonly loadsOnSight = computed(() => this.entry().comments === 'auto');

  readonly state = computed(() => this.service.state(this.entryId())());
  readonly network = computed(() => {
    const social = this.entry().social;
    return social ? SOCIAL_NETWORK_NAMES[social.network] : null;
  });
  private readonly brokenAvatars = linkedSignal<number, ReadonlySet<string>>({
    source: this.entryId,
    computation: () => new Set(),
  });
  readonly retryInSeconds = computed(() => {
    const state = this.state();
    if (state.status !== 'throttled') return 0;
    return Math.max(0, Math.ceil((state.retryAt - this.now()) / 1000));
  });
  private readonly throttledUntil = computed(() => {
    const state = this.state();
    return state.status === 'throttled' ? state.retryAt : null;
  });

  constructor() {
    effect((onCleanup) => {
      if (!this.loadsOnSight()) return;
      const id = this.entryId();
      onCleanup(
        observeOnSight(this.host.nativeElement, this.scroller(), () => this.service.load(id)),
      );
    });
    effect((onCleanup) => {
      const until = this.throttledUntil();
      if (until !== null) onCleanup(this.countDownTo(until));
    });
    afterNextRender(() => this.playOneMediumAtATime());
  }

  load(): void {
    this.service.load(this.entryId());
  }

  reload(): void {
    this.service.reload(this.entryId());
  }

  refresh(): void {
    const entry = this.entry();
    this.service.reload(entry.id);
    if (entry.social) this.socialCounts.reload(entry);
  }

  when(iso: string): string {
    return relativeTime(iso, this.language.lang());
  }

  indentOf(comment: EntryCommentDto): number {
    return Math.min(comment.depth, DEEPEST_INDENT);
  }

  ratioOf(comment: EntryCommentDto): number {
    return mediumRatio(comment.media[0]);
  }

  isAvatarBroken(url: string): boolean {
    return this.brokenAvatars().has(url);
  }

  markAvatarBroken(url: string): void {
    this.brokenAvatars.update((broken) => new Set(broken).add(url));
  }

  initialsOf(comment: EntryCommentDto): string {
    return initialsOf(comment.author ?? comment.authorHandle ?? '');
  }

  toneOf(comment: EntryCommentDto): number {
    return avatarToneOf(comment.authorHandle ?? comment.author ?? '');
  }

  private playOneMediumAtATime(): void {
    const article = this.host.nativeElement.closest('article');
    if (!article) return;
    const listening = new AbortController();
    this.destroyRef.onDestroy(() => listening.abort());
    this.zone.runOutsideAngular(() =>
      article.addEventListener('play', ({ target }) => this.played(article, target), {
        capture: true,
        signal: listening.signal,
      }),
    );
  }

  private played(article: HTMLElement, medium: EventTarget | null): void {
    if (!(medium instanceof HTMLMediaElement)) return;
    if (this.owns(medium)) this.pauseMediaOutside(article);
    else this.holdAutoplayWhilePlaying(medium);
  }

  private pauseMediaOutside(article: HTMLElement): void {
    for (const medium of Array.from(article.querySelectorAll('video, audio'))) {
      if (medium instanceof HTMLMediaElement && !medium.paused && !this.owns(medium)) {
        medium.pause();
      }
    }
  }

  private holdAutoplayWhilePlaying(medium: HTMLMediaElement): void {
    this.autoplay.suspend();
    const stopped = new AbortController();
    const resume = () => {
      stopped.abort();
      this.autoplay.resume();
    };
    medium.addEventListener('pause', resume, { signal: stopped.signal });
    medium.addEventListener('ended', resume, { signal: stopped.signal });
  }

  private owns(medium: HTMLMediaElement): boolean {
    return this.host.nativeElement.contains(medium);
  }

  // Outside the zone: an in-zone interval never lets whenStable() settle.
  private countDownTo(until: number): () => void {
    this.now.set(Date.now());
    const ticker = this.zone.runOutsideAngular(() =>
      setInterval(() => {
        this.now.set(Date.now());
        if (Date.now() >= until) clearInterval(ticker);
      }, 1000),
    );
    return () => clearInterval(ticker);
  }
}
