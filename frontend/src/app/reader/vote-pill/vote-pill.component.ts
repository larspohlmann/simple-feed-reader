import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { CompactCount } from '../../core/i18n/compact-count';
import { pluralKey } from '../../core/i18n/plural-key';
import { IconComponent } from '../../shared/icon/icon.component';

@Component({
  selector: 'app-vote-pill',
  imports: [IconComponent, TranslocoPipe],
  templateUrl: './vote-pill.component.html',
  styleUrl: './vote-pill.component.scss',
  host: { '[class.bare]': "variant() === 'bare'" },
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class VotePillComponent {
  readonly score = input.required<number>();
  readonly variant = input<'framed' | 'bare'>('framed');

  private readonly compactCount = inject(CompactCount);

  protected readonly compactScore = computed(() => this.compactCount.format(this.score()));
  protected readonly pointsKey = computed(() =>
    pluralKey('reader.discussionCounts.points', this.score()),
  );
}
