import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  computed,
  effect,
  inject,
  input,
  signal,
  viewChild,
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
import { openExternal } from '../../../shared/open-external';
import { CONFIRMATION_DURATION_MS, ToastService } from '../../../shared/toast/toast.service';
import { stepMenuFocus } from '../../../shared/menu-keys';
import { SOCIAL_NETWORKS, SocialNetworkName } from '../../../core/social-network';
import { LayoutService } from '../../layout.service';
import { SubscriptionsStore } from '../../state/subscriptions.store';
import { PostActions } from '../composer/post-actions.service';
import { SHARE_GLYPH, SHARE_TARGETS, ShareTarget, shareLogoUrl, shareUrl } from './share-targets';
import { stripTracking } from './tracking-params';

/** Where the trigger sits: in the article toolbar, or as a disc in the article's bottom-left corner. */
export type ShareButtonAppearance = 'toolbar' | 'corner';

const POSITIONS: Record<ShareButtonAppearance, ConnectedPosition[]> = {
  toolbar: [
    { originX: 'end', originY: 'bottom', overlayX: 'end', overlayY: 'top', offsetY: 6 },
    { originX: 'end', originY: 'top', overlayX: 'end', overlayY: 'bottom', offsetY: -6 },
  ],
  corner: [
    { originX: 'start', originY: 'top', overlayX: 'start', overlayY: 'bottom', offsetY: -6 },
  ],
};

/** A network the URL can be posted to, and who the post goes out as, or that the composer asks. */
interface PostNetworkItem {
  readonly network: SocialNetworkName;
  readonly hint: string;
}

type ConfiguredTarget = Exclude<ShareTarget, { kind: 'divider' }>;

/** One entry of the menu and of the sheet, under the id the sheet answers with. */
type ShareItem =
  | { readonly kind: 'post'; readonly id: string; readonly post: PostNetworkItem }
  | { readonly kind: 'divider'; readonly id: string }
  | { readonly kind: 'target'; readonly id: string; readonly target: ConfiguredTarget };

function configuredItem(target: ShareTarget, index: number): ShareItem {
  const id = String(index);
  return target.kind === 'divider' ? { kind: 'divider', id } : { kind: 'target', id, target };
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
  readonly appearance = input<ShareButtonAppearance>('toolbar');

  private readonly toast = inject(ToastService);
  private readonly i18n = inject(TranslocoService);
  private readonly screen = inject(LayoutService);
  private readonly sheet = inject(ActionSheet);
  private readonly destroyRef = inject(DestroyRef);
  private readonly subscriptions = inject(SubscriptionsStore);
  private readonly postActions = inject(PostActions);

  protected readonly glyph = SHARE_GLYPH;
  protected readonly positions = computed(() => POSITIONS[this.appearance()]);
  protected readonly logoUrl = shareLogoUrl;
  protected readonly open = signal(false);
  protected readonly origin = viewChild.required(CdkOverlayOrigin);
  private readonly activeLang = toSignal(this.i18n.langChanges$, {
    initialValue: this.i18n.getActiveLang(),
  });
  private readonly postItems = computed((): ShareItem[] => {
    // translate() is one-shot: reading the language re-resolves the hints on a switch.
    this.activeLang();
    const connected = this.subscriptions
      .socialAccounts()
      .filter((account) => account.status === 'connected');
    return SOCIAL_NETWORKS.flatMap((network): ShareItem[] => {
      const handles = connected
        .filter((account) => account.network === network)
        .map((account) => account.handle);
      if (handles.length === 0) return [];
      const post = { network, hint: this.postHint(handles) };
      return [{ kind: 'post', id: `post-${network}`, post }];
    });
  });
  protected readonly items = computed((): ShareItem[] => {
    const posts = this.postItems();
    const divider: ShareItem[] =
      posts.length === 0 ? [] : [{ kind: 'divider', id: 'post-divider' }];
    return [...posts, ...divider, ...SHARE_TARGETS.map(configuredItem)];
  });
  private readonly itemsById = computed(() => new Map(this.items().map((item) => [item.id, item])));

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
  protected choose(target: ConfiguredTarget): void {
    this.open.set(false);
    const url = stripTracking(this.url());
    if (target.kind === 'system') this.shareThroughSystem(url);
    if (target.kind === 'copy') this.copy(url);
    if (target.kind === 'link') this.visit(shareUrl(target.url, url, this.title()));
  }

  protected post(network: SocialNetworkName): void {
    this.open.set(false);
    this.postActions.postLink(network, stripTracking(this.url()), this.title());
  }

  protected closed(): void {
    this.open.set(false);
    (this.origin().elementRef.nativeElement as HTMLElement).focus();
  }

  protected step(event: KeyboardEvent): void {
    const menu = (event.target as Element).closest('[role="menu"]');
    if (menu !== null) stepMenuFocus(event, menu);
  }

  private postHint(handles: readonly string[]): string {
    return handles.length === 1
      ? this.i18n.translate('reader.share.postAs', { handle: handles[0] })
      : this.i18n.translate('reader.share.pickAccount');
  }

  private openSheet(): void {
    this.sheet
      .open({
        title: this.i18n.translate('reader.share.button'),
        actions: this.items().map((item) => this.sheetItem(item)),
      })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe((choice) => this.chooseById(choice));
  }

  private sheetItem(item: ShareItem): ActionSheetItem {
    if (item.kind === 'divider') return ACTION_SHEET_DIVIDER;
    if (item.kind === 'post') {
      const network = item.post.network;
      return {
        id: item.id,
        label: this.i18n.translate(`reader.link.${network}`),
        hint: item.post.hint,
        logo: { url: shareLogoUrl(`${network}.svg`), color: `var(--brand-${network})` },
      };
    }
    const target = item.target;
    return {
      id: item.id,
      label: target.labelKey ? this.i18n.translate(target.labelKey) : (target.label ?? ''),
      symbol: target.icon ? undefined : (target.symbol ?? this.glyph),
      logo: target.icon ? { url: shareLogoUrl(target.icon), color: target.color } : undefined,
    };
  }

  private chooseById(choice: string | undefined): void {
    const item = choice === undefined ? undefined : this.itemsById().get(choice);
    if (item?.kind === 'post') this.post(item.post.network);
    if (item?.kind === 'target') this.choose(item.target);
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
    if (/^https?:/i.test(address)) openExternal(address);
    else window.location.assign(address);
  }

  private notify(key: string, durationMs?: number): void {
    this.toast.show({ message: this.i18n.translate(key), durationMs });
  }
}
