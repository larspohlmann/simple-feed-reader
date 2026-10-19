import { ChangeDetectionStrategy, Component, effect, inject, input, signal } from '@angular/core';
import { CdkTrapFocus } from '@angular/cdk/a11y';
import { CdkConnectedOverlay, CdkOverlayOrigin, ConnectedPosition } from '@angular/cdk/overlay';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { ListActionDirective } from '../../../shared/list-action/list-action.directive';
import { CONFIRMATION_DURATION_MS, ToastService } from '../../../shared/toast/toast.service';
import { stepMenuFocus } from '../../../shared/menu-keys';
import { SHARE_GLYPH, SHARE_TARGETS, ShareTarget, shareUrl } from './share-targets';
import { stripTracking } from './tracking-params';

const POSITIONS: ConnectedPosition[] = [
  { originX: 'end', originY: 'bottom', overlayX: 'end', overlayY: 'top', offsetY: 6 },
  { originX: 'end', originY: 'top', overlayX: 'end', overlayY: 'bottom', offsetY: -6 },
];

@Component({
  selector: 'app-share-button',
  imports: [
    TranslocoPipe,
    IconComponent,
    ListActionDirective,
    CdkConnectedOverlay,
    CdkOverlayOrigin,
    CdkTrapFocus,
  ],
  templateUrl: './share-button.component.html',
  styleUrl: './share-button.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ShareButtonComponent {
  readonly url = input.required<string>();
  readonly title = input('');

  private readonly toast = inject(ToastService);
  private readonly i18n = inject(TranslocoService);

  protected readonly glyph = SHARE_GLYPH;
  protected readonly positions = POSITIONS;
  protected readonly targets = SHARE_TARGETS;
  protected readonly open = signal(false);

  constructor() {
    effect(() => {
      this.url();
      this.open.set(false);
    });
  }

  /** Runs inside the click, so Safari still sees the gesture that share and clipboard need. */
  protected choose(target: Exclude<ShareTarget, { kind: 'divider' }>): void {
    this.open.set(false);
    const url = stripTracking(this.url());
    if (target.kind === 'system') this.shareThroughSystem(url);
    if (target.kind === 'copy') this.copy(url);
    if (target.kind === 'link') this.visit(shareUrl(target.url, url, this.title()));
  }

  protected step(event: KeyboardEvent): void {
    const menu = (event.target as Element).closest('[role="menu"]');
    if (menu !== null) stepMenuFocus(event, menu);
  }

  private shareThroughSystem(url: string): void {
    navigator.share({ title: this.title(), url }).catch((error: unknown) => {
      if (error instanceof DOMException && error.name === 'AbortError') return;
      this.notify('reader.share.failed');
    });
  }

  private copy(url: string): void {
    (navigator.clipboard?.writeText(url) ?? Promise.reject()).then(
      () => this.notify('reader.share.copied', CONFIRMATION_DURATION_MS),
      () => this.notify('reader.share.copyFailed'),
    );
  }

  /** mailto: hands off to another program; a new tab for it would stay blank. */
  private visit(address: string): void {
    if (/^https?:/i.test(address)) window.open(address, '_blank', 'noopener,noreferrer');
    else window.location.assign(address);
  }

  private notify(key: string, durationMs?: number): void {
    this.toast.show({ message: this.i18n.translate(key), durationMs });
  }
}
