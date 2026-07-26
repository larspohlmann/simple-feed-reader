// src/app/discover/category-rail.component.ts
import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { CatalogCategoryDto } from './catalog.models';

/**
 * Desktop navigation for the picker. Two jobs: jump to a category, and show how
 * many feeds have been picked from each one so "what have I chosen so far" is
 * answerable without scrolling back.
 *
 * Navigation only — clicking a row never selects a feed.
 */
@Component({
  selector: 'app-category-rail',
  standalone: true,
  imports: [TranslocoPipe],
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
              <span class="dot" [style.background]="category.color"></span>
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
    .rail {
      position: sticky;
      top: 0;
      align-self: start;
      width: 200px;
      padding: var(--space-2) 0;
      border-right: 1px solid var(--border);
      background: var(--surface-1);
    }
    ul {
      margin: 0;
      padding: 0;
      list-style: none;
    }
    button {
      display: flex;
      gap: var(--space-2);
      align-items: center;
      width: 100%;
      padding: var(--space-1) var(--space-3);
      border: 0;
      background: none;
      color: var(--text-secondary);
      font-size: var(--fs-sm);
      text-align: left;
      cursor: pointer;
    }
    button.active {
      box-shadow: inset 2px 0 0 var(--accent);
      background: var(--accent-soft);
      color: var(--text-primary);
      font-weight: 600;
    }
    .dot {
      flex: none;
      width: 8px;
      height: 8px;
      border-radius: 3px;
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
      .rail {
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
