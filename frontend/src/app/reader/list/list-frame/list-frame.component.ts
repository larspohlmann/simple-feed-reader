import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  NgZone,
  effect,
  inject,
  input,
  output,
  viewChild,
} from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { Problem } from '../../../core/problem';
import { ErrorBannerComponent } from '../../../shared/error-banner/error-banner.component';
import { publishListBarHeight } from '../list-bar-height';

/** A list's chrome held over its column — floating header, error banner, corner frame —
 *  with the rows projected between. */
@Component({
  selector: 'app-list-frame',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslocoPipe, ErrorBannerComponent],
  templateUrl: './list-frame.component.html',
  styleUrl: './list-frame.component.scss',
})
export class ListFrameComponent {
  readonly problem = input<Problem | null>(null);
  readonly collapsed = input(false);
  readonly retry = output<void>();
  readonly dismiss = output<void>();

  readonly header = viewChild.required<ElementRef<HTMLElement>>('header');

  private readonly host: HTMLElement = inject(ElementRef).nativeElement;
  private readonly zone = inject(NgZone);

  /** Only measured while expanded: the collapsed height would shrink the rows'
   *  reservation and bring back the jump this replaces (#87). */
  private readonly _publishBarHeight = effect((onCleanup) => {
    const header = this.header().nativeElement;
    onCleanup(
      publishListBarHeight(this.zone, {
        host: this.host,
        header,
        isPublishing: () => !this.collapsed(),
      }),
    );
  });
}
