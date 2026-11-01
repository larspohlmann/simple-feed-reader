import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  DestroyRef,
  computed,
  effect,
  inject,
  signal,
  viewChild,
} from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { A11yModule } from '@angular/cdk/a11y';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { TranslocoPipe } from '@jsverse/transloco';
import { Observable, Subscription } from 'rxjs';
import { SocialNetworkName } from '../../../core/social-network';
import { parseProblem, problemMessage } from '../../../core/problem';
import { ButtonComponent } from '../../../shared/button/button.component';
import { ErrorBannerComponent } from '../../../shared/error-banner/error-banner.component';
import { FieldComponent } from '../../../shared/field/field.component';
import { inputValue } from '../../../shared/input-value';
import { OverlayPanelComponent } from '../../../shared/overlay-panel/overlay-panel.component';
import { randomUuid } from '../../../shared/random-uuid';
import { SkeletonComponent } from '../../../shared/skeleton/skeleton.component';
import {
  ComposerContextDto,
  MASTODON_VISIBILITIES,
  MastodonVisibility,
  PublishedPostDto,
  PostDraftDto,
  ResponseTargetDto,
} from '../../entry-social';
import { SocialPostApi } from '../../social-post-api';
import { postLength } from './post-length';

/** The post a reply or quote responds to, as the composer shows it above the text. */
export interface ComposeTarget extends ResponseTargetDto {
  network: SocialNetworkName;
  authorName: string | null;
  authorHandle: string | null;
  excerpt: string;
}

/** What the composer published, with what the panel needs to show it before the network does. */
export interface SentPost extends PublishedPostDto {
  text: string;
  authorHandle: string | null;
}

type ContextState =
  | { status: 'loading' }
  | { status: 'ready'; context: ComposerContextDto }
  | { status: 'failed'; message: string };

/**
 * Writes a reply to, or a quote of, a Bluesky or Mastodon post (#1964, #1965). Closing never asks: typed text is discarded.
 * It stays open while a reply is on the wire, so the panel always learns what was published.
 * A failed send keeps the text and says why; a resend carries the key minted on open, so Mastodon
 * publishes the reply once.
 */
@Component({
  selector: 'app-composer-dialog',
  imports: [
    A11yModule,
    ButtonComponent,
    ErrorBannerComponent,
    FieldComponent,
    OverlayPanelComponent,
    SkeletonComponent,
    TranslocoPipe,
  ],
  templateUrl: './composer-dialog.component.html',
  styleUrl: './composer-dialog.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ComposerDialogComponent {
  protected readonly ref = inject<DialogRef<SentPost>>(DialogRef);
  protected readonly target = inject<ComposeTarget>(DIALOG_DATA);
  private readonly api = inject(SocialPostApi);
  private readonly idempotencyKey = randomUuid();
  private readonly textField = viewChild<ElementRef<HTMLTextAreaElement>>('textField');

  protected readonly visibilities = MASTODON_VISIBILITIES;
  protected readonly state = signal<ContextState>({ status: 'loading' });
  protected readonly text = signal('');
  protected readonly visibility = signal<MastodonVisibility | null>(null);
  /** Null while the reply carries no content warning. */
  protected readonly contentWarning = signal<string | null>(null);
  private readonly accountId = signal<number | undefined>(undefined);
  private visibilityChosen = false;
  private loading = Subscription.EMPTY;
  protected readonly sending = signal(false);
  protected readonly sendError = signal<string | null>(null);

  protected readonly context = computed(() => {
    const state = this.state();
    return state.status === 'ready' ? state.context : null;
  });
  protected readonly loadError = computed(() => {
    const state = this.state();
    return state.status === 'failed' ? state.message : null;
  });
  protected readonly used = computed(() => postLength(this.text(), this.target.network));
  protected readonly over = computed(() => {
    const max = this.context()?.maxCharacters;
    return max !== undefined && this.used() > max;
  });
  protected readonly canSend = computed(
    () =>
      this.context()?.canSend === true &&
      this.text().trim() !== '' &&
      !this.over() &&
      !this.sending(),
  );

  constructor() {
    inject(DestroyRef).onDestroy(() => this.loading.unsubscribe());
    this.load();
    // The field appears only once the context loads, after the focus trap placed its initial focus.
    effect(() => this.textField()?.nativeElement.focus());
  }

  /** A newer load drops the one still on the wire, so a slow answer never replaces a later choice. */
  protected load(): void {
    this.loading.unsubscribe();
    this.state.set({ status: 'loading' });
    this.loading = this.api.composer(this.target, this.accountId()).subscribe({
      next: (context) => this.adopt(context),
      error: (error: HttpErrorResponse) =>
        this.state.set({ status: 'failed', message: problemMessage(parseProblem(error)) }),
    });
  }

  protected chooseAccount(event: Event): void {
    this.accountId.set(Number(inputValue(event)));
    this.load();
  }

  protected write(event: Event): void {
    this.text.set(inputValue(event));
  }

  protected chooseVisibility(event: Event): void {
    this.visibility.set(inputValue(event) as MastodonVisibility);
    this.visibilityChosen = true;
  }

  protected toggleWarning(event: Event): void {
    this.contentWarning.set((event.target as HTMLInputElement).checked ? '' : null);
  }

  protected writeWarning(event: Event): void {
    this.contentWarning.set(inputValue(event));
  }

  protected send(event: Event): void {
    event.preventDefault();
    const context = this.context();
    if (!this.canSend() || context === null) return;
    this.sending.set(true);
    this.ref.disableClose = true;
    this.sendError.set(null);
    this.publish(context).subscribe({
      next: (published) => this.ref.close(this.sentAs(published, context)),
      error: (error: HttpErrorResponse) => {
        this.sending.set(false);
        this.ref.disableClose = false;
        this.sendError.set(problemMessage(parseProblem(error)));
      },
    });
  }

  private adopt(context: ComposerContextDto): void {
    this.state.set({ status: 'ready', context });
    if (!this.visibilityChosen) this.visibility.set(context.visibility);
    if (this.text() === '' && context.mentions.length > 0) {
      this.text.set(context.mentions.join(' ') + ' ');
    }
  }

  private publish(context: ComposerContextDto): Observable<PublishedPostDto> {
    const { entryId, address } = this.target;
    const draft = this.draftFor(context);
    return this.target.kind === 'quote'
      ? this.api.quote(entryId, address, draft)
      : this.api.reply(entryId, address, draft);
  }

  private sentAs(published: PublishedPostDto, context: ComposerContextDto): SentPost {
    const account = context.accounts.find((candidate) => candidate.id === context.accountId);
    return { ...published, text: this.text(), authorHandle: account?.handle ?? null };
  }

  private draftFor(context: ComposerContextDto): PostDraftDto {
    const visibility = this.visibility();
    const warning = this.contentWarning()?.trim() ?? '';
    return {
      text: this.text(),
      accountId: context.accountId,
      language: browserLanguage(),
      idempotencyKey: this.idempotencyKey,
      ...(visibility === null ? {} : { visibility }),
      ...(warning === '' ? {} : { contentWarning: warning }),
    };
  }
}

function browserLanguage(): string | null {
  const primary = navigator.language.split('-')[0]?.toLowerCase() ?? '';
  return /^[a-z]{2,3}$/.test(primary) ? primary : null;
}
