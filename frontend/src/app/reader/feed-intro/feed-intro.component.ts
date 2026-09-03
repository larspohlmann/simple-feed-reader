import { ChangeDetectionStrategy, Component, computed, input, signal } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { FaviconComponent } from '../../shared/favicon/favicon.component';
import { IconComponent } from '../../shared/icon/icon.component';

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

  /** The homepage shown as its own address, not a generic word. The scheme and
   *  a bare trailing slash are dropped — same on every feed, so they cost width
   *  for nothing. The full URL stays on the link's title/href. */
  protected readonly homepageLabel = computed(() =>
    (this.siteUrl() ?? '').replace(/^https?:\/\//i, '').replace(/\/$/, ''),
  );
}
