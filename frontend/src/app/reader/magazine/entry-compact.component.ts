// src/app/reader/magazine/entry-compact.component.ts
import { Component, computed, input, output } from '@angular/core';
import { EntryDto, SubscriptionTagDto } from '../models';
import { relativeTime } from '../format';
import { FaviconComponent } from '../../shared/favicon/favicon.component';
import { SourceTagsComponent } from '../source-tags/source-tags.component';

@Component({
  selector: 'app-entry-compact',
  imports: [FaviconComponent, SourceTagsComponent],
  template: `
    <article
      class="compact"
      role="button"
      tabindex="0"
      [class.read]="entry().isRead"
      (click)="open.emit(entry())"
      (keydown.enter)="open.emit(entry())"
      (keydown.space)="$event.preventDefault(); open.emit(entry())"
    >
      <span class="dot" [class.on]="!entry().isRead" aria-hidden="true"></span>
      <div class="body">
        <p class="kicker">
          @if (showSource()) {
            <app-favicon [url]="entry().faviconUrl" [size]="12" />{{ entry().source }} ·
          }
          {{ when() }}
        </p>
        <p class="title">{{ entry().title }}</p>
        @if (showSource()) {
          <app-source-tags [tags]="tags()" />
        }
      </div>
    </article>
  `,
  styles: [
    `
      :host {
        display: block;
      }
      .compact {
        display: flex;
        gap: var(--space-3);
        align-items: baseline;
        padding: var(--space-3) var(--space-4);
        cursor: pointer;
      }
      .compact:hover {
        background: var(--surface-0);
      }
      .dot {
        width: 7px;
        height: 7px;
        border-radius: 50%;
        flex: 0 0 auto;
        border: 1px solid var(--border-strong);
      }
      .dot.on {
        background: var(--accent);
        border-color: var(--accent);
      }
      .body {
        min-width: 0;
      }
      .kicker {
        margin: 0;
        font-size: var(--fs-sm);
        color: var(--text-muted);
      }
      .title {
        margin: 0;
        color: var(--text-primary);
        /* Never truncate the title, even in this small tile — let it wrap in full. */
        overflow-wrap: anywhere;
      }
      .compact.read .title {
        color: var(--text-secondary);
      }
    `,
  ],
})
export class EntryCompactComponent {
  readonly entry = input.required<EntryDto>();
  /** Hidden inside a source group, where the header already names the source
   *  and carries the tag pills — so the per-item pills are suppressed too. */
  readonly showSource = input(true);
  readonly tags = input<SubscriptionTagDto[]>([]);
  readonly open = output<EntryDto>();
  readonly when = computed(() => relativeTime(this.entry().publishedAt ?? this.entry().createdAt));
}
