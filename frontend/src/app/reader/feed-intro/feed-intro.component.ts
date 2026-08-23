import { ChangeDetectionStrategy, Component, computed, input, signal } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { FaviconComponent } from '../../shared/favicon/favicon.component';
import { IconComponent } from '../../shared/icon/icon.component';

/**
 * What a feed says about itself: its own image, its description and a link to
 * its site. Shown once, at the top of the entry list, when the reader is
 * scoped to a single feed (#568).
 *
 * It is rendered through the list's `topBlock` outlet rather than inside the
 * list header, so it scrolls away with the rows. The header is sticky and
 * collapses on scroll; making it taller moves the rows under the finger (#419).
 */
@Component({
  selector: 'app-feed-intro',
  imports: [FaviconComponent, IconComponent, TranslocoPipe],
  templateUrl: './feed-intro.component.html',
  styleUrl: './feed-intro.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class FeedIntroComponent {
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

  /** Whether there is anything at all to show. The caller uses this to leave
   *  the block out entirely rather than render an empty box above the rows. */
  readonly hasContent = computed(
    () => this.description() !== null || this.imageUrl() !== null || this.siteUrl() !== null,
  );
}
