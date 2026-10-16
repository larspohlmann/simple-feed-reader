import { ChangeDetectionStrategy, Component, computed, input, linkedSignal } from '@angular/core';
import { ProxiedImageDirective } from '../proxied-image/proxied-image.directive';
import { avatarToneOf } from '../avatar-tone';
import { initialsOf } from '../initials';

export type AvatarSize = 'sm' | 'md';

@Component({
  selector: 'app-avatar',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ProxiedImageDirective],
  templateUrl: './avatar.component.html',
  styleUrl: './avatar.component.scss',
  host: {
    '[class.md]': "size() === 'md'",
    '[class.neutral]': 'toneSeed() === null',
  },
})
export class AvatarComponent {
  readonly url = input<string | null>(null);
  readonly name = input.required<string>();
  /** Colours the initials; without a seed they stay neutral. */
  readonly toneSeed = input<string | null>(null);
  readonly size = input<AvatarSize>('sm');

  protected readonly failed = linkedSignal({ source: this.url, computation: () => false });
  protected readonly initials = computed(() => initialsOf(this.name()));
  protected readonly toneClass = computed(() => {
    const seed = this.toneSeed();
    return seed === null ? '' : `tone-${avatarToneOf(seed)}`;
  });
}
