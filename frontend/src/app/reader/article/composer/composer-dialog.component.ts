import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  computed,
  effect,
  inject,
  linkedSignal,
  signal,
  viewChild,
} from '@angular/core';
import { A11yModule } from '@angular/cdk/a11y';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { takeUntilDestroyed, toSignal } from '@angular/core/rxjs-interop';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { filter, finalize, merge } from 'rxjs';
import { networkList } from '../../../core/network-list';
import { SOCIAL_NETWORK_NAMES } from '../../../core/social-network';
import { ButtonComponent } from '../../../shared/button/button.component';
import { ErrorBannerComponent } from '../../../shared/error-banner/error-banner.component';
import { FieldComponent } from '../../../shared/field/field.component';
import { IconComponent } from '../../../shared/icon/icon.component';
import { inputValue } from '../../../shared/input-value';
import { NetworkLogoComponent } from '../../../shared/network-logo/network-logo.component';
import { OverlayPanelComponent } from '../../../shared/overlay-panel/overlay-panel.component';
import { SkeletonComponent } from '../../../shared/skeleton/skeleton.component';
import {
  ComposerContextDto,
  ComposerKind,
  MASTODON_VISIBILITIES,
  MastodonVisibility,
  PostTargetDto,
} from '../../entry-social';
import { SentPost } from './composed-post';
import { ComposerLane, SharedDraft } from './composer-lane';
import { ComposerLanes } from './composer-lanes';
import { ComposerSubjectComponent } from './composer-subject.component';
import { NetworkPillsComponent } from './network-pills.component';
import { postLength } from './post-length';

/**
 * Writes a reply to a Lemmy post or comment, a reply to or a quote of a Bluesky or Mastodon post, or a post of a shared URL.
 * Closing never asks: typed text is discarded.
 * It stays open while a post is on the wire, and once one went out it closes only with it, so
 * the reader always learns what was published.
 * Send posts to every active network still to post. A failed send keeps the text and says why;
 * a resend reaches only the networks that failed, each under the key its lane minted, so no
 * network publishes the post twice.
 */
@Component({
  selector: 'app-composer-dialog',
  imports: [
    A11yModule,
    ButtonComponent,
    ComposerSubjectComponent,
    ErrorBannerComponent,
    FieldComponent,
    IconComponent,
    NetworkLogoComponent,
    NetworkPillsComponent,
    OverlayPanelComponent,
    SkeletonComponent,
    TranslocoPipe,
  ],
  templateUrl: './composer-dialog.component.html',
  styleUrl: './composer-dialog.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
  providers: [ComposerLanes],
})
export class ComposerDialogComponent {
  protected readonly ref = inject<DialogRef<SentPost[]>>(DialogRef);
  protected readonly target = inject<PostTargetDto>(DIALOG_DATA);
  protected readonly lanes = inject(ComposerLanes);
  private readonly i18n = inject(TranslocoService);
  private readonly textField = viewChild<ElementRef<HTMLTextAreaElement>>('textField');
  private readonly language = toSignal(this.i18n.langChanges$, {
    initialValue: this.i18n.getActiveLang(),
  });

  protected readonly visibilities = MASTODON_VISIBILITIES;
  protected readonly names = SOCIAL_NETWORK_NAMES;
  protected readonly primary = this.lanes.primary;
  private textTouched = false;
  protected readonly text = linkedSignal<string | undefined, string>({
    source: () => this.lanes.picked().context()?.text,
    computation: (starting, previous) =>
      this.textTouched || starting === undefined ? (previous?.value ?? '') : starting,
  });
  private readonly caretBeforeText = this.target.kind === 'link' && this.target.quote !== null;
  protected readonly sending = signal(false);

