import {
  DestroyRef,
  Injectable,
  Injector,
  computed,
  inject,
  runInInjectionContext,
  signal,
} from '@angular/core';
import { Observable, catchError, defer, forkJoin, map, of } from 'rxjs';
import { SocialNetworkName } from '../../../core/social-network';
import { PostNetworks } from '../post-networks.service';
import { SentPost } from './composed-post';
import { ComposeRequest, crossposts } from './compose-request';
import { ComposerLane, SharedDraft } from './composer-lane';

/** The lanes of one open composer: a link post gets one per connected link network, anything else one. */
@Injectable()
export class ComposerLanes {
  private readonly injector = inject(Injector);
  private readonly postNetworks = inject(PostNetworks);
  private readonly built = signal<readonly ComposerLane[]>([]);
  private readonly pickedNetwork = signal<SocialNetworkName | null>(null);

  readonly lanes = this.built.asReadonly();
  readonly active = computed(() => this.built().filter((lane) => lane.active()));
  /** The active lanes still to post, so a retry never reaches a network that took the post. */
  readonly pending = computed(() => this.active().filter((lane) => lane.sent() === null));
  readonly sentPosts = computed<SentPost[]>(() =>
    this.built().flatMap((lane) => lane.sent() ?? []),
  );
  readonly showsPills = computed(() => this.built().length > 1);
  /** The lane of the network the composer was opened for, whose context gives the starting text. */
  readonly picked = computed(() => {
    const picked = this.built().find((lane) => lane.network === this.pickedNetwork());
    if (picked === undefined) throw new Error('ComposerLanes.init has not run');
    return picked;
  });
  /** The lane whose subject the dialog shows: the first active one with a context, the picked one ahead. */
  readonly primary = computed(() => {
    const picked = this.picked();
    const candidates = picked.active() ? [picked, ...this.active()] : this.active();
    return candidates.find((lane) => lane.context() !== null) ?? candidates[0] ?? picked;
  });

  constructor() {
    inject(DestroyRef).onDestroy(() => this.dispose());
  }

  init(request: ComposeRequest): void {
    this.dispose();
    this.pickedNetwork.set(request.network);
    const lanes = runInInjectionContext(this.injector, () =>
      this.networksFor(request).map((network) => new ComposerLane({ ...request, network })),
    );
    for (const lane of lanes) lane.loadFailed.subscribe(() => this.switchOff(lane));
    this.built.set(lanes);
    this.picked().activate();
  }

  /** A control's id, suffixed by its lane's network while several lanes show their own. */
  controlId(control: string, lane: ComposerLane): string {
    return this.showsPills() ? `compose-${control}-${lane.network}` : `compose-${control}`;
  }

  /** Switches a lane on or off; the last active lane stays on, and a sent one stays as it is. */
  toggle(network: SocialNetworkName): void {
    const lane = this.built().find((candidate) => candidate.network === network);
    if (lane === undefined || lane.sent() !== null) return;
    if (!lane.active()) {
      lane.activate();
      return;
    }
    this.switchOff(lane);
  }

  /** Posts every pending lane at once and answers once all have; each lane keeps its own outcome. */
  sendAll(draft: SharedDraft, text: string): Observable<void> {
    return defer(() =>
      forkJoin(
        this.pending().map((lane) => lane.send(draft, text).pipe(catchError(() => of(null)))),
      ),
    ).pipe(map(() => undefined));
  }

  private networksFor(request: ComposeRequest): SocialNetworkName[] {
    if (!crossposts(request)) return [request.network];
    const connected = this.postNetworks.connected().map(({ network }) => network);
    return connected.includes(request.network) ? connected : [request.network];
  }

  private switchOff(lane: ComposerLane): void {
    if (this.active().length > 1) lane.deactivate();
  }

  private dispose(): void {
    for (const lane of this.built()) lane.dispose();
  }
}
