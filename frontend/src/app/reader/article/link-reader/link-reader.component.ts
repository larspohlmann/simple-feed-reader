import {
  Component,
  ElementRef,
  computed,
  effect,
  inject,
  input,
  output,
  untracked,
  viewChild,
} from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { ScrollProgressRail } from '../../../shared/progress-rail/scroll-progress-rail';
import { ProxiedImageDirective } from '../../../shared/proxied-image/proxied-image.directive';
import { IconComponent } from '../../../shared/icon/icon.component';
import { ListActionDirective } from '../../../shared/list-action/list-action.directive';
import { LoadingOverlayComponent } from '../../../shared/loading-overlay/loading-overlay.component';
import { WarningBoxComponent } from '../../../shared/warning-box/warning-box.component';
import { prefersReducedMotion } from '../../../core/reduced-motion';
import { LayoutService } from '../../layout.service';
import { LinkCardDto } from '../../link-card/link-card';
import { LinkCardRef } from '../../query/link-param';
import { ListAutoplayCoordinator } from '../../media/autoplay/list-autoplay-coordinator';
import { UnlockedVideoElements } from '../../media/autoplay/unlocked-video-elements';
import { AUTOPLAY_REGION } from '../../media/autoplay/autoplay-region';
import { ArticleLayerComponent } from '../article-layer/article-layer.component';
import { ArticleContentComponent } from '../article-content/article-content.component';
import { PaywallNoticeComponent } from '../paywall-notice/paywall-notice.component';
import { ReadingScope } from '../reading/reading-scope.service';
import { LinkArticleSource } from './link-article-source.service';

/** Where the back button returns: the article the card sits in, or the list. */
export type LinkReaderReturn = 'article' | 'list';

/** A link card's page in the reader, with only the chrome a page that is no entry has. */
@Component({
  selector: 'app-link-reader',
  imports: [
    TranslocoPipe,
    ProxiedImageDirective,
    IconComponent,
    ListActionDirective,
    LoadingOverlayComponent,
    WarningBoxComponent,
    PaywallNoticeComponent,
    ArticleLayerComponent,
    ArticleContentComponent,
  ],
  providers: [
    ReadingScope,
    LinkArticleSource,
    ListAutoplayCoordinator,
    UnlockedVideoElements,
    // Nothing autoplays in a linked page: an undefined region covers nothing.
    { provide: AUTOPLAY_REGION, useValue: () => undefined },
  ],
  templateUrl: './link-reader.component.html',
  styleUrl: './link-reader.component.scss',
})
export class LinkReaderComponent {
  readonly ref = input.required<LinkCardRef>();
  /** The card as the store lists it; null on a deep link to a card not in view. */
  readonly card = input<LinkCardDto | null>(null);
  readonly fullscreen = input(false);
  readonly returnsTo = input.required<LinkReaderReturn>();

  // Semantic "back" output; not a DOM element's close event.
  // eslint-disable-next-line @angular-eslint/no-output-native
  readonly close = output<void>();

  protected readonly source = inject(LinkArticleSource);
  protected readonly scope = inject(ReadingScope);
  private readonly reduceMotion = prefersReducedMotion();
  private readonly layer = viewChild(ArticleLayerComponent);
  private readonly articleContent = viewChild(ArticleContentComponent);
  private readonly titleHeading = viewChild<ElementRef<HTMLElement>>('titleHeading');

  protected readonly progressRail = new ScrollProgressRail({
    isWide: inject(LayoutService).isWide,
    contentBottom: this.scope.readingEnd,
    layout: this.scope.readingEnd,
  });

  protected readonly title = computed(
    () => this.card()?.title ?? this.source.article()?.title ?? '',
  );
  protected readonly siteName = computed(
    () => this.card()?.siteName ?? this.source.article()?.siteName ?? '',
  );
  protected readonly originalUrl = computed(
    () =>
      this.card()?.url ?? this.source.article()?.url ?? this.source.failure()?.url ?? null,
  );
  protected readonly backLabelKey = computed(() =>
    this.returnsTo() === 'article' ? 'reader.backToArticle' : 'reader.backToList',
  );

  constructor() {
    effect(() => {
      const ref = this.ref();
      untracked(() => {
        this.layer()?.resetChrome();
        this.source.open(ref);
      });
    });

    this.scope.connect({
      scroller: computed(() => this.layer()?.scroller()),
      content: computed(() => this.articleContent()?.element()),
      trailing: [],
    });

    this.scope.observeResizes();
  }

  protected reload(): void {
    this.source.reload(this.ref());
  }

  protected scrollToTop(): void {
    this.layer()?.scrollToTop(this.reduceMotion ? 'auto' : 'smooth');
    // The heading is still off-screen; a plain focus() would cancel the scroll.
    this.titleHeading()?.nativeElement.focus({ preventScroll: true });
  }
}
