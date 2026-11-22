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
import { NgTemplateOutlet } from '@angular/common';
import { A11yModule } from '@angular/cdk/a11y';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { TranslocoPipe } from '@jsverse/transloco';
import { filter, finalize, merge } from 'rxjs';
import { LanguageService } from '../../../core/i18n/language.service';
import { networkList } from '../../../core/network-list';
import { SOCIAL_NETWORK_NAMES } from '../../../core/social-network';
import { ButtonComponent } from '../../../shared/button/button.component';
import { ErrorBannerComponent } from '../../../shared/error-banner/error-banner.component';
import { FieldComponent } from '../../../shared/field/field.component';
import { IconComponent } from '../../../shared/icon/icon.component';
import { inputChecked, inputValue } from '../../../shared/input-value';
import { NetworkLogoComponent } from '../../../shared/network-logo/network-logo.component';
import { OverlayPanelComponent } from '../../../shared/overlay-panel/overlay-panel.component';
import { SkeletonComponent } from '../../../shared/skeleton/skeleton.component';
import { ComposeRequest, ComposerKind } from './compose-request';
import { ComposerContextDto, MASTODON_VISIBILITIES, MastodonVisibility } from './composer-dto';
import { SentPost } from './composed-post';
import { ComposerLane, SharedDraft } from './composer-lane';
import { ComposerLanes } from './composer-lanes';
import { ComposerSubjectComponent } from './composer-subject.component';
import { LemmyPostFieldsComponent } from './lemmy-post-fields.component';
import { NetworkPillsComponent } from './network-pills.component';
import { postLength } from './post-length';

