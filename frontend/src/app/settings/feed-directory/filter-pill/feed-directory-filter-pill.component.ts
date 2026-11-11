import { CdkConnectedOverlay, CdkOverlayOrigin, ConnectedPosition } from '@angular/cdk/overlay';
import { ChangeDetectionStrategy, Component, computed, input, output, signal } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';

export interface FilterPillOption<T extends string = string> {
  readonly value: T;
  readonly label?: string;
  readonly labelKey?: string;
  readonly count?: string;
  readonly selected: boolean;
}

export type FilterPillChoice = 'one' | 'many';

const UNDER_THE_PILL: ConnectedPosition[] = [
  { originX: 'start', originY: 'bottom', overlayX: 'start', overlayY: 'top', offsetY: 6 },
  { originX: 'start', originY: 'top', overlayX: 'start', overlayY: 'bottom', offsetY: -6 },
];

@Component({
  selector: 'app-feed-directory-filter-pill',
  imports: [CdkConnectedOverlay, CdkOverlayOrigin, IconComponent, TranslocoPipe],
  templateUrl: './feed-directory-filter-pill.component.html',
  styleUrl: './feed-directory-filter-pill.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class FeedDirectoryFilterPillComponent<T extends string> {
  readonly label = input.required<string>();
  readonly menuLabel = input.required<string>();
  readonly options = input.required<readonly FilterPillOption<T>[]>();
  readonly choice = input<FilterPillChoice>('many');
  readonly icon = input('expand_more');
  readonly count = input(0);
  readonly active = input(false);
  readonly choose = output<T>();

  protected readonly open = signal(false);
  protected readonly caption = computed(() =>
    this.count() > 0 ? `${this.label()} · ${this.count()}` : this.label(),
  );
  protected readonly positions = UNDER_THE_PILL;

  protected pick(value: T): void {
    this.choose.emit(value);
    if (this.choice() === 'one') this.open.set(false);
  }
}
