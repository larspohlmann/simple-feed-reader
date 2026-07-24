// src/app/reader/entry-row/entry-row.component.ts
import { Component, computed, effect, input, output, signal } from '@angular/core';
import { IconComponent } from '../../shared/icon/icon.component';
import { FaviconComponent } from '../../shared/favicon/favicon.component';
import { EntryDto } from '../models';
import { firstPreviewImage, textSnippet } from '../preview-image';
import { relativeTime } from '../format';

@Component({
  selector: 'app-entry-row',
  imports: [IconComponent, FaviconComponent],
  template: `
    <article
      class="row"
      role="button"
      tabindex="0"
      [class.read]="entry().isRead"
      [class.img-left]="imageSide() === 'left'"
      (click)="open.emit(entry())"
      (keydown.enter)="open.emit(entry())"
      (keydown.space)="$event.preventDefault(); open.emit(entry())"
    >
      <span class="dot" [class.on]="!entry().isRead" aria-hidden="true"></span>
      <div class="body">
        <h3 class="title">{{ entry().title }}</h3>
        <p class="meta">
          <app-favicon [url]="entry().faviconUrl" [size]="14" />{{ entry().source }} · {{ when() }}
        </p>
        <p class="snippet">{{ snippet() }}</p>
        <div class="actions">
          <button
            type="button"
            aria-label="Favorite"
            [class.on]="entry().isFavorite"
            [attr.aria-pressed]="entry().isFavorite"
            (click)="$event.stopPropagation(); favorite.emit(entry())"
          >
            <app-icon name="star" [size]="18" />
          </button>
          <button
            type="button"
            aria-label="Keep"
            [class.on]="entry().isKept"
            [attr.aria-pressed]="entry().isKept"
            (click)="$event.stopPropagation(); keep.emit(entry())"
          >
            <app-icon name="bookmark" [size]="18" />
          </button>
          <button
            type="button"
            aria-label="Toggle read"
            [attr.aria-pressed]="entry().isRead"
            (click)="$event.stopPropagation(); read.emit(entry())"
          >
            <app-icon [name]="entry().isRead ? 'mark_email_unread' : 'check'" [size]="18" />
          </button>
        </div>
      </div>
      @if (image() && !imgError()) {
        <img
          class="thumb"
          [src]="image()!"
          alt=""
          loading="lazy"
          decoding="async"
          referrerpolicy="no-referrer"
          (error)="imgError.set(true)"
        />
      }
    </article>
  `,
  styles: [
    `
      .row {
        display: flex;
        gap: var(--space-3);
        padding: var(--space-3) var(--space-4);
        border-bottom: 1px solid var(--border);
        cursor: pointer;
      }
      .row:hover {
        background: var(--surface-0);
      }
      .dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        margin-top: 6px;
        flex: 0 0 auto;
        border: 1px solid var(--border-strong);
      }
      .dot.on {
        background: var(--accent);
        border-color: var(--accent);
      }
      .body {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: var(--space-1);
      }
      .title {
        margin: 0;
        font-size: var(--fs-base);
        font-weight: 500;
        color: var(--text-primary);
      }
      .row.read .title {
        font-weight: 400;
        color: var(--text-secondary);
      }
      .meta {
        margin: 0;
        font-size: var(--fs-sm);
        color: var(--text-muted);
      }
      .snippet {
        margin: 0;
        font-size: var(--fs-sm);
        color: var(--text-secondary);
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
      }
      .actions {
        display: flex;
        gap: var(--space-3);
      }
      .actions button {
        background: none;
        border: none;
        color: var(--text-muted);
        cursor: pointer;
        padding: 2px;
      }
      .actions button.on {
        color: var(--accent);
      }
      .thumb {
        width: 88px;
        height: 66px;
        object-fit: cover;
        border-radius: var(--radius);
        flex: 0 0 auto;
      }
      .row.img-left .thumb {
        order: -1;
      }
    `,
  ],
})
export class EntryRowComponent {
  readonly entry = input.required<EntryDto>();
  readonly imageSide = input<'left' | 'right'>('right');
  readonly favorite = output<EntryDto>();
  readonly keep = output<EntryDto>();
  readonly read = output<EntryDto>();
  readonly open = output<EntryDto>();

  readonly imgError = signal(false);
  readonly image = computed(() =>
    firstPreviewImage(this.entry().contentHtml, this.entry().summary),
  );
  readonly snippet = computed(() =>
    this.entry().summary
      ? textSnippet(this.entry().summary)
      : textSnippet(this.entry().contentHtml),
  );
  readonly when = computed(() => relativeTime(this.entry().publishedAt ?? this.entry().createdAt));

  // Reset the failed-image flag whenever the row is reused for a different entry.
  private readonly _resetOnEntryChange = effect(() => {
    this.entry();
    this.imgError.set(false);
  });
}
