import {
  Component,
  ElementRef,
  computed,
  effect,
  inject,
  input,
  output,
  signal,
  viewChild,
} from '@angular/core';
import { ProgressRailComponent } from '../../../shared/progress-rail/progress-rail.component';
import { ScrollProgressDirective } from '../../../shared/progress-rail/scroll-progress.directive';
import { ScrollProgressRail } from '../../../shared/progress-rail/scroll-progress-rail';
import { IconComponent } from '../../../shared/icon/icon.component';
import { SpinnerComponent } from '../../../shared/spinner/spinner.component';
import {
  BACK_TO_TOP_AFTER_PX,
  ToTopButtonComponent,
} from '../../../shared/to-top-button/to-top-button.component';
import { nextHeaderHidden } from '../../scroll/header-scroll';
import { ArticleGestures } from '../reader-view/article-gestures.service';

/** The reader's layer: its frame, hide-on-scroll toolbar, the one scroller, the
 *  progress rail, the to-top button and the return gestures. The article itself,
 *  the toolbar's actions and the nameplate are projected by the reader using it. */
@Component({
  selector: 'app-article-layer',
  imports: [
    ProgressRailComponent,
    ScrollProgressDirective,
    IconComponent,
    SpinnerComponent,
    ToTopButtonComponent,
  ],
  providers: [ArticleGestures],
  templateUrl: './article-layer.component.html',
  styleUrl: './article-layer.component.scss',
})
export class ArticleLayerComponent {
  /** Full-screen reading (the mobile overlay) vs. the split pane. The article
   *  is its own layer: its own hide-on-scroll toolbar, slide-out back button,
   *  and return gestures. The shell's app bar stays beneath, untouched (#128). */
  readonly fullscreen = input(false);
  readonly progressRail = input.required<ScrollProgressRail>();
  readonly withTail = input(false);
  readonly backLabel = input.required<string>();

  // Semantic "back to list" output; not a DOM element's close event.
  // eslint-disable-next-line @angular-eslint/no-output-native
  readonly close = output<void>();
  readonly scrolled = output<number>();
  readonly toTop = output<void>();
  readonly barResized = output<void>();

  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly scrollerRef = viewChild<ElementRef<HTMLElement>>('scroller');
  readonly scroller = computed(() => this.scrollerRef()?.nativeElement);
  private readonly bar = viewChild<ElementRef<HTMLElement>>('bar');
  protected readonly gestures = inject(ArticleGestures);
  readonly leaving = this.gestures.leaving.asReadonly();

  /** Back-to-top affordance: revealed once the reader has scrolled past a screen. */
  protected readonly showToTop = signal(false);

  /** Full-screen only: the toolbar retracts scrolling down and returns
   *  scrolling up, exactly like the list's app bar over the list — driven by
   *  this article's own scroller alone. */
  protected readonly toolbarHidden = signal(false);
  private lastToolbarScrollTop = 0;

  constructor() {
    this.gestures.connect({
      fullscreen: this.fullscreen,
      scroller: this.scroller,
      close: () => this.close.emit(),
    });
    this.reserveToolbarHeight();
  }

  /** The toolbar's back button. Full-screen it plays the same slide-out as a
   *  back-swipe rather than cutting straight to the list; the split pane has
   *  no overlay to slide, so it closes directly. */
  back(): void {
    if (this.fullscreen()) this.gestures.slideBack();
    else this.close.emit();
  }

  resetChrome(): void {
    this.showToTop.set(false);
    this.toolbarHidden.set(false);
    this.lastToolbarScrollTop = 0;
  }

  scrollToTop(behavior: ScrollBehavior): void {
    this.scroller()?.scrollTo({ top: 0, behavior });
  }

  protected onScroll(scrollTop: number): void {
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
    this.scrolled.emit(scrollTop);
  }

  /** The toolbar floats over the scroller, which reserves its height (#1332). */
  private reserveToolbarHeight(): void {
    effect((onCleanup) => {
      const bar = this.bar()?.nativeElement;
      if (!bar || typeof ResizeObserver === 'undefined') return;
      const style = this.host.nativeElement.style;
      const observer = new ResizeObserver(() => {
        style.setProperty('--reader-bar-h', `${bar.offsetHeight}px`);
        this.barResized.emit();
      });
      observer.observe(bar);
      onCleanup(() => observer.disconnect());
    });
  }
}
