import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  effect,
  inject,
  input,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { CdkTrapFocus } from '@angular/cdk/a11y';
import { CdkConnectedOverlay, CdkOverlayOrigin, ConnectedPosition } from '@angular/cdk/overlay';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import {
  ACTION_SHEET_DIVIDER,
  ActionSheetItem,
} from '../../../shared/action-sheet/action-sheet.component';
import { ActionSheet } from '../../../shared/action-sheet/action-sheet.service';
import { IconComponent } from '../../../shared/icon/icon.component';
import { ListActionDirective } from '../../../shared/list-action/list-action.directive';
import { MaskedLogoComponent } from '../../../shared/masked-logo/masked-logo.component';
import { CONFIRMATION_DURATION_MS, ToastService } from '../../../shared/toast/toast.service';
import { stepMenuFocus } from '../../../shared/menu-keys';
import { LayoutService } from '../../layout.service';
import { SHARE_GLYPH, SHARE_TARGETS, ShareTarget, shareLogoUrl, shareUrl } from './share-targets';
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
    MaskedLogoComponent,
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
  private readonly screen = inject(LayoutService);
  private readonly sheet = inject(ActionSheet);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly glyph = SHARE_GLYPH;
  protected readonly positions = POSITIONS;
  protected readonly targets = SHARE_TARGETS;
  protected readonly logoUrl = shareLogoUrl;
  protected readonly open = signal(false);

  private readonly targetsById = new Map(
    this.targets.flatMap((target, index) =>
      target.kind === 'divider' ? [] : [[String(index), target] as const],
    ),
  );

  constructor() {
    effect(() => {
      this.url();
      this.open.set(false);
    });
  }

  protected press(): void {
    if (this.screen.isCoarse()) this.openSheet();
    else this.open.update((open) => !open);
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

  private openSheet(): void {
    this.sheet
      .open({
        title: this.i18n.translate('reader.share.button'),
        actions: this.targets.map((target, index) => this.sheetItem(target, index)),
      })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe((choice) => this.chooseById(choice));
  }

  private sheetItem(target: ShareTarget, index: number): ActionSheetItem {
    if (target.kind === 'divider') return ACTION_SHEET_DIVIDER;
    return {
      id: String(index),
      label: target.labelKey ? this.i18n.translate(target.labelKey) : (target.label ?? ''),
      symbol: target.icon ? undefined : (target.symbol ?? this.glyph),
      logo: target.icon ? { url: shareLogoUrl(target.icon), color: target.color } : undefined,
    };
  }

  private chooseById(choice: string | undefined): void {
    const target = choice === undefined ? undefined : this.targetsById.get(choice);
    if (target !== undefined) this.choose(target);
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
