import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  computed,
  inject,
  input,
  output,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { CdkTrapFocus } from '@angular/cdk/a11y';
import { CdkConnectedOverlay, CdkOverlayOrigin, ConnectedPosition } from '@angular/cdk/overlay';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { ActionSheet } from '../../../shared/action-sheet/action-sheet.service';
import { IconComponent } from '../../../shared/icon/icon.component';
import { stepMenuFocus } from '../../../shared/menu-keys';
import { SocialNetworkName } from '../../../core/social-network';
import { LayoutService } from '../../layout.service';

const POSITIONS: ConnectedPosition[] = [
  { originX: 'end', originY: 'bottom', overlayX: 'end', overlayY: 'top', offsetY: 6 },
  { originX: 'end', originY: 'top', overlayX: 'end', overlayY: 'bottom', offsetY: -6 },
];

const TOGGLE = 'toggle';
const QUOTE = 'quote';

/**
 * A post's repost count as a button that opens the Repost / Quote menu; an action the network refuses stays in the
 * menu, disabled with its reason, and the button itself is disabled only when it refuses both.
 */
@Component({
  selector: 'app-repost-button',
  imports: [TranslocoPipe, IconComponent, CdkConnectedOverlay, CdkOverlayOrigin, CdkTrapFocus],
  templateUrl: './repost-button.component.html',
  styleUrl: './repost-button.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class RepostButtonComponent {
  readonly network = input.required<SocialNetworkName>();
  /** The repost count, already formatted for the reader's language; null before the counts load. */
  readonly countText = input.required<string | null>();
  /** The repost count as words, which the spoken label carries because it replaces the visible number. */
  readonly countLabel = input.required<string | null>();
  readonly reposted = input.required<boolean>();
  readonly canRepost = input.required<boolean>();
  readonly canQuote = input.required<boolean>();
  readonly toggled = output<void>();
  readonly quoteRequested = output<void>();

  private readonly i18n = inject(TranslocoService);
  private readonly screen = inject(LayoutService);
  private readonly sheet = inject(ActionSheet);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly positions = POSITIONS;
  protected readonly popup = computed(() => (this.screen.isCoarse() ? 'dialog' : 'menu'));
  protected readonly open = signal(false);
  protected readonly actionKey = computed(
    () => `reader.${this.reposted() ? 'unrepost' : 'repost'}.${this.network()}`,
  );
  protected readonly opens = computed(() => this.canRepost() || this.canQuote());
  private readonly repostForbiddenKey = computed(() => `reader.repostForbidden.${this.network()}`);
  /** Named for the repost while it is allowed, for the menu while only quoting is, for the refusal otherwise. */
  protected readonly hintKey = computed(() => {
    if (this.canRepost()) return this.actionKey();
    return this.canQuote() ? `reader.repostMenu.${this.network()}` : this.repostForbiddenKey();
  });

  protected press(): void {
    if (!this.opens()) return;
    if (this.screen.isCoarse()) this.openSheet();
    else this.open.update((open) => !open);
  }

  protected choose(): void {
    if (!this.canRepost()) return;
    this.open.set(false);
    this.toggled.emit();
  }

  protected quote(): void {
    if (!this.canQuote()) return;
    this.open.set(false);
    this.quoteRequested.emit();
  }

  protected step(event: KeyboardEvent): void {
    const menu = (event.target as Element).closest('[role="menu"]');
    if (menu !== null) stepMenuFocus(event, menu);
  }

  private refusal(allowed: boolean, reasonKey: string): { disabled: boolean; hint?: string } {
    return allowed ? { disabled: false } : { disabled: true, hint: this.i18n.translate(reasonKey) };
  }

  private openSheet(): void {
    this.sheet
      .open({
        title: this.i18n.translate(`reader.repostMenu.${this.network()}`),
        actions: [
          {
            id: TOGGLE,
            label: this.i18n.translate(this.actionKey()),
            symbol: 'repeat',
            ...this.refusal(this.canRepost(), this.repostForbiddenKey()),
          },
          {
            id: QUOTE,
            label: this.i18n.translate(`reader.quote.${this.network()}`),
            symbol: 'format_quote',
            ...this.refusal(this.canQuote(), `reader.quoteForbidden.${this.network()}`),
          },
        ],
      })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe((choice) => {
        if (choice === TOGGLE) this.choose();
        if (choice === QUOTE) this.quote();
      });
  }
}
