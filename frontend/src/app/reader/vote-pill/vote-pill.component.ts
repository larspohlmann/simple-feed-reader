import { ChangeDetectionStrategy, Component, computed, inject, input, output } from '@angular/core';
import { NgTemplateOutlet } from '@angular/common';
import { TranslocoPipe } from '@jsverse/transloco';
import { CompactCount } from '../../core/i18n/compact-count';
import { pluralKey } from '../../core/i18n/plural-key';
import { IconComponent } from '../../shared/icon/icon.component';
import { VoteValue, nextVote } from './vote';

@Component({
  selector: 'app-vote-pill',
  imports: [IconComponent, NgTemplateOutlet, TranslocoPipe],
  templateUrl: './vote-pill.component.html',
  styleUrl: './vote-pill.component.scss',
  host: { '[class.bare]': "variant() === 'bare'" },
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class VotePillComponent {
  readonly score = input.required<number>();
  readonly variant = input<'framed' | 'bare'>('framed');
  readonly myVote = input<VoteValue | null>(null);
  readonly voted = output<VoteValue>();

  private readonly compactCount = inject(CompactCount);

  protected readonly compactScore = computed(() => this.compactCount.format(this.score()));
  protected readonly pointsKey = computed(() =>
    pluralKey('reader.discussionCounts.points', this.score()),
  );

  protected press(pressed: 'up' | 'down', event: Event): void {
    event.stopPropagation();
    const current = this.myVote();
    if (current !== null) this.voted.emit(nextVote(current, pressed));
  }
}
