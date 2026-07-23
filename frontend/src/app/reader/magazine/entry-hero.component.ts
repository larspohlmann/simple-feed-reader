// src/app/reader/magazine/entry-hero.component.ts
import { Component, computed, effect, input, output, signal } from '@angular/core';
import { IconComponent } from '../../shared/icon/icon.component';
import { EntryDto } from '../models';
import { firstPreviewImage, textSnippet } from '../preview-image';
import { relativeTime } from '../format';

@Component({
  selector: 'app-entry-hero',
  imports: [IconComponent],
  template: `
    <article
      class="hero"
      role="button"
      tabindex="0"
      [class.read]="entry().isRead"
      (click)="open.emit(entry())"
      (keydown.enter)="open.emit(entry())"
      (keydown.space)="$event.preventDefault(); open.emit(entry())"
    >
      @if (showImage()) {
        <img
          class="img"
          [src]="image()!"
          alt=""
          loading="lazy"
          decoding="async"
          referrerpolicy="no-referrer"
          (load)="onLoad($event)"
          (error)="imgError.set(true)"
        />
      }
      <div class="body">
        <p class="kicker">
          <span class="dot" [class.on]="!entry().isRead" aria-hidden="true"></span>
          {{ entry().source }} · {{ when() }}
        </p>
        <h3 class="title">{{ entry().title }}</h3>
        @if (snippet()) {
          <p class="dek">{{ snippet() }}</p>
        }
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
    </article>
  `,
  styles: [
    `
      :host {
        display: block;
      }
      .hero {
        background: var(--surface-2);
        border: 1px solid var(--border);
        border-radius: 12px;
        overflow: hidden;
        cursor: pointer;
      }
      .hero:hover {
        border-color: var(--border-strong);
      }
      .img {
        width: 100%;
        aspect-ratio: 16 / 9;
        object-fit: cover;
        display: block;
      }
      .body {
        padding: var(--space-3) var(--space-4);
        display: flex;
        flex-direction: column;
        gap: var(--space-2);
      }
      .kicker {
        margin: 0;
        display: flex;
        align-items: center;
        gap: var(--space-1);
        font-size: var(--fs-sm);
        color: var(--text-muted);
      }
      .dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        border: 1px solid var(--border-strong);
      }
      .dot.on {
        background: var(--accent);
        border-color: var(--accent);
      }
      .title {
        margin: 0;
        font-size: var(--fs-xl);
        font-weight: 500;
        line-height: 1.3;
        color: var(--text-primary);
      }
      .hero.read .title {
        color: var(--text-secondary);
        font-weight: 400;
      }
      .dek {
        margin: 0;
        color: var(--text-secondary);
        display: -webkit-box;
        -webkit-line-clamp: 3;
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
    `,
  ],
})
export class EntryHeroComponent {
  readonly entry = input.required<EntryDto>();
  readonly favorite = output<EntryDto>();
  readonly keep = output<EntryDto>();
  readonly read = output<EntryDto>();
  readonly open = output<EntryDto>();

  readonly imgError = signal(false);
  readonly tooSmall = signal(false);
  readonly image = computed(() =>
    firstPreviewImage(this.entry().contentHtml, this.entry().summary),
  );
  readonly showImage = computed(() => !!this.image() && !this.imgError() && !this.tooSmall());
  readonly snippet = computed(() => textSnippet(this.entry().summary || this.entry().contentHtml));
  readonly when = computed(() => relativeTime(this.entry().publishedAt ?? this.entry().createdAt));

  onLoad(ev: Event): void {
    const img = ev.target as HTMLImageElement;
    if (img.naturalWidth && img.naturalWidth < 200) this.tooSmall.set(true);
  }

  // Reset the gates when the host reuses this component for a different entry.
  private readonly _reset = effect(() => {
    this.entry();
    this.imgError.set(false);
    this.tooSmall.set(false);
  });
}
