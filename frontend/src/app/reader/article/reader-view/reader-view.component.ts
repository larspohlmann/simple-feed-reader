import {
  Component,
  ElementRef,
  Injector,
  computed,
  effect,
  inject,
  input,
  output,
  signal,
  viewChild,
} from '@angular/core';
import { EntryActionHandler } from '../../entry/entry-actions/entry-action-handler';
import { ImageProxyService } from '../../../shared/proxied-image/image-proxy.service';
import { ProgressRailComponent } from '../../../shared/progress-rail/progress-rail.component';
import { ProxiedImageDirective } from '../../../shared/proxied-image/proxied-image.directive';
import { RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { ListActionDirective } from '../../../shared/list-action/list-action.directive';
import { FlagToggleDirective } from '../../../shared/flag-toggle/flag-toggle.directive';
import { FaviconComponent } from '../../../shared/favicon/favicon.component';
import { SpinnerComponent } from '../../../shared/spinner/spinner.component';
import { LoadingOverlayComponent } from '../../../shared/loading-overlay/loading-overlay.component';
import {
  BACK_TO_TOP_AFTER_PX,
  ToTopButtonComponent,
} from '../../../shared/to-top-button/to-top-button.component';
import { EntryPillsComponent } from '../../entry/entry-pills/entry-pills.component';
import { EntryActionsComponent } from '../../entry/entry-actions/entry-actions.component';
import { PaywallNoticeComponent } from '../paywall-notice/paywall-notice.component';
import { EntryCommentsComponent } from '../entry-comments/entry-comments.component';
import { WarningBoxComponent } from '../../../shared/warning-box/warning-box.component';
import { ErrorBannerComponent } from '../../../shared/error-banner/error-banner.component';
import { EntryDto, SubscriptionTagDto } from '../../models';
import { ReaderModeService } from '../content/reader-mode.service';
import { ArticleSource } from '../content/article-source.service';
import { LanguageService } from '../../../core/i18n/language.service';
import { LayoutService } from '../../layout.service';
import { nextHeaderHidden } from '../../scroll/header-scroll';
import { ArticleScrollRestore } from '../reading/article-scroll-restore.service';
import { ReadingScope } from '../reading/reading-scope.service';
import { prefersReducedMotion } from '../reading/reduced-motion';
import { TocEntry, collectToc } from '../reading/reading-toc';
import { ReaderTocComponent } from '../reader-toc/reader-toc.component';
import { ArticleGestures } from './article-gestures.service';
import { formatDuration, relativeTime } from '../../format';
import { decorateArticle } from '../decorators/decorate-article';
import { estimateReadingMinutes } from '../decorators/reading-time';
import { selectionQueryParams } from '../../query/query';
import { AudioPlayerService } from '../../audio-player.service';
import { firstAudioAttachment, toAudioTrack } from '../decorators/audio-attachment';

@Component({
  selector: 'app-reader-view',
  imports: [
    ProgressRailComponent,
    ProxiedImageDirective,
    IconComponent,
    ListActionDirective,
    FlagToggleDirective,
    FaviconComponent,
    SpinnerComponent,
    LoadingOverlayComponent,
    EntryPillsComponent,
    EntryActionsComponent,
    ToTopButtonComponent,
    RouterLink,
    TranslocoPipe,
    PaywallNoticeComponent,
    WarningBoxComponent,
    ErrorBannerComponent,
    EntryCommentsComponent,
    ReaderTocComponent,
  ],
  providers: [ArticleScrollRestore, ArticleGestures, ArticleSource, ReadingScope],
  templateUrl: './reader-view.component.html',
  styleUrls: ['./reader-view.component.scss', './reader-view.component.content.scss'],
})
export class ReaderViewComponent {
  protected readonly selectionQueryParams = selectionQueryParams;

  readonly entry = input.required<EntryDto | null>();
  readonly tags = input<SubscriptionTagDto[]>([]);
  /** Full-screen reading (the mobile overlay) vs. the split pane. The article
   *  is its own layer: its own hide-on-scroll toolbar, slide-out back button,
   *  and return gestures. The shell's app bar stays beneath, untouched (#128). */
  readonly fullscreen = input(false);
  protected readonly actions = inject(EntryActionHandler);

  readonly openOriginal = output<void>();
  // Semantic "back to list" output; not a DOM element's close event.
  // eslint-disable-next-line @angular-eslint/no-output-native
  readonly close = output<void>();

  private readonly content = viewChild<ElementRef<HTMLElement>>('content');
  private readonly commentsSection = viewChild(EntryCommentsComponent, {
    read: ElementRef<HTMLElement>,
  });
  /** Focus target for the corner button on activation — see scrollToTop(). */
  private readonly titleHeading = viewChild<ElementRef<HTMLElement>>('titleHeading');
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly scrollerRef = viewChild<ElementRef<HTMLElement>>('scroller');
  private readonly scroller = computed(() => this.scrollerRef()?.nativeElement);
  private readonly bar = viewChild<ElementRef<HTMLElement>>('bar');
  private readonly i18n = inject(TranslocoService);
  protected readonly readerMode = inject(ReaderModeService);
  private readonly language = inject(LanguageService);
  protected readonly screen = inject(LayoutService);
  private readonly audioPlayer = inject(AudioPlayerService);
  private readonly injector = inject(Injector);
  private readonly reduceMotion = prefersReducedMotion();
  private readonly restore = inject(ArticleScrollRestore);
  protected readonly gestures = inject(ArticleGestures);
  protected readonly source = inject(ArticleSource);
  protected readonly scope = inject(ReadingScope);

  protected readonly formatDuration = formatDuration;

  /** The entry's first playable audio enclosure, surfaced as a listen control
   *  above the article; null when the feed declared none (#915). */
  protected readonly audioAttachment = computed(() =>
    firstAudioAttachment(this.entry()?.attachments ?? []),
  );

  protected listen(): void {
    const entry = this.entry();
    const attachment = this.audioAttachment();
    if (entry && attachment) this.audioPlayer.play(toAudioTrack(entry, attachment));
  }

  // The open entry's reference changes on every optimistic flag update, but its
  // id doesn't. Tracking the loaded id lets the load effect ignore those churns
  // — no re-fetch, and the Reader/Original toggle survives an in-reader action.
  private loadedId: number | null = null;

  // Alias the shared mode signal so the template and computeds read it directly;
  // writes go through the ReaderModeService lifecycle methods below.
  readonly mode = this.readerMode.mode;

  // Table of contents, built from the rendered article headings. `tocOpen` lives
  // here, not in the TOC, so it survives the reader/original swap.
  readonly toc = signal<TocEntry[]>([]);
  readonly tocOpen = signal(false);

  /** Back-to-top affordance: revealed once the reader has scrolled past a screen. */
  readonly showToTop = signal(false);

  /** Full-screen only: the toolbar retracts scrolling down and returns
   *  scrolling up, exactly like the list's app bar over the list — driven by
   *  this article's own scroller alone. */
  readonly toolbarHidden = signal(false);
  private lastToolbarScrollTop = 0;

  /** Estimated minutes to read the displayed text; null hides the meta chip. */
  readonly readingMinutes = computed(() => estimateReadingMinutes(this.source.displayHtml()));

  constructor() {
    this.source.connect(this.entry);
    this.gestures.connect({
      fullscreen: this.fullscreen,
      scroller: this.scroller,
      close: () => this.close.emit(),
    });
    this.restore.connect(this.scroller);

    effect(() => {
      const entry = this.entry();
      const id = entry?.id ?? null;
      // Only react to a genuine entry change — not to a same-entry reference
      // churn from an optimistic flag update (which must not cancel an in-flight
      // load, re-fetch, or reset the mode toggle).
      if (id === this.loadedId) return;
      this.loadedId = id;
      // Before the source opens: a synchronous load calls enableToggle().
      this.readerMode.reset();
      this.restore.arm(id);
      // A new article starts at the top, with a fresh, collapsed TOC and its
      // toolbar presented — this instance is reused, and a retracted toolbar
      // must not carry over to the next article.
      this.toc.set([]);
      this.tocOpen.set(false);
      this.showToTop.set(false);
      this.toolbarHidden.set(false);
      this.lastToolbarScrollTop = 0;
      this.scope.reset();
      this.source.open(entry);
    });

    this.scope.connect({
      scroller: this.scroller,
      content: this.content,
      comments: this.commentsSection,
    });

    // Body images arrive through [innerHTML], so one capturing listener gives them the proxy
    // fallback (error events do not bubble).
    effect((onCleanup) => {
      const content = this.content()?.nativeElement;
      if (!content) return;
      const recover = (event: Event) => {
        if (event.target instanceof HTMLImageElement) {
          void this.injector.get(ImageProxyService).recover(event.target);
        }
      };
      content.addEventListener('error', recover, true);
      onCleanup(() => content.removeEventListener('error', recover, true));
    });

    // Re-decorate external links and re-seat the reading focus whenever the
    // rendered HTML changes (new article, or Reader/Original toggle).
    effect(() => {
      this.source.displayHtml();
      // Depend on the container too, not just the HTML: when extraction fails,
      // displayHtml() recomputes to the same string and never notifies, so the
      // container replacing the placeholder is the only render signal (#101).
      if (!this.content()) return;
      queueMicrotask(() => {
        const host = this.content()?.nativeElement;
        if (!host) return;
        decorateArticle(host, this.i18n);
        this.toc.set(collectToc(host));
        this.scope.refresh();
        // Runs on the original render and again when the reader content swaps in.
        this.restore.reseat(() => this.entry()?.id);
      });
    });

    this.scope.observeResizes();
    this.reserveToolbarHeight();
  }

  /** The toolbar floats over the scroller, which reserves its height (#1332). */
  private reserveToolbarHeight(): void {
    effect((onCleanup) => {
      const bar = this.bar()?.nativeElement;
      if (!bar || typeof ResizeObserver === 'undefined') return;
      const style = this.host.nativeElement.style;
      const observer = new ResizeObserver(() => {
        style.setProperty('--reader-bar-h', `${bar.offsetHeight}px`);
        this.scope.refresh();
      });
      observer.observe(bar);
      onCleanup(() => observer.disconnect());
    });
  }

  /** The toolbar's back button. Full-screen it plays the same slide-out as a
   *  back-swipe rather than cutting straight to the list; the split pane has
   *  no overlay to slide, so it closes directly. */
  onBack(): void {
    if (this.fullscreen()) this.gestures.slideBack();
    else this.close.emit();
  }

  protected onScroll(scrollTop: number): void {
    this.scope.trackScroll(scrollTop);
    this.showToTop.set(scrollTop > BACK_TO_TOP_AFTER_PX);
    if (this.fullscreen()) {
      // `isWide` is false by definition here: full-screen reading only exists
      // on the narrow layout, and the split pane keeps its toolbar put.
      this.toolbarHidden.set(
        nextHeaderHidden({
          previousHidden: this.toolbarHidden(),
          lastTop: this.lastToolbarScrollTop,
          top: scrollTop,
          isWide: false,
        }),
      );
    }
    this.lastToolbarScrollTop = scrollTop;
    if (!this.gestures.leaving()) this.restore.remember(this.entry()?.id, scrollTop);
  }

  /** Jump the reading pane back to the top of the article. */
  scrollToTop(): void {
    this.restore.abort(); // don't let a restore fight the jump
    this.scroller()?.scrollTo({ top: 0, behavior: this.reduceMotion ? 'auto' : 'smooth' });
    // Land focus on the title, not wherever the button was — an unmounted button
    // drops focus to <body>. preventScroll is needed since the heading is still
    // off-screen (why the button showed); a plain focus() would cancel the scroll.
    this.titleHeading()?.nativeElement.focus({ preventScroll: true });
  }

  /** Scroll the reading pane to a heading, clearing the chrome that covers the scroller. */
  scrollToHeading(id: string): void {
    const scroller = this.scroller();
    const heading = this.content()?.nativeElement.querySelector<HTMLElement>(`#${CSS.escape(id)}`);
    if (!scroller || !heading) return;
    this.restore.abort(); // a jump takes over from any in-flight restore
    const top =
      heading.getBoundingClientRect().top -
      scroller.getBoundingClientRect().top +
      scroller.scrollTop;
    const covered = parseFloat(getComputedStyle(scroller).scrollPaddingTop) || 0;
    scroller.scrollTo({
      top: Math.max(0, top - covered),
      behavior: this.reduceMotion ? 'auto' : 'smooth',
    });
  }

  /** The inline error's retry action — refetches the open entry's body. */
  retryBody(): void {
    const entry = this.entry();
    if (entry) this.source.retryBody(entry.id);
  }

  toggleMode(): void {
    this.readerMode.toggle();
  }

  /** Drop the open article from the browser cache and refetch it. */
  refreshArticle(): void {
    const entry = this.entry();
    if (!entry) return;
    this.source.reload(entry.id);
  }

  when(entry: EntryDto): string {
    return relativeTime(entry.publishedAt ?? entry.createdAt, this.language.lang());
  }
}