  /** The primary lane, or while its context is missing the first active lane that has one. */
  private readonly shown = computed(() => {
    const primary = this.primary();
    if (primary.context() !== null) return primary;
    return this.lanes.active().find((lane) => lane.context() !== null) ?? primary;
  });
  protected readonly context = computed(() => this.shown().context());
  private readonly kind = linkedSignal<ComposerContextDto | null, ComposerKind>({
    source: this.context,
    computation: (context, previous) => context?.kind ?? previous?.value ?? this.target.kind,
  });
  private readonly crossposts = computed(() => this.lanes.active().length > 1);
  protected readonly forbiddenKeys = computed(() =>
    this.lanes.active().flatMap((lane) => {
      const context = lane.context();
      if (context === null || context.canSend || context.kind === 'link') return [];
      return [`reader.${context.kind}Forbidden.${lane.network}`];
    }),
  );
  /** Where the words that differ between a reply, a quote and a link post live. */
  protected readonly copy = computed(() => `reader.composer.${this.kind()}`);
  protected readonly heading = computed(() => {
    const active = this.lanes.active();
    if (active.length < 2) {
      return { key: `reader.${this.kind()}.${this.shown().network}`, params: {} };
    }
    const networks = networkList(
      active.map(({ network }) => network),
      this.language(),
    );
    return { key: 'reader.link.many', params: { networks } };
  });
  protected readonly sendLabel = computed(() => {
    const pending = this.lanes.pending();
    if (this.lanes.sentPosts().length === 0 || pending.length === 0) {
      return { key: this.sendKey(), params: {} };
    }
    const network = networkList(
      pending.map(({ network }) => network),
      this.language(),
    );
    return { key: 'reader.composer.retry', params: { network } };
  });
  private readonly sendKey = computed(() => {
    const count = this.lanes.active().length;
    if (count === 2) return 'reader.composer.link.sendBoth';
    return count > 2 ? 'reader.composer.link.send' : `${this.copy()}.send`;
  });
  protected readonly counters = computed(() =>
    this.lanes.active().flatMap((lane) => {
      const max = lane.context()?.maxCharacters;
      if (max === undefined) return [];
      const used = postLength(this.text(), lane.network);
      return [{ network: this.names[lane.network], used, max, over: used > max }];
    }),
  );
  private readonly pendingFit = computed(() =>
    this.lanes.pending().every((lane) => {
      const context = lane.context();
      return (
        context?.canSend === true && postLength(this.text(), lane.network) <= context.maxCharacters
      );
    }),
  );
  protected readonly accountLabel = computed(() =>
    this.crossposts() ? 'reader.composer.accountOn' : `${this.copy()}.account`,
  );
  protected readonly writable = computed(() =>
    this.lanes.active().some((lane) => lane.context()?.canSend === true),
  );
  protected readonly appendedUrl = computed(() => {
    const target = this.target;
    const adds = this.lanes
      .active()
      .some((lane) => lane.network === 'mastodon' && lane.context()?.kind === 'link');
    return adds && target.kind === 'link' ? target.url : null;
  });
  protected readonly kindNotes = computed(() => {
    const shown = this.kind();
    return KIND_NOTES.flatMap(({ kind, key }) => {
      if (kind === shown) return [];
      const networks = this.lanes
        .active()
        .filter((lane) => lane.context()?.kind === kind)
        .map(({ network }) => network);
      if (networks.length === 0) return [];
      return [{ key, networks, network: networkList(networks, this.language()) }];
    });
  });
  private readonly textOptional = computed(() =>
    this.lanes.pending().every((lane) => lane.context()?.kind === 'link'),
  );
  protected readonly canSend = computed(
    () =>
      this.lanes.pending().length > 0 &&
      this.pendingFit() &&
      (this.textOptional() || this.text().trim() !== '') &&
      !this.sending(),
  );

  constructor() {
    this.lanes.init(this.target);
    // The field appears only once the context loads, after the focus trap placed its initial focus.
    effect(() => this.placeCaret(this.textField()?.nativeElement));
    merge(
      this.ref.backdropClick,
      this.ref.keydownEvents.pipe(filter((event) => event.key === 'Escape')),
    )
      .pipe(takeUntilDestroyed())
      .subscribe(() => this.dismiss());
  }

  private placeCaret(field: HTMLTextAreaElement | undefined): void {
    if (field === undefined) return;
    field.focus();
    if (this.caretBeforeText && !this.textTouched) field.setSelectionRange(0, 0);
  }

  /** Lane controls keep today's ids in a one-network composer and carry the network beside pills. */
  protected controlId(control: string, lane: ComposerLane): string {
    return this.lanes.showsPills() ? `reply-${control}-${lane.network}` : `reply-${control}`;
  }

  protected chooseAccount(lane: ComposerLane, event: Event): void {
    lane.chooseAccount(Number(inputValue(event)));
  }

  protected write(event: Event): void {
    this.textTouched = true;
    this.text.set(inputValue(event));
  }

  protected clear(): void {
    this.textTouched = true;
    this.text.set('');
    this.textField()?.nativeElement.focus();
  }

  protected chooseVisibility(lane: ComposerLane, event: Event): void {
    lane.chooseVisibility(inputValue(event) as MastodonVisibility);
  }

  protected toggleWarning(lane: ComposerLane, event: Event): void {
    lane.contentWarning.set((event.target as HTMLInputElement).checked ? '' : null);
  }

  protected writeWarning(lane: ComposerLane, event: Event): void {
    lane.contentWarning.set(inputValue(event));
  }

  /** Settling in finalize means a send that throws can never leave the composer unclosable. */
  protected send(event: Event): void {
    event.preventDefault();
    if (!this.canSend()) return;
    this.holdOpen(true);
    this.lanes
      .sendAll(this.sharedDraft(), this.text())
      .pipe(finalize(() => this.holdOpen(false)))
      .subscribe({ complete: () => this.closeOnceAllSent() });
  }

  protected cancel(): void {
    const sent = this.lanes.sentPosts();
    if (sent.length === 0) this.ref.close();
    else this.ref.close(sent);
  }

  private holdOpen(sending: boolean): void {
    this.sending.set(sending);
    this.ref.disableClose = sending || this.lanes.sentPosts().length > 0;
  }

  /** The dialog closes itself on Escape or a backdrop click until a post went out. */
  private dismiss(): void {
    if (this.ref.disableClose && !this.sending()) this.cancel();
  }

  private closeOnceAllSent(): void {
    if (this.lanes.pending().length === 0) this.ref.close(this.lanes.sentPosts());
  }

  private sharedDraft(): SharedDraft {
    return { text: this.text(), language: browserLanguage() };
  }
}

const KIND_NOTES: readonly { kind: ComposerKind; key: string }[] = [
  { kind: 'link', key: 'reader.composer.sharesAsLink' },
  { kind: 'quote', key: 'reader.composer.quotesPost' },
];

function browserLanguage(): string | null {
  const primary = navigator.language.split('-')[0]?.toLowerCase() ?? '';
  return /^[a-z]{2,3}$/.test(primary) ? primary : null;
}
