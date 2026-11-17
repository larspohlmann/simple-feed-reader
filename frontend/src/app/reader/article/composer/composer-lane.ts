import { HttpErrorResponse } from '@angular/common/http';
import { Signal, WritableSignal, computed, signal } from '@angular/core';
import { TranslocoService } from '@jsverse/transloco';
import { Observable, Subscription, map, tap } from 'rxjs';
import { SocialNetworkName } from '../../../core/social-network';
import { parseProblem, problemText } from '../../../core/problem';
import { randomUuid } from '../../../shared/random-uuid';
import {
  ComposerContextDto,
  MastodonVisibility,
  PostDraftDto,
  PostTargetDto,
  PublishedPostDto,
} from '../../entry-social';
import { SocialPostApi } from '../../social-post-api';
import { SentPost } from './composed-post';

export type ContextState =
  | { status: 'loading' }
  | { status: 'ready'; context: ComposerContextDto }
  | { status: 'failed'; message: string };

/** What the dialog's shared fields contribute to a lane's post. */
export type SharedDraft = Omit<PostDraftDto, 'accountId' | 'idempotencyKey' | 'visibility'>;

/**
 * One network the composer posts to: its context, account and visibility, and what its send did.
 * The key is minted once, so a resend publishes the post once.
 */
export class ComposerLane {
  readonly network: SocialNetworkName;
  readonly idempotencyKey = randomUuid();
  readonly visibility: WritableSignal<MastodonVisibility | null> = signal(null);

  private readonly loadState = signal<ContextState>({ status: 'loading' });
  private readonly chosenAccountId = signal<number | undefined>(undefined);
  private readonly sentPost = signal<SentPost | null>(null);
  private readonly sendFailure = signal<string | null>(null);
  private visibilityChosen = false;
  private loading = Subscription.EMPTY;

  readonly state: Signal<ContextState> = this.loadState.asReadonly();
  readonly accountId: Signal<number | undefined> = this.chosenAccountId.asReadonly();
  readonly sent: Signal<SentPost | null> = this.sentPost.asReadonly();
  readonly sendError: Signal<string | null> = this.sendFailure.asReadonly();
  readonly context = computed(() => {
    const state = this.loadState();
    return state.status === 'ready' ? state.context : null;
  });
  readonly loadError = computed(() => {
    const state = this.loadState();
    return state.status === 'failed' ? state.message : null;
  });

  constructor(
    readonly target: PostTargetDto,
    private readonly api: SocialPostApi,
    private readonly i18n: TranslocoService,
  ) {
    this.network = target.network;
  }

  /** A newer load drops the one still on the wire, so a slow answer never replaces a later choice. */
  load(): void {
    this.loading.unsubscribe();
    this.loadState.set({ status: 'loading' });
    this.loading = this.api.composer(this.target, this.chosenAccountId()).subscribe({
      next: (context) => this.adopt(context),
      error: (error: HttpErrorResponse) =>
        this.loadState.set({ status: 'failed', message: this.messageOf(error) }),
    });
  }

  chooseAccount(id: number): void {
    this.chosenAccountId.set(id);
    this.load();
  }

  chooseVisibility(value: MastodonVisibility): void {
    this.visibility.set(value);
    this.visibilityChosen = true;
  }

  send(draft: SharedDraft, text: string): Observable<SentPost> {
    const context = this.requireContext();
    this.sendFailure.set(null);
    return this.api.send(this.target, this.draftFor(draft, context)).pipe(
      map((published) => this.sentAs(published, context, text)),
      tap({
        next: (sent) => this.sentPost.set(sent),
        error: (error: HttpErrorResponse) => this.sendFailure.set(this.messageOf(error)),
      }),
    );
  }

  dispose(): void {
    this.loading.unsubscribe();
  }

  private adopt(context: ComposerContextDto): void {
    this.loadState.set({ status: 'ready', context });
    if (!this.visibilityChosen) this.visibility.set(context.visibility);
  }

  private requireContext(): ComposerContextDto {
    const context = this.context();
    if (context === null) throw new Error(`The ${this.network} lane has no context to send with`);
    return context;
  }

  private draftFor(draft: SharedDraft, context: ComposerContextDto): PostDraftDto {
    const visibility = this.visibility();
    return {
      ...draft,
      accountId: context.accountId,
      idempotencyKey: this.idempotencyKey,
      ...(visibility === null ? {} : { visibility }),
    };
  }

  private sentAs(published: PublishedPostDto, context: ComposerContextDto, text: string): SentPost {
    const account = context.accounts.find((candidate) => candidate.id === context.accountId);
    return {
      ...published,
      kind: context.kind,
      network: this.network,
      text,
      authorHandle: account?.handle ?? null,
      target: context.target,
    };
  }

  private messageOf(error: HttpErrorResponse): string {
    return problemText(parseProblem(error), this.i18n);
  }
}
