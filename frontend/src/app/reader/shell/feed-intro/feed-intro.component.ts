import {
  afterRenderEffect,
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  ElementRef,
  inject,
  input,
  linkedSignal,
  NgZone,
  signal,
  viewChild,
} from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { FaviconComponent } from '../../../shared/favicon/favicon.component';
import { IconComponent } from '../../../shared/icon/icon.component';
import { observeResize } from '../../../shared/observe-resize';

const CLAMPED_LINES = 2;

/**
 * What a feed says about itself: its own image, description, and a link to its
 * site. Shown once, at the top of the entry list, for a single-feed view (#568).
 *
 * Rendered through the list's `topBlock` outlet, not the list header, so it
 * scrolls away with the rows — the header is sticky, and making it taller
 * would move rows under the finger on scroll (#419).
 */
@Component({
  selector: 'app-feed-intro',
  imports: [FaviconComponent, IconComponent, TranslocoPipe],
  templateUrl: './feed-intro.component.html',
  styleUrl: './feed-intro.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class FeedIntroComponent {
  private readonly zone = inject(NgZone);

  /** The feed's name as the sidebar shows it, custom title included. Repeated
   *  from the list header on purpose: with the logo beside it the block reads
   *  as the feed's masthead rather than as a stray paragraph. */
  readonly title = input<string | null>(null);
  readonly description = input<string | null>(null);
  /** The image the feed publishes for itself. Plain https URL, or null. */
  readonly imageUrl = input<string | null>(null);
  /** The site's icon, resolved from the page rather than read from the feed.
   *  Only 8% of feeds publish an image of their own, so without this stand-in
   *  the block would sit next to empty space for nearly all of them. */
  readonly faviconUrl = input<string | null>(null);
  readonly siteUrl = input<string | null>(null);

  /** A dead logo URL must leave no broken-image box, so the <img> is dropped
   *  rather than left to render its own failure. Mirrors app-favicon. */
  protected readonly broken = signal(false);
  /** The feed's own image, while it still loads. Null drops the template to the
   *  favicon branch, so a dead logo URL degrades to the site icon rather than
   *  to nothing. */
  protected readonly logoUrl = computed(() => (this.broken() ? null : this.imageUrl()));

  private readonly text = viewChild<ElementRef<HTMLElement>>('text');
  private readonly blurbLines = viewChild<ElementRef<HTMLElement>>('blurbLines');
  private readonly lineCount = signal(0);
  protected readonly long = computed(() => this.lineCount() > CLAMPED_LINES);
  protected readonly expanded = linkedSignal({
    source: this.description,
    computation: () => false,
  });
  protected readonly clamped = computed(() => this.long() && !this.expanded());

  constructor() {
    afterRenderEffect(() => {
      this.description();
      this.countLines();
    });
    effect((onCleanup) => {
      const text = this.text()?.nativeElement;
      if (text) onCleanup(observeResize(this.zone, [text], () => this.countLines()));
    });
  }

  /** An inline box has one client rect per line it wraps onto, clamped or not,
   *  so the count holds still while the clamp toggles. */
  private countLines(): void {
    this.lineCount.set(this.blurbLines()?.nativeElement.getClientRects().length ?? 0);
  }
}
