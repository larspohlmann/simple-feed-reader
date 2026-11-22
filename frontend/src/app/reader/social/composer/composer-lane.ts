import { HttpErrorResponse } from '@angular/common/http';
import { Signal, WritableSignal, computed, inject, signal } from '@angular/core';
import { TranslocoService } from '@jsverse/transloco';
import { Observable, Subject, Subscription, map, tap } from 'rxjs';
import { SocialNetworkName } from '../../../core/social-network';
import { parseProblem, problemText } from '../../../core/problem';
import { randomUuid } from '../../../shared/random-uuid';
import { ComposeRequest } from './compose-request';
import {
  ComposerContextDto,
  LemmyPostFieldsDto,
  MastodonVisibility,
  PostDraftDto,
  PublishedPostDto,
} from './composer-dto';
import { ComposerApi } from './composer-api';
import { SentPost } from './composed-post';
import { LemmyCommunityMemory } from './lemmy-community-memory';
import { postLength } from './post-length';

const SEND_FAILURE_KEYS: Readonly<Record<string, string | undefined>> = {
  social_network_unavailable: 'social.composer.notSent',
  social_write_outcome_unknown: 'social.composer.mayHaveSent',
};

type ContextState =
  | { status: 'idle' }
  | { status: 'loading' }
  | { status: 'ready'; context: ComposerContextDto }
  | { status: 'failed'; message: string };

type LemmyChoice = Required<Pick<PostDraftDto, 'title' | 'communityId'>>;

/** What the dialog's shared fields contribute to a lane's post. */
export type SharedDraft = Omit<
  PostDraftDto,
  'accountId' | 'idempotencyKey' | 'visibility' | 'contentWarning' | 'title' | 'communityId'
>;

/**
 * One network the composer posts to: its context, account and own fields, and what its send did.
 * The key is minted once, so a resend publishes the post once. Built in an injection context.
 */
export class ComposerLane {
  private readonly api = inject(ComposerApi);
  private readonly i18n = inject(TranslocoService);
  private readonly communityMemory = inject(LemmyCommunityMemory);
  readonly network: SocialNetworkName;
  private readonly idempotencyKey = randomUuid();
  readonly active: WritableSignal<boolean> = signal(false);
  /** Mastodon's own fields, which the lane carries only while its context offers a visibility. */
  readonly visibility: WritableSignal<MastodonVisibility | null> = signal(null);
  readonly contentWarning: WritableSignal<string | null> = signal(null);
  /** Lemmy's own fields, which the lane carries only while its context has Lemmy fields. */
  readonly community: WritableSignal<number | null> = signal(null);
  private readonly typedTitle = signal('');
  readonly title: Signal<string> = this.typedTitle.asReadonly();
  private readonly trimmedTitle = computed(() => this.title().trim());
  readonly titleLength: Signal<number> = computed(() => postLength(this.trimmedTitle(), 'lemmy'));

  private readonly loadState = signal<ContextState>({ status: 'idle' });
  private readonly chosenAccountId = signal<number | undefined>(undefined);
  private readonly sentPost = signal<SentPost | null>(null);
  private readonly sendFailure = signal<string | null>(null);
  private readonly loadFailure = new Subject<void>();
  private visibilityChosen = false;
  private titleWritten = false;
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
  readonly fieldsReady: Signal<boolean> = computed(() => {
    const lemmy = this.context()?.lemmy ?? null;
    if (lemmy === null) return true;
    const length = this.titleLength();
    return (
      this.community() !== null &&
      length >= lemmy.minTitleCharacters &&
      length <= lemmy.maxTitleCharacters
    );
  });

  constructor(readonly request: ComposeRequest) {
    this.network = request.network;
  }

  /** A newer load drops the one still on the wire, so a slow answer never replaces a later choice. */
  load(): void {
    this.loading.unsubscribe();
    this.loadState.set({ status: 'loading' });
    this.loading = this.api.context(this.request, this.chosenAccountId()).subscribe({
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

  writeTitle(value: string): void {
    this.typedTitle.set(value.replace(/\s*[\r\n]+\s*/g, ' '));
    this.titleWritten = true;
  }

  send(draft: SharedDraft, text: string): Observable<SentPost> {
    const context = this.requireContext();
    this.sendFailure.set(null);
    return this.api.send(this.request, this.draftFor(draft, context)).pipe(
      map((published) => this.sentAs(published, context, text)),
      tap({
        next: (sent) => {
          this.sentPost.set(sent);
          this.rememberCommunity(context);
        },
        error: (error: HttpErrorResponse) => this.sendFailure.set(this.sendFailureMessage(error)),
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
    if (context.lemmy !== null) this.adoptLemmy(context.accountId, context.lemmy);
  }

  private adoptLemmy(accountId: number, lemmy: LemmyPostFieldsDto): void {
    const remembered = this.communityMemory.recall(accountId);
    const offered = lemmy.communities.some((community) => community.id === remembered);
    if (!this.titleWritten) this.typedTitle.set(lemmy.title);
    this.community.set(offered ? remembered : null);
  }

  private rememberCommunity(context: ComposerContextDto): void {
    const choice = this.lemmyChoice(context);
    if (choice !== null) this.communityMemory.remember(context.accountId, choice.communityId);
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
      ...(this.lemmyChoice(context) ?? {}),
    };
  }

  private mastodonFields(): Pick<PostDraftDto, 'visibility' | 'contentWarning'> {
    const visibility = this.visibility();
    if (visibility === null) return {};
    const warning = this.contentWarning()?.trim() ?? '';
    return { visibility, ...(warning === '' ? {} : { contentWarning: warning }) };
  }

  private lemmyChoice(context: ComposerContextDto): LemmyChoice | null {
    const community = this.community();
    if (context.lemmy === null || community === null) return null;
    return { title: this.trimmedTitle(), communityId: community };
  }

  private sentAs(published: PublishedPostDto, context: ComposerContextDto, text: string): SentPost {
    const account = context.accounts.find((candidate) => candidate.id === context.accountId);
    return {
      ...published,
      kind: context.kind,
      network: this.network,
      text: context.lemmy === null ? text : this.trimmedTitle(),
      authorHandle: account?.handle ?? null,
      subject: context.subject,
    };
  }

  private sendFailureMessage(error: HttpErrorResponse): string {
    const problem = parseProblem(error);
    const key = SEND_FAILURE_KEYS[problem.type];
    if (key === undefined || problem.serviceName === undefined)
      return problemText(problem, this.i18n);
    return this.i18n.translate(key, { serviceName: problem.serviceName });
  }

  private messageOf(error: HttpErrorResponse): string {
    return problemText(parseProblem(error), this.i18n);
  }
}
