import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { LanguageService } from '../../../core/i18n/language.service';
import { formatLongDateTime, relativeTime } from '../../format';
import { StoryCard } from '../trending.models';

@Component({
  selector: 'app-story-dates',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslocoPipe],
  templateUrl: './story-dates.component.html',
  styleUrl: './story-dates.component.scss',
})
export class StoryDatesComponent {
  readonly story = input.required<Pick<StoryCard, 'createdAt' | 'lastAddedAt'>>();

  private readonly language = inject(LanguageService);
  protected readonly created = computed(() => this.describe(this.story().createdAt));
  protected readonly lastAdded = computed(() => this.describe(this.story().lastAddedAt));
  protected readonly hasGrown = computed(() => this.story().lastAddedAt !== this.story().createdAt);

  private describe(iso: string): { time: string; title: string } {
    const locale = this.language.lang();
    return { time: relativeTime(iso, locale), title: formatLongDateTime(iso, locale) };
  }
}
