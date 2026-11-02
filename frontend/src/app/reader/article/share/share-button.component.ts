import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  signal,
  viewChild,
} from '@angular/core';
import { CdkTrapFocus } from '@angular/cdk/a11y';
import { CdkConnectedOverlay, CdkOverlayOrigin, ConnectedPosition } from '@angular/cdk/overlay';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import {
  ACTION_SHEET_DIVIDER,
  ActionSheetAction,
  ActionSheetItem,
} from '../../../shared/action-sheet/action-sheet.component';
import { ActionSheet } from '../../../shared/action-sheet/action-sheet.service';
import { IconComponent } from '../../../shared/icon/icon.component';
import { IconButtonDirective } from '../../../shared/icon-button/icon-button.directive';
import { KeepSelectionDirective } from '../../../shared/keep-selection/keep-selection.directive';
import { ListActionDirective } from '../../../shared/list-action/list-action.directive';
import { MaskedLogoComponent } from '../../../shared/masked-logo/masked-logo.component';
import { NetworkLogoComponent } from '../../../shared/network-logo/network-logo.component';
import { openExternal } from '../../../shared/open-external';
import { CONFIRMATION_DURATION_MS, ToastService } from '../../../shared/toast/toast.service';
import { stepMenuFocus } from '../../../shared/menu-keys';
import { SocialNetworkName } from '../../../core/social-network';
import { LayoutService } from '../../layout.service';
import { PostActions } from '../composer/post-actions.service';
import { ArticleSelection } from '../selection-share/article-selection.service';
import { SelectedQuote } from '../selection-share/selected-quote';
import { PostNetworks } from './post-networks.service';
import {
  ShareContent,
  SharedPage,
  clipboardText,
  clippedAtWord,
  linkContent,
  quoteContent,
  sharedLink,
  sharedPage,
} from './share-content';
import { ShareItem, ShareMenuMode, TargetItem, shareMenuItems } from './share-menu';
import { SHARE_GLYPH, shareLogoUrl, shareUrl } from './share-targets';

/** Where the trigger sits: in the article toolbar, as a disc in the article's corner, or among a selection's actions. */
export type ShareButtonAppearance = 'toolbar' | 'corner' | 'compact';

const POSITIONS: Record<ShareButtonAppearance, ConnectedPosition[]> = {
  toolbar: [
    { originX: 'end', originY: 'bottom', overlayX: 'end', overlayY: 'top', offsetY: 6 },
    { originX: 'end', originY: 'top', overlayX: 'end', overlayY: 'bottom', offsetY: -6 },
  ],
  corner: [
    { originX: 'start', originY: 'top', overlayX: 'start', overlayY: 'bottom', offsetY: -6 },
  ],
  compact: [
    { originX: 'center', originY: 'bottom', overlayX: 'center', overlayY: 'top', offsetY: 6 },
    { originX: 'center', originY: 'top', overlayX: 'center', overlayY: 'bottom', offsetY: -6 },
  ],
};

const SHEET_SNIPPET_LENGTH = 60;

