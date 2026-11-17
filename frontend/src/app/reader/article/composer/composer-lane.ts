import { HttpErrorResponse } from '@angular/common/http';
import { Signal, WritableSignal, computed, inject, signal } from '@angular/core';
import { TranslocoService } from '@jsverse/transloco';
import { Observable, Subject, Subscription, map, tap } from 'rxjs';
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

type ContextState =
  | { status: 'idle' }
  | { status: 'loading' }
  | { status: 'ready'; context: ComposerContextDto }
  | { status: 'failed'; message: string };

/** What the dialog's shared fields contribute to a lane's post. */
export type SharedDraft = Omit<
  PostDraftDto,
  'accountId' | 'idempotencyKey' | 'visibility' | 'contentWarning'
>;

/**
 * One network the composer posts to: its context, account and own fields, and what its send did.
 * The key is minted once, so a resend publishes the post once. Built in an injection context.
 */
export class ComposerLane {
  private readonly api = inject(SocialPostApi);
  private readonly i18n = inject(TranslocoService);
  readonly network: SocialNetworkName;
  private readonly idempotencyKey = randomUuid();
  readonly active: WritableSignal<boolean> = signal(false);
  /** Mastodon's own fields, which the lane carries only while its context offers a visibility. */
  readonly visibility: WritableSignal<MastodonVisibility | null> = signal(null);
  readonly contentWarning: WritableSignal<string | null> = signal(null);

  private readonly loadState = signal<ContextState>({ status: 'idle' });
  private readonly chosenAccountId = signal<number | undefined>(undefined);
  private readonly sentPost = signal<SentPost | null>(null);
  private readonly sendFailure = signal<string | null>(null);
  private readonly loadFailure = new Subject<void>();
  private visibilityChosen = false;
  private loading = Subscription.EMPTY;

  readonly state: Signal<ContextState> = this.loadState.asReadonly();
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
  readonly error = computed(() => {
    const loadError = this.loadError();
    if (loadError !== null) return { message: loadError, retries: true };
    const sendError = this.sendFailure();
    return sendError === null ? null : { message: sendError, retries: false };
  });
  readonly loadFailed: Observable<void> = this.loadFailure.asObservable();

  constructor(readonly target: PostTargetDto) {
    this.network = target.network;
  }

  /** A newer load drops the one still on the wire, so a slow answer never replaces a later choice. */
  load(): void {
    this.loading.unsubscribe();
    this.loadState.set({ status: 'loading' });
    this.loading = this.api.composer(this.target, this.chosenAccountId()).subscribe({
      next: (context) => this.adopt(context),
      error: (error: HttpErrorResponse) => {
        this.loadState.set({ status: 'failed', message: this.messageOf(error) });
        this.loadFailure.next();
      },
    });
  }

  /** Loads the context the first time, and again after a failed load. */
  activate(): void {
    this.active.set(true);
    const status = this.loadState().status;
    if (status === 'idle' || status === 'failed') this.load();
  }

  /** A load still on the wire is dropped, so its late answer never shows, and a failed send's banner goes. */
  deactivate(): void {
    this.active.set(false);
    this.sendFailure.set(null);
    if (this.loadState().status !== 'loading') return;
    this.loading.unsubscribe();
    this.loadState.set({ status: 'idle' });
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
    this.loadFailure.complete();
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
    return {
      ...draft,
      accountId: context.accountId,
      idempotencyKey: this.idempotencyKey,
      ...this.mastodonFields(),
    };
  }

  private mastodonFields(): Pick<PostDraftDto, 'visibility' | 'contentWarning'> {
    const visibility = this.visibility();
    if (visibility === null) return {};
    const warning = this.contentWarning()?.trim() ?? '';
    return { visibility, ...(warning === '' ? {} : { contentWarning: warning }) };
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
