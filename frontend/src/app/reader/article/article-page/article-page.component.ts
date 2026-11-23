import {
  Component,
  ElementRef,
  computed,
  contentChildren,
  effect,
  inject,
  input,
  output,
  signal,
  untracked,
  viewChild,
} from '@angular/core';
import { NgTemplateOutlet } from '@angular/common';
import { TranslocoPipe } from '@jsverse/transloco';
import { ScrollProgressRail } from '../../../shared/progress-rail/scroll-progress-rail';
import { ProxiedImageDirective } from '../../../shared/proxied-image/proxied-image.directive';
import { IconComponent } from '../../../shared/icon/icon.component';
import { ListActionDirective } from '../../../shared/list-action/list-action.directive';
import { FaviconComponent } from '../../../shared/favicon/favicon.component';
import { LoadingOverlayComponent } from '../../../shared/loading-overlay/loading-overlay.component';
import { WarningBoxComponent } from '../../../shared/warning-box/warning-box.component';
import { prefersReducedMotion } from '../../../core/reduced-motion';
import { ArticleLayerComponent } from '../article-layer/article-layer.component';
import { ArticleContentComponent } from '../article-content/article-content.component';
import { PaywallNoticeComponent } from '../paywall-notice/paywall-notice.component';
import { ReaderTocComponent } from '../reader-toc/reader-toc.component';
import { ShareAvailability } from '../share/share-availability';
import { ShareButtonComponent } from '../share/share-button.component';
import { SelectionShareComponent } from '../selection-share/selection-share.component';
import { ArticleSelection } from '../selection-share/article-selection.service';
import { ArticleScrollRestore } from '../reading/article-scroll-restore.service';
import { ReadingScope, type TrailingPart } from '../reading/reading-scope.service';
import { TocEntry, collectToc } from '../reading/reading-toc';
import { estimateReadingMinutes } from '../decorators/reading-time';
import { ARTICLE_PAGE_SOURCE, type ArticlePageBody } from './article-page-source';
import { ArticlePageSlotDirective, ArticlePageSlotName } from './article-page-slot.directive';

function sameBody(left: ArticlePageBody | null, right: ArticlePageBody | null): boolean {
  if (left === null || right === null) return left === right;
  return left.html === right.html && left.pageUrl === right.pageUrl;
}

/** One article page — its layer, skeleton and reading aids — filled by a view through its source and slots. */
@Component({
  selector: 'app-article-page',
  imports: [
    NgTemplateOutlet,
    TranslocoPipe,
    ProxiedImageDirective,
    IconComponent,
    ListActionDirective,
    FaviconComponent,
    LoadingOverlayComponent,
    WarningBoxComponent,
    PaywallNoticeComponent,
    ReaderTocComponent,
    ArticleLayerComponent,
    ArticleContentComponent,
    ShareButtonComponent,
    SelectionShareComponent,
  ],
  providers: [ReadingScope, ArticleSelection, ArticleScrollRestore],
  templateUrl: './article-page.component.html',
  styleUrl: './article-page.component.scss',
})
export class ArticlePageComponent {
  readonly fullscreen = input(false);
  readonly backLabel = input.required<string>();
  readonly trailing = input<readonly TrailingPart[]>([]);

  readonly openOriginal = output<void>();
  readonly back = output<void>();

  protected readonly source = inject(ARTICLE_PAGE_SOURCE);
  protected readonly scope = inject(ReadingScope);
  protected readonly sharing = inject(ShareAvailability);
  protected readonly shareUrl = computed(() =>
    this.sharing.available() ? this.source.url() : null,
  );
  private readonly restore = inject(ArticleScrollRestore);
  private readonly reduceMotion = prefersReducedMotion();

  private readonly slots = contentChildren(ArticlePageSlotDirective);
  protected readonly notices = this.slotNamed('notices');
  protected readonly afterBody = this.slotNamed('afterBody');
  protected readonly footer = this.slotNamed('footer');

  private readonly articleContent = viewChild(ArticleContentComponent);
  private readonly content = computed(() => this.articleContent()?.element);
  private readonly titleHeading = viewChild<ElementRef<HTMLElement>>('titleHeading');
  private readonly layer = viewChild(ArticleLayerComponent);
  private readonly scroller = computed(() => this.layer()?.scroller());

  protected readonly progressRail = new ScrollProgressRail({
    scroller: this.scroller,
    contentBottom: this.scope.readingEnd,
    layout: this.scope.readingEnd,
  });

  // `tocOpen` lives here, not in the TOC, so it survives the reader/original swap.
  protected readonly toc = signal<TocEntry[]>([]);
  protected readonly tocOpen = signal(false);

  protected readonly body = computed(() => this.source.body(), { equal: sameBody });
  protected readonly readingMinutes = computed(() =>
    estimateReadingMinutes(this.body()?.html ?? ''),
  );

  constructor() {
    this.restore.connect(this.scroller);
    effect(() => {
      this.source.key();
      untracked(() => {
        this.toc.set([]);
        this.tocOpen.set(false);
      });
    });
    this.scope.connect({ scroller: this.scroller, content: this.content, trailing: this.trailing });
    this.scope.observeResizes();
    inject(ArticleSelection).connect(this.content);
  }

  protected scrollToTop(): void {
    this.restore.abort(); // don't let a restore fight the jump
    this.layer()?.scrollToTop(this.titleHeading()?.nativeElement);
  }

  protected scrollToHeading(id: string): void {
    this.scrollToElement(
      this.content()?.nativeElement.querySelector<HTMLElement>(`#${CSS.escape(id)}`),
    );
  }

  /** Scroll the reading pane to an element, clearing the chrome that covers the scroller. */
  scrollToElement(target: HTMLElement | null | undefined): void {
    const scroller = this.scroller();
    if (!scroller || !target) return;
    this.restore.abort(); // a jump takes over from any in-flight restore
    const top = scroller.toContentY(target.getBoundingClientRect().top);
    scroller.scrollTo({
      top: Math.max(0, top - scroller.scrollPaddingTop()),
      behavior: this.reduceMotion ? 'auto' : 'smooth',
    });
  }

  protected onDecorated(host: HTMLElement): void {
    this.toc.set(collectToc(host));
    this.scope.refresh();
    // Runs on the original render and again when the reader content swaps in.
    this.restore.reseat();
  }

  protected onScroll(scrollTop: number): void {
    if (!this.layer()?.leaving()) this.restore.remember(scrollTop);
  }

  private slotNamed(name: ArticlePageSlotName) {
    return computed(() => this.slots().find((slot) => slot.name() === name)?.template ?? null);
  }
}
