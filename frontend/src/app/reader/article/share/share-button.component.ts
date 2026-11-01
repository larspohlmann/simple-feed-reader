import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  computed,
  effect,
  inject,
  input,
  signal,
} from '@angular/core';
import { takeUntilDestroyed, toSignal } from '@angular/core/rxjs-interop';
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
import { NetworkLogoComponent } from '../../../shared/network-logo/network-logo.component';
import { CONFIRMATION_DURATION_MS, ToastService } from '../../../shared/toast/toast.service';
import { stepMenuFocus } from '../../../shared/menu-keys';
import { SOCIAL_NETWORKS, SocialNetworkName } from '../../../core/social-network';
import { LayoutService } from '../../layout.service';
import { SocialAccountRefDto } from '../../social-feed';
import { SubscriptionsStore } from '../../state/subscriptions.store';
import { linkPrefill } from '../composer/link-prefill';
import { ResponseActions } from '../composer/response-actions.service';
import { SHARE_GLYPH, SHARE_TARGETS, ShareTarget, shareLogoUrl, shareUrl } from './share-targets';
import { stripTracking } from './tracking-params';

const POSITIONS: ConnectedPosition[] = [
  { originX: 'end', originY: 'bottom', overlayX: 'end', overlayY: 'top', offsetY: 6 },
  { originX: 'end', originY: 'top', overlayX: 'end', overlayY: 'bottom', offsetY: -6 },
];

/** A network the URL can be posted to, with the handles of the connected accounts there. */
interface ConnectedNetwork {
  readonly network: SocialNetworkName;
  readonly accounts: readonly string[];
}

/** Who the post goes out as, or that the composer asks when the network has several accounts. */
interface PostTarget extends ConnectedNetwork {
  readonly hint: string;
}

function connectedNetworks(accounts: readonly SocialAccountRefDto[]): ConnectedNetwork[] {
  const connected = accounts.filter((account) => account.status === 'connected');
  return SOCIAL_NETWORKS.map((network) => ({
    network,
    accounts: connected
      .filter((account) => account.network === network)
      .map((account) => account.handle),
  })).filter((target) => target.accounts.length > 0);
}

function hostOf(url: string): string {
  try {
    return new URL(url).hostname;
  } catch {
    return url;
  }
}

function postIdOf(network: SocialNetworkName): string {
  return `post-${network}`;
}

@Component({
  selector: 'app-share-button',
  imports: [
    TranslocoPipe,
    IconComponent,
    ListActionDirective,
    MaskedLogoComponent,
    NetworkLogoComponent,
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
  readonly imageUrl = input<string | null>(null);

  private readonly toast = inject(ToastService);
  private readonly i18n = inject(TranslocoService);
  private readonly screen = inject(LayoutService);
  private readonly sheet = inject(ActionSheet);
  private readonly destroyRef = inject(DestroyRef);
  private readonly subscriptions = inject(SubscriptionsStore);
  private readonly responses = inject(ResponseActions);

  protected readonly glyph = SHARE_GLYPH;
  protected readonly positions = POSITIONS;
  protected readonly targets = SHARE_TARGETS;
  protected readonly logoUrl = shareLogoUrl;
  protected readonly open = signal(false);
  private readonly activeLang = toSignal(this.i18n.langChanges$, {
    initialValue: this.i18n.getActiveLang(),
  });
  protected readonly postTargets = computed((): PostTarget[] => {
    // translate() is one-shot: reading the language re-resolves the hints on a switch.
    this.activeLang();
    return connectedNetworks(this.subscriptions.socialAccounts()).map((target) => ({
      ...target,
      hint: this.postHint(target.accounts),
    }));
  });

  private readonly targetsById = new Map(
    this.targets.flatMap((target, index) =>
      target.kind === 'divider' ? [] : [[String(index), target] as const],
    ),
  );
  private readonly postNetworksById = new Map(
    SOCIAL_NETWORKS.map((network) => [postIdOf(network), network] as const),
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

  protected post(network: SocialNetworkName): void {
    this.open.set(false);
    const url = stripTracking(this.url());
    const site = hostOf(url);
    this.responses.respond({
      kind: 'link',
      network,
      url,
      title: this.title() || site,
      site,
      imageUrl: this.imageUrl(),
      prefill: linkPrefill(network, this.title(), url),
    });
  }

  protected step(event: KeyboardEvent): void {
    const menu = (event.target as Element).closest('[role="menu"]');
    if (menu !== null) stepMenuFocus(event, menu);
  }

  private postHint(accounts: readonly string[]): string {
    return accounts.length === 1
      ? this.i18n.translate('reader.share.postAs', { handle: accounts[0] })
      : this.i18n.translate('reader.share.pickAccount');
  }

  private openSheet(): void {
    this.sheet
      .open({
        title: this.i18n.translate('reader.share.button'),
        actions: [
          ...this.postSheetItems(),
          ...this.targets.map((target, index) => this.sheetItem(target, index)),
        ],
      })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe((choice) => this.chooseById(choice));
  }

  private postSheetItems(): ActionSheetItem[] {
    const items = this.postTargets().map((target): ActionSheetItem => ({
      id: postIdOf(target.network),
      label: this.i18n.translate(`reader.link.${target.network}`),
      hint: target.hint,
      logo: {
        url: shareLogoUrl(`${target.network}.svg`),
        color: `var(--brand-${target.network})`,
      },
    }));
    return items.length === 0 ? [] : [...items, ACTION_SHEET_DIVIDER];
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
    if (choice === undefined) return;
    const network = this.postNetworksById.get(choice);
    if (network !== undefined) return this.post(network);
    const target = this.targetsById.get(choice);
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
