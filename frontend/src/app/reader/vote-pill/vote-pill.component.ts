import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { Observable } from 'rxjs';
import { PendingAction } from '../../core/rx/pending-action';
import { NgTemplateOutlet } from '@angular/common';
import { TranslocoPipe } from '@jsverse/transloco';
import { CompactCount } from '../../core/i18n/compact-count';
import { pluralKey } from '../../core/i18n/plural-key';
import { IconComponent } from '../../shared/icon/icon.component';
import { PendingDotsComponent } from '../../shared/pending-dots/pending-dots.component';
import { VoteValue, nextVote } from './vote';

@Component({
  selector: 'app-vote-pill',
  imports: [IconComponent, NgTemplateOutlet, PendingDotsComponent, TranslocoPipe],
  templateUrl: './vote-pill.component.html',
  styleUrl: './vote-pill.component.scss',
  host: { '[class.bare]': "variant() === 'bare'" },
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class VotePillComponent {
  readonly score = input.required<number>();
  /** `framed` takes its height from `--vote-pill-height` and its colour from where it sits. */
  readonly variant = input<'framed' | 'bare'>('framed');
  readonly myVote = input<VoteValue | null>(null);
  /** Casts the vote; the tapped arrow shows the pending dots until what it returns settles. */
  readonly vote = input<((to: VoteValue) => Observable<unknown>) | null>(null);
  protected readonly voting = new PendingAction<'up' | 'down'>();

  private readonly compactCount = inject(CompactCount);

  protected readonly compactScore = computed(() => this.compactCount.format(this.score()));
  protected readonly pointsKey = computed(() =>
    pluralKey('reader.discussionCounts.points', this.score()),
  );

  protected press(pressed: 'up' | 'down', event: Event): void {
    event.stopPropagation();
    const current = this.myVote();
    const vote = this.vote();
    if (current === null || vote === null) return;
    this.voting.run(pressed, () => vote(nextVote(current, pressed)));
  }
}
