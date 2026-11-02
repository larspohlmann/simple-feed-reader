import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { LayoutService } from '../../layout.service';
import { ArticleSelection } from './article-selection.service';
import { SelectionActionsComponent } from './selection-actions.component';

/** The selection's actions docked at the foot of the article on a coarse pointer, while a quote is selected. */
@Component({
  selector: 'app-selection-bar',
  imports: [TranslocoPipe, SelectionActionsComponent],
  templateUrl: './selection-bar.component.html',
  styleUrl: './selection-bar.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SelectionBarComponent {
  readonly url = input.required<string | null>();
  readonly title = input('');

  private readonly selection = inject(ArticleSelection);
  private readonly screen = inject(LayoutService);
  protected readonly quote = computed(() =>
    this.screen.isCoarse() && this.url() !== null ? this.selection.quote() : null,
  );
}
