import { A11yModule } from '@angular/cdk/a11y';
import { CdkConnectedOverlay, CdkOverlayOrigin, ConnectedPosition } from '@angular/cdk/overlay';
import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  computed,
  input,
  output,
  signal,
  viewChild,
} from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { MENU_VIEWPORT_MARGIN } from '../../../shared/menu-keys';

export interface FeedDirectoryFilterPillOption<T extends string = string> {
  readonly value: T;
  readonly label?: string;
  readonly labelKey?: string;
  readonly count?: string;
  readonly selected: boolean;
}

export type FeedDirectoryFilterPillChoice = 'one' | 'many';

const UNDER_THE_PILL: ConnectedPosition[] = [
  { originX: 'start', originY: 'bottom', overlayX: 'start', overlayY: 'top', offsetY: 6 },
  { originX: 'start', originY: 'top', overlayX: 'start', overlayY: 'bottom', offsetY: -6 },
];

@Component({
  selector: 'app-feed-directory-filter-pill',
  imports: [A11yModule, CdkConnectedOverlay, CdkOverlayOrigin, IconComponent, TranslocoPipe],
  templateUrl: './feed-directory-filter-pill.component.html',
  styleUrl: './feed-directory-filter-pill.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class FeedDirectoryFilterPillComponent<T extends string> {
  readonly label = input.required<string>();
  readonly menuLabel = input.required<string>();
  readonly options = input.required<readonly FeedDirectoryFilterPillOption<T>[]>();
  readonly choice = input<FeedDirectoryFilterPillChoice>('many');
  readonly icon = input('expand_more');
  readonly count = input(0);
  readonly active = input(false);
  readonly choose = output<T>();

  protected readonly open = signal(false);
  protected readonly caption = computed(() =>
    this.count() > 0 ? `${this.label()} · ${this.count()}` : this.label(),
  );
  protected readonly positions = UNDER_THE_PILL;
  protected readonly viewportMargin = MENU_VIEWPORT_MARGIN;
  private readonly pill = viewChild.required(CdkOverlayOrigin);
  private readonly overlay = viewChild.required(CdkConnectedOverlay);

  protected focusFirstOption(): void {
    this.overlay()
      .overlayRef.overlayElement.querySelector<HTMLElement>('.option input, button.option')
      ?.focus({ preventScroll: true });
  }

  protected closed(): void {
    this.open.set(false);
    (this.pill().elementRef as ElementRef<HTMLElement>).nativeElement.focus();
  }

  protected pick(value: T): void {
    this.choose.emit(value);
    if (this.choice() === 'one') this.open.set(false);
  }
}
