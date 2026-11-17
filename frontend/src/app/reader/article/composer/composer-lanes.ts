import {
  DestroyRef,
  Injectable,
  Injector,
  computed,
  inject,
  runInInjectionContext,
  signal,
} from '@angular/core';
import { LINK_POST_NETWORKS, SocialNetworkName } from '../../../core/social-network';
import { PostTargetDto } from '../../entry-social';
import { PostNetworks } from '../share/post-networks.service';
import { ComposerLane } from './composer-lane';

/** The lanes of one open composer: a link post gets one per connected link network, anything else one. */
@Injectable()
export class ComposerLanes {
  private readonly injector = inject(Injector);
  private readonly postNetworks = inject(PostNetworks);
  private readonly built = signal<readonly ComposerLane[]>([]);
  private readonly pickedNetwork = signal<SocialNetworkName | null>(null);

  readonly lanes = this.built.asReadonly();
  readonly active = computed(() => this.built().filter((lane) => lane.active()));
  readonly showsPills = computed(() => this.built().length > 1);
  /** The lane of the network the composer was opened for, whose context gives the starting text. */
  readonly picked = computed(() => {
    const picked = this.built().find((lane) => lane.network === this.pickedNetwork());
    if (picked === undefined) throw new Error('ComposerLanes.init has not run');
    return picked;
  });
  /** The lane whose subject the dialog shows. */
  readonly primary = computed(() => {
    const picked = this.picked();
    return picked.active() ? picked : (this.active()[0] ?? picked);
  });

  constructor() {
    inject(DestroyRef).onDestroy(() => this.dispose());
  }

  init(target: PostTargetDto): void {
    this.dispose();
    this.pickedNetwork.set(target.network);
    this.built.set(this.networksFor(target).map((network) => this.laneFor({ ...target, network })));
    this.picked().activate();
  }

  /** Switches a lane on or off; the last active lane stays on. */
  toggle(network: SocialNetworkName): void {
    const lane = this.built().find((candidate) => candidate.network === network);
    if (lane === undefined) return;
    if (!lane.active()) {
      lane.activate();
      return;
    }
    if (this.active().length > 1) lane.deactivate();
  }

  private laneFor(target: PostTargetDto): ComposerLane {
    return runInInjectionContext(
      this.injector,
      () => new ComposerLane(target, (lane) => this.dropFailed(lane)),
    );
  }

  private networksFor(target: PostTargetDto): SocialNetworkName[] {
    if (target.kind !== 'link') return [target.network];
    const connected = this.postNetworks.connected().map(({ network }) => network);
    return LINK_POST_NETWORKS.filter(
      (network) => network === target.network || connected.includes(network),
    );
  }

  private dropFailed(lane: ComposerLane): void {
    if (this.active().length > 1) lane.deactivate();
  }

  private dispose(): void {
    for (const lane of this.built()) lane.dispose();
  }
}