/**
 * Writes a reply to a Lemmy post or comment, a reply to or a quote of a Bluesky or Mastodon post, a post of a shared
 * URL, or a post of text alone.
 * Closing never asks: typed text is discarded, unless the request carries a draft that keeps it.
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
    LemmyPostFieldsComponent,
    NetworkLogoComponent,
    NetworkPillsComponent,
    NgTemplateOutlet,
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
  protected readonly request = inject<ComposeRequest>(DIALOG_DATA);
  protected readonly lanes = inject(ComposerLanes);
  private readonly language = inject(LanguageService).lang;
  private readonly textField = viewChild<ElementRef<HTMLTextAreaElement>>('textField');

  protected readonly visibilities = MASTODON_VISIBILITIES;
  protected readonly names = SOCIAL_NETWORK_NAMES;
  protected readonly primary = this.lanes.primary;
  private readonly draft = this.request.draft;
  /** A kept draft counts as typed, so the context's empty starting text never replaces it. */
  private textTouched = (this.draft?.() ?? '') !== '';
  protected readonly text = linkedSignal<string | undefined, string>({
    source: () => this.lanes.picked().context()?.text,
    computation: (starting, previous) =>
      this.textTouched || starting === undefined
        ? (previous?.value ?? this.draft?.() ?? '')
        : starting,
  });
  private readonly caretBeforeText = this.request.kind === 'link' && this.request.quote !== null;
  protected readonly sending = signal(false);
  private readonly holdsOpen = computed(() => this.sending() || this.lanes.sentPosts().length > 0);

  protected readonly context = computed(() => this.primary().context());
  private readonly kind = linkedSignal<ComposerContextDto | null, ComposerKind>({
    source: this.context,
    computation: (context, previous) => context?.kind ?? previous?.value ?? this.request.kind,
  });
  private readonly activeNetworks = computed(() =>
    this.lanes.active().map(({ network }) => network),
  );
  private readonly crossposts = computed(() => this.activeNetworks().length > 1);
  protected readonly forbiddenKeys = computed(() =>
    this.lanes.active().flatMap((lane) => {
      const context = lane.context();
      if (context === null || context.canSend || context.kind === 'link') return [];
      return [`reader.${context.kind}Forbidden.${lane.network}`];
    }),
  );
  /** Where the words that differ between a reply, a quote and a link post live. */
  protected readonly copy = computed(() => `social.composer.${this.kind()}`);
  protected readonly heading = computed(() => {
    if (!this.crossposts()) {
      return { key: `reader.${this.kind()}.${this.primary().network}`, params: {} };
    }
    const networks = networkList(this.activeNetworks(), this.language());
    return { key: 'social.composer.crosspostHeading', params: { networks } };
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
    return { key: 'social.composer.retry', params: { network } };
  });
  private readonly sendKey = computed(() => {
    const count = this.activeNetworks().length;
    if (count === 2) return 'social.composer.sendToBoth';
    return count > 2 ? 'social.composer.link.send' : `${this.copy()}.send`;
  });
  protected readonly counters = computed(() =>
    this.lanes.active().flatMap((lane) => {
      const max = lane.context()?.maxCharacters;
      if (max === undefined) return [];
      const used = postLength(this.text(), lane.network, lane.contentWarning() ?? '');
      return [
        { lane: lane.network, network: this.names[lane.network], used, max, over: used > max },
      ];
    }),
  );
  private readonly pendingFit = computed(() => {
    const over = this.counters()
      .filter((counter) => counter.over)
      .map((counter) => counter.lane);
    return this.lanes
      .pending()
      .every(
        (lane) =>
          lane.context()?.canSend === true && lane.fieldsReady() && !over.includes(lane.network),
      );
  });
  protected readonly accountLabel = computed(() =>
    this.crossposts() ? 'social.composer.accountOn' : `${this.copy()}.account`,
  );
  protected readonly writable = computed(() =>
    this.lanes.active().some((lane) => lane.context()?.canSend === true),
  );
  protected readonly appendedUrl = computed(() => {
    const subject = this.lanes
      .active()
      .find((lane) => lane.network === 'mastodon' && lane.context()?.kind === 'link')
      ?.context()?.subject;
    return subject?.kind === 'link' ? subject.card.url : null;
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
    this.lanes.init(this.request);
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

  protected networkName(lane: ComposerLane): string {
    return this.names[lane.network];
  }

  protected controlId(control: string, lane: ComposerLane): string {
    return this.lanes.controlId(control, lane);
  }

  protected locked(lane: ComposerLane): boolean {
    return this.sending() || lane.sent() !== null;
  }

  protected chooseAccount(lane: ComposerLane, event: Event): void {
    lane.chooseAccount(Number(inputValue(event)));
  }

  protected write(event: Event): void {
    this.textTouched = true;
    this.setText(inputValue(event));
  }

  protected clear(): void {
    this.textTouched = true;
    this.setText('');
    this.textField()?.nativeElement.focus();
  }

  private setText(text: string): void {
    this.text.set(text);
    this.draft?.set(text);
  }

  protected chooseVisibility(lane: ComposerLane, event: Event): void {
    lane.chooseVisibility(inputValue(event) as MastodonVisibility);
  }

  protected toggleWarning(lane: ComposerLane, event: Event): void {
    lane.contentWarning.set(inputChecked(event) ? '' : null);
  }

  protected writeWarning(lane: ComposerLane, event: Event): void {
    lane.contentWarning.set(inputValue(event));
  }

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
    this.ref.disableClose = this.holdsOpen();
  }

  /** The dialog closes itself on Escape or a backdrop click until a post went out. */
  private dismiss(): void {
    if (this.holdsOpen() && !this.sending()) this.cancel();
  }

  private closeOnceAllSent(): void {
    if (this.lanes.pending().length === 0) this.ref.close(this.lanes.sentPosts());
  }

  private sharedDraft(): SharedDraft {
    return { text: this.text(), language: browserLanguage() };
  }
}

const KIND_NOTES: readonly { kind: ComposerKind; key: string }[] = [
  { kind: 'link', key: 'social.composer.sharesAsLink' },
  { kind: 'quote', key: 'social.composer.quotesPost' },
];

function browserLanguage(): string | null {
  const primary = navigator.language.split('-')[0]?.toLowerCase() ?? '';
  return /^[a-z]{2,3}$/.test(primary) ? primary : null;
}
