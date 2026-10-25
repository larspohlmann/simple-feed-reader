import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { pluralKey } from '../../../core/i18n/plural-key';

const MAX_SHOWN_SUBTAGS = 9;

/** A tag row's subtag count, a pill after the tag name; hidden when the tag has none. */
@Component({
  selector: 'app-sidebar-subtag-pill',
  imports: [TranslocoPipe],
  templateUrl: './sidebar-subtag-pill.component.html',
  styleUrl: './sidebar-subtag-pill.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { '[hidden]': 'count() === 0' },
})
export class SidebarSubtagPillComponent {
  readonly count = input.required<number>();

  protected readonly pluralKey = pluralKey;

  protected readonly label = computed(() => {
    const count = this.count();
    return count > MAX_SHOWN_SUBTAGS ? `${MAX_SHOWN_SUBTAGS}+` : String(count);
  });
}
