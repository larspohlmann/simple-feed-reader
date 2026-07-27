// src/app/discover/category-chips.component.ts
import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../shared/icon/icon.component';
import { CatalogCategoryDto } from './catalog.models';

/**
 * The rail, laid on its side, for viewports too narrow to carry it. Same state,
 * same jump behaviour — only the rendering differs, which is why ActiveCategory
 * lives outside both components. The leading tinted glyph matches the rail and
 * the reader sidebar.
 */
@Component({
  selector: 'app-category-chips',
  standalone: true,
  imports: [TranslocoPipe, IconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <nav class="chips" [attr.aria-label]="'discover.categories' | transloco">
      @for (category of categories(); track category.id) {
        <button
          type="button"
          [class.active]="category.id === activeId()"
          [attr.aria-current]="category.id === activeId() ? 'true' : null"
          (click)="jump.emit(category.id)"
        >
          <app-icon
            [name]="category.icon"
            [size]="16"
            [style.color]="
              category.id === activeId() ? 'currentColor' : category.color || 'var(--text-muted)'
            "
          />
          {{ category.name }}
          @if (picked()[category.id] > 0) {
            <span class="n">{{ picked()[category.id] }}</span>
          }
        </button>
      }
    </nav>
  `,
  styleUrl: './category-chips.component.scss',
})
export class CategoryChipsComponent {
  readonly categories = input.required<CatalogCategoryDto[]>();
  readonly activeId = input<number | null>(null);
  readonly picked = input.required<Record<number, number>>();
  readonly jump = output<number>();
}
