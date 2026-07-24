import { Component, computed, input, signal } from '@angular/core';
import { IconComponent } from '../icon/icon.component';

/**
 * A tiny site favicon shown in front of a source name. Falls back to the rss
 * glyph when the feed has no resolved favicon or the image fails to load
 * (dead icon URL, 404). `referrerpolicy=no-referrer` keeps the app's URL out
 * of the request to the third-party icon host.
 */
@Component({
  selector: 'app-favicon',
  imports: [IconComponent],
  templateUrl: './favicon.component.html',
  styleUrl: './favicon.component.scss',
})
export class FaviconComponent {
  readonly url = input<string | null>(null);
  readonly size = input(16);

  protected readonly broken = signal(false);
  protected readonly src = computed(() => (this.broken() ? null : this.url()));
}
