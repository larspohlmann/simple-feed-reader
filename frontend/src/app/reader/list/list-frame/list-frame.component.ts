import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  inject,
  input,
  output,
  viewChild,
} from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { Problem } from '../../../core/problem';
import { ErrorBannerComponent } from '../../../shared/error-banner/error-banner.component';
import { PaneBarComponent } from '../../../shared/pane-bar/pane-bar.component';

/** A list's chrome held over its column — floating header, error banner, corner frame —
 *  with the rows projected between. */
@Component({
  selector: 'app-list-frame',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslocoPipe, ErrorBannerComponent, PaneBarComponent],
  templateUrl: './list-frame.component.html',
  styleUrl: './list-frame.component.scss',
})
export class ListFrameComponent {
  readonly problem = input<Problem | null>(null);
  readonly collapsed = input(false);
  readonly retry = output<void>();
  readonly dismiss = output<void>();

  readonly header = viewChild.required('header', { read: ElementRef<HTMLElement> });

  private readonly host: HTMLElement = inject(ElementRef).nativeElement;

  /** Only while expanded: the collapsed height would shrink the rows' reservation
   *  and bring back the jump this replaces (#87). */
  protected publishBarHeight(height: number): void {
    if (!this.collapsed()) this.host.style.setProperty('--list-bar-h', `${height}px`);
  }
}
