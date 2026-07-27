// src/app/discover/category-rail.component.ts
import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../shared/icon/icon.component';
import { CatalogCategoryDto } from './catalog.models';

/**
 * Desktop navigation for the picker. Two jobs: jump to a category, and show how
 * many feeds have been picked from each one so "what have I chosen so far" is
 * answerable without scrolling back.
 *
 * Navigation only — clicking a row never selects a feed. The leading tinted
 * glyph mirrors the reader sidebar's tag treatment so the two read as one app.
 */
@Component({
  selector: 'app-category-rail',
  standalone: true,
  imports: [TranslocoPipe, IconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <nav class="rail" [attr.aria-label]="'discover.categories' | transloco">
      <ul>
        @for (category of categories(); track category.id) {
          <li>
            <button
              type="button"
              [class.active]="category.id === activeId()"
              [attr.aria-current]="category.id === activeId() ? 'true' : null"
              (click)="jump.emit(category.id)"
            >
              <span class="lead">
                <app-icon
                  [name]="category.icon"
                  [size]="18"
                  [style.color]="category.color || 'var(--text-muted)'"
                />
              </span>
              <span class="name">{{ category.name }}</span>
              <span class="count" [class.picked]="picked()[category.id] > 0">
                {{ picked()[category.id] || category.feeds.length }}
              </span>
            </button>
          </li>
        }
      </ul>
    </nav>
  `,
  styles: `
    /* The host is the flex child of the scrolling body, so the stickiness must
       live here — a sticky <nav> inside a content-height host has no room to
       stick and scrolls away with it. Cap the height to the body and let the
       rail scroll internally when the categories outrun it. */
    :host {
      position: sticky;
      top: 0;
      display: block;
      align-self: flex-start;
      width: 220px;
      max-height: 100%;
      overflow-y: auto;
      border-right: 1px solid var(--border);
      background: var(--surface-1);
    }
    .rail {
      padding: var(--space-3) var(--space-2);
    }
    ul {
      display: flex;
      flex-direction: column;
      gap: 2px;
      margin: 0;
      padding: 0;
      list-style: none;
    }
    button {
      display: flex;
      gap: var(--space-2);
      align-items: center;
      width: 100%;
      padding: var(--space-2) var(--space-3);
      border: 0;
      border-radius: var(--radius);
      background: none;
      color: var(--text-secondary);
      font-size: var(--fs-sm);
      text-align: left;
      cursor: pointer;
    }
    button:hover {
      background: var(--surface-2);
      color: var(--text-primary);
    }
    button.active {
      background: var(--accent-soft);
      color: var(--text-primary);
      font-weight: 600;
    }
    .lead {
      display: inline-flex;
      flex: none;
      align-items: center;
      justify-content: center;
      width: 18px;
    }
    .name {
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .count {
      margin-left: auto;
      color: var(--text-muted);
      font-size: var(--fs-sm);
    }
    .count.picked {
      color: var(--accent);
      font-weight: 700;
    }
    @media (max-width: 800px) {
      :host {
        display: none;
      }
    }
  `,
})
export class CategoryRailComponent {
  readonly categories = input.required<CatalogCategoryDto[]>();
  readonly activeId = input<number | null>(null);
  /** categoryId -> how many of its feeds are picked. */
  readonly picked = input.required<Record<number, number>>();
  readonly jump = output<number>();
}
