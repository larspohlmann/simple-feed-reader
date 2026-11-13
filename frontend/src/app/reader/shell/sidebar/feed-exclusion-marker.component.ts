import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { TranslocoService } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { ActiveDictionary } from '../../../core/i18n/active-dictionary';
import { ExclusionSurface, exclusionTitle } from '../../feed-exclusions';

/** The eye badge on a hidden row's lead, naming the surfaces it is left out of. */
@Component({
  selector: 'app-feed-exclusion-marker',
  imports: [IconComponent],
  template: '<app-icon name="visibility_off" size="text" />',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: {
    class: 'feed-exclusion-marker',
    role: 'img',
    '[attr.aria-label]': 'title()',
    '[title]': 'title()',
  },
})
export class FeedExclusionMarkerComponent {
  readonly surfaces = input.required<readonly ExclusionSurface[]>();

  private readonly i18n = inject(TranslocoService);
  private readonly dictionary = inject(ActiveDictionary);

  protected readonly title = computed(() => {
    this.dictionary.loaded();
    return exclusionTitle(this.i18n, this.surfaces());
  });
}
