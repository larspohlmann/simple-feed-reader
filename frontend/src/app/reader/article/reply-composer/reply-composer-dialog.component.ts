import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { A11yModule } from '@angular/cdk/a11y';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { TranslocoPipe } from '@jsverse/transloco';
import { SocialNetworkName } from '../../../core/social-network';
import { parseProblem, problemMessage } from '../../../core/problem';
import { ButtonComponent } from '../../../shared/button/button.component';
import { ErrorBannerComponent } from '../../../shared/error-banner/error-banner.component';
import { FieldComponent } from '../../../shared/field/field.component';
import { inputValue } from '../../../shared/input-value';
import { OverlayPanelComponent } from '../../../shared/overlay-panel/overlay-panel.component';
import { SkeletonComponent } from '../../../shared/skeleton/skeleton.component';
import {
  ComposerContextDto,
  MASTODON_VISIBILITIES,
  MastodonVisibility,
  PublishedReplyDto,
  ReplyDraftDto,
} from '../../entry-social';
import { SocialPostApi } from '../../social-post-api';
import { postLength } from './post-length';

/** The post a reply answers, as the composer shows it above the text. */
export interface ReplyTarget {
  entryId: number;
  network: SocialNetworkName;
  /** A reply in the thread; null answers the entry's own post. */
  address: string | null;
  authorName: string | null;
  authorHandle: string | null;
  excerpt: string;
}

type ContextState =
  | { status: 'loading' }
  | { status: 'ready'; context: ComposerContextDto }
  | { status: 'failed'; message: string };

/**
 * Writes a reply to a Bluesky or Mastodon post (#1964). Closing never asks: typed text is discarded.
 * A failed send keeps the text and says why; a resend carries the key minted on open, so Mastodon
 * publishes the reply once.
 */
@Component({
  selector: 'app-reply-composer-dialog',
  imports: [
    A11yModule,
    ButtonComponent,
    ErrorBannerComponent,
    FieldComponent,
    OverlayPanelComponent,
    SkeletonComponent,
    TranslocoPipe,
  ],
  templateUrl: './reply-composer-dialog.component.html',
  styleUrl: './reply-composer-dialog.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ReplyComposerDialogComponent {
  protected readonly ref = inject<DialogRef<PublishedReplyDto>>(DialogRef);
  protected readonly target = inject<ReplyTarget>(DIALOG_DATA);
  private readonly api = inject(SocialPostApi);
  private readonly idempotencyKey = crypto.randomUUID();

  protected readonly visibilities = MASTODON_VISIBILITIES;
  protected readonly state = signal<ContextState>({ status: 'loading' });
  protected readonly text = signal('');
  protected readonly visibility = signal<MastodonVisibility | null>(null);
  protected readonly warns = signal(false);
  protected readonly contentWarning = signal('');
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
  protected readonly over = computed(() => this.used() > (this.context()?.maxCharacters ?? 0));
  protected readonly canSend = computed(
    () =>
      this.context()?.canReply === true &&
      this.text().trim() !== '' &&
      !this.over() &&
      !this.sending(),
  );

  constructor() {
    this.load();
  }

  protected load(accountId?: number): void {
    this.state.set({ status: 'loading' });
    const address = this.target.address ?? undefined;
    this.api.composer(this.target.entryId, { address, accountId }).subscribe({
      next: (context) => this.adopt(context),
      error: (error: HttpErrorResponse) =>
        this.state.set({ status: 'failed', message: problemMessage(parseProblem(error)) }),
    });
  }

  protected chooseAccount(event: Event): void {
    this.load(Number(inputValue(event)));
  }

  protected write(event: Event): void {
    this.text.set(inputValue(event));
  }

  protected chooseVisibility(event: Event): void {
    this.visibility.set(inputValue(event) as MastodonVisibility);
  }

  protected toggleWarning(event: Event): void {
    this.warns.set((event.target as HTMLInputElement).checked);
  }

  protected writeWarning(event: Event): void {
    this.contentWarning.set(inputValue(event));
  }

  protected send(event: Event): void {
    event.preventDefault();
    const context = this.context();
    if (!this.canSend() || context === null) return;
    this.sending.set(true);
    this.sendError.set(null);
    this.api.reply(this.target.entryId, this.target.address, this.draftFor(context)).subscribe({
      next: (published) => this.ref.close(published),
      error: (error: HttpErrorResponse) => {
        this.sending.set(false);
        this.sendError.set(problemMessage(parseProblem(error)));
      },
    });
  }

  private adopt(context: ComposerContextDto): void {
    this.state.set({ status: 'ready', context });
    this.visibility.set(context.visibility);
    if (this.text() === '' && context.mentions.length > 0) {
      this.text.set(context.mentions.join(' ') + ' ');
    }
  }

  private draftFor(context: ComposerContextDto): ReplyDraftDto {
    const visibility = this.visibility();
    const warning = this.warns() ? this.contentWarning().trim() : '';
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