@Component({
  selector: 'app-share-button',
  imports: [
    TranslocoPipe,
    IconComponent,
    IconButtonDirective,
    KeepSelectionDirective,
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
  /** Unset, the live selection is shared; a host that remembers the quote passes it, or null for none. */
  readonly offeredQuote = input<SelectedQuote | null | undefined>(undefined);

  private readonly toast = inject(ToastService);
  private readonly i18n = inject(TranslocoService);
  private readonly screen = inject(LayoutService);
  private readonly sheet = inject(ActionSheet);
  private readonly postActions = inject(PostActions);
  private readonly selection = inject(ArticleSelection, { optional: true });
  private readonly networks = inject(PostNetworks).connected;

  protected readonly glyph = SHARE_GLYPH;
  protected readonly positions = computed(() => POSITIONS[this.appearance()]);
  protected readonly logoUrl = shareLogoUrl;
  protected readonly open = signal(false);
  protected readonly origin = viewChild.required(CdkOverlayOrigin);
  /** Caught when the menu opens: the press that opens it may collapse the selection it shares. */
  protected readonly quote = signal<SelectedQuote | null>(null);
  private readonly mode = computed((): ShareMenuMode => {
    if (this.quote() !== null) return 'quote';
    return this.selection === null ? 'link' : 'hinted';
  });
  protected readonly items = computed(() => shareMenuItems(this.networks(), this.mode()));
  private readonly itemsById = computed(() => new Map(this.items().map((item) => [item.id, item])));

  constructor() {
    effect(() => {
      this.url();
      this.open.set(false);
    });
  }

  protected press(): void {
    this.quote.set(this.selectedQuote());
    if (this.screen.isCoarse()) this.openSheet();
    else this.open.update((open) => !open);
  }

  /** Runs inside the click, so Safari still sees the gesture that share and clipboard need. */
  protected choose(item: TargetItem): void {
    this.open.set(false);
    const content = this.contentFor(item);
    const target = item.target;
    if (target.kind === 'system') this.shareThroughSystem(content);
    if (target.kind === 'copy') this.copy(content);
    if (target.kind === 'link') this.visit(shareUrl(target.url, content));
  }

  protected post(network: SocialNetworkName): void {
    this.open.set(false);
    this.postActions.postLink(network, sharedLink(this.page(), this.quote()));
  }

  protected closed(): void {
    this.open.set(false);
    (this.origin().elementRef.nativeElement as HTMLElement).focus();
  }

  protected step(event: KeyboardEvent): void {
    const menu = (event.target as Element).closest('[role="menu"]');
    if (menu !== null) stepMenuFocus(event, menu);
  }

  private selectedQuote(): SelectedQuote | null {
    const offered = this.offeredQuote();
    if (offered !== undefined) return offered;
    return this.selection?.quote() ?? null;
  }

  private page(): SharedPage {
    return sharedPage(this.url(), this.title());
  }

  private contentFor(item: TargetItem): ShareContent {
    const quote = this.quote();
    return quote === null || item.linkOnly
      ? linkContent(this.page())
      : quoteContent(this.page(), quote);
  }

  /** Not bound to this component's life: the sheet outlives a trigger in the phone bar, and `closed` completes. */
  private openSheet(): void {
    this.sheet
      .open({
        title: this.sheetTitle(),
        actions: this.items().map((item) => this.sheetItem(item)),
      })
      .subscribe((choice) => this.chooseById(choice));
  }

  private sheetTitle(): string {
    const quote = this.quote();
    return quote === null
      ? this.i18n.translate('reader.share.button')
      : `“${clippedAtWord(quote.text, SHEET_SNIPPET_LENGTH)}”`;
  }

  private sheetItem(item: ShareItem): ActionSheetItem {
    switch (item.kind) {
      case 'divider':
        return ACTION_SHEET_DIVIDER;
      case 'hint':
        return { note: this.i18n.translate('reader.share.quoteHint') };
      case 'post':
        return {
          id: item.id,
          label: this.i18n.translate(item.labelKey),
          hint: this.i18n.translate(item.hint.key, item.hint.params),
          logo: {
            url: shareLogoUrl(`${item.network}.svg`),
            color: `var(--brand-${item.network})`,
          },
        };
      case 'target':
        return this.targetSheetItem(item);
    }
  }

  private targetSheetItem({ id, target, linkOnly }: TargetItem): ActionSheetAction {
    return {
      id,
      label: target.labelKey ? this.i18n.translate(target.labelKey) : (target.label ?? ''),
      symbol: target.icon ? undefined : (target.symbol ?? this.glyph),
      logo: target.icon ? { url: shareLogoUrl(target.icon), color: target.color } : undefined,
      ...(linkOnly ? { hint: this.i18n.translate('reader.share.linkOnly') } : {}),
    };
  }

  private chooseById(choice: string | undefined): void {
    const item = choice === undefined ? undefined : this.itemsById().get(choice);
    if (item?.kind === 'post') this.post(item.network);
    if (item?.kind === 'target') this.choose(item);
  }

  private shareThroughSystem(content: ShareContent): void {
    const text = content.quote === null ? {} : { text: content.quote };
    navigator.share({ title: content.title, url: content.url, ...text }).catch((error: unknown) => {
      if (error instanceof DOMException && error.name === 'AbortError') return;
      this.notify('reader.share.failed');
    });
  }

  private copy(content: ShareContent): void {
    const copied = content.quote === null ? 'reader.share.copied' : 'reader.share.quoteCopied';
    (navigator.clipboard?.writeText(clipboardText(content)) ?? Promise.reject()).then(
      () => this.notify(copied, CONFIRMATION_DURATION_MS),
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
