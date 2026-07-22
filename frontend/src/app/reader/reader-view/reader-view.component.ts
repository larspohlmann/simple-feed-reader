// src/app/reader/reader-view/reader-view.component.ts
import { AfterViewChecked, Component, ElementRef, input, output, viewChild } from '@angular/core';
import { IconComponent } from '../../shared/icon/icon.component';
import { EntryDto } from '../models';
import { relativeTime } from '../format';

@Component({
  selector: 'app-reader-view',
  imports: [IconComponent],
  template: `
    @if (entry(); as e) {
      <div class="reader">
        <div class="bar">
          <button class="close" type="button" aria-label="Back to list" (click)="close.emit()">
            <app-icon name="arrow_back" [size]="20" />
          </button>
          <div class="nav">
            <button
              class="prev"
              type="button"
              aria-label="Previous"
              [disabled]="!hasPrev()"
              (click)="prev.emit()"
            >
              <app-icon name="chevron_left" [size]="20" />
            </button>
            <button
              class="next"
              type="button"
              aria-label="Next"
              [disabled]="!hasNext()"
              (click)="next.emit()"
            >
              <app-icon name="chevron_right" [size]="20" />
            </button>
          </div>
        </div>
        <article>
          <h1 class="title">{{ e.title }}</h1>
          <p class="meta">
            {{ e.source }}
            @if (e.author) {
              · {{ e.author }}
            }
            · {{ when(e) }}
            @if (e.url) {
              ·
              <a [href]="e.url" target="_blank" rel="noopener noreferrer"
                >Open original <app-icon name="open_in_new" [size]="14"
              /></a>
            }
          </p>
          <div class="actions">
            <button
              type="button"
              aria-label="Favorite"
              [class.on]="e.isFavorite"
              (click)="favorite.emit()"
            >
              <app-icon name="star" [size]="20" />
            </button>
            <button type="button" aria-label="Keep" [class.on]="e.isKept" (click)="keep.emit()">
              <app-icon name="bookmark" [size]="20" />
            </button>
            <button type="button" aria-label="Toggle read" (click)="read.emit()">
              <app-icon [name]="e.isRead ? 'mark_email_unread' : 'check'" [size]="20" />
            </button>
          </div>
          <div #content class="content" [innerHTML]="e.contentHtml"></div>
        </article>
      </div>
    } @else {
      <div class="placeholder"><p>Select an article to read.</p></div>
    }
  `,
  styles: [
    `
      :host {
        display: block;
        height: 100%;
        overflow: auto;
      }
      .bar {
        position: sticky;
        top: 0;
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: var(--space-2) var(--space-4);
        border-bottom: 1px solid var(--border);
        background: var(--surface-1);
      }
      .bar button,
      .actions button {
        background: none;
        border: none;
        color: var(--text-secondary);
        cursor: pointer;
        padding: var(--space-1);
      }
      .bar button:disabled {
        color: var(--text-muted);
        cursor: default;
      }
      article {
        max-width: 720px;
        margin: 0 auto;
        padding: var(--space-5) var(--space-4);
      }
      .title {
        font-size: var(--fs-xl);
        margin: 0 0 var(--space-2);
        color: var(--text-primary);
      }
      .meta {
        font-size: var(--fs-sm);
        color: var(--text-muted);
        margin: 0 0 var(--space-3);
      }
      .meta a {
        color: var(--accent);
        text-decoration: none;
      }
      .actions {
        display: flex;
        gap: var(--space-4);
        padding: var(--space-2) 0 var(--space-4);
        border-bottom: 1px solid var(--border);
        margin-bottom: var(--space-4);
      }
      .actions button.on {
        color: var(--accent);
      }
      .content {
        color: var(--text-primary);
        line-height: 1.7;
      }
      .content :is(img, video, iframe) {
        max-width: 100%;
        height: auto;
        border-radius: var(--radius);
      }
      .content a {
        color: var(--accent);
      }
      .placeholder {
        display: grid;
        place-items: center;
        height: 100%;
        color: var(--text-muted);
      }
    `,
  ],
})
export class ReaderViewComponent implements AfterViewChecked {
  readonly entry = input.required<EntryDto | null>();
  readonly hasPrev = input(false);
  readonly hasNext = input(false);

  readonly favorite = output<void>();
  readonly keep = output<void>();
  readonly read = output<void>();
  readonly prev = output<void>();
  readonly next = output<void>();
  // Semantic "back to list" output; not a DOM element's close event.
  // eslint-disable-next-line @angular-eslint/no-output-native
  readonly close = output<void>();

  private readonly content = viewChild<ElementRef<HTMLElement>>('content');

  when(e: EntryDto): string {
    return relativeTime(e.publishedAt ?? e.createdAt);
  }

  ngAfterViewChecked(): void {
    const host = this.content()?.nativeElement;
    if (!host) return;
    for (const a of Array.from(host.querySelectorAll('a'))) {
      if (a.target !== '_blank') {
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
      }
    }
  }
}
