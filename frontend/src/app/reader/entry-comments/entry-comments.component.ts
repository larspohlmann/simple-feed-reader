import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  NgZone,
  computed,
  effect,
  inject,
  input,
  signal,
} from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../shared/icon/icon.component';
import { IconButtonDirective } from '../../shared/icon-button/icon-button.directive';
import { ListActionDirective } from '../../shared/list-action/list-action.directive';
import { SkeletonComponent } from '../../shared/skeleton/skeleton.component';
import { WarningBoxComponent } from '../../shared/warning-box/warning-box.component';
import { LanguageService } from '../../core/language.service';
import { CommentsService } from '../comments.service';
import { relativeTime } from '../format';
import { EntryDto } from '../models';
import { nearestScroller } from '../nearest-scroller';

type CommentsEntry = Pick<EntryDto, 'id' | 'comments' | 'discussionUrl'>;

const LOOKAHEAD = '400px 0px';

@Component({
  selector: 'app-entry-comments',
  imports: [
    TranslocoPipe,
    IconComponent,
    IconButtonDirective,
    ListActionDirective,
    SkeletonComponent,
    WarningBoxComponent,
  ],
  templateUrl: './entry-comments.component.html',
  styleUrl: './entry-comments.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class EntryCommentsComponent {
  readonly entry = input.required<CommentsEntry>();

  private readonly service = inject(CommentsService);
  private readonly language = inject(LanguageService);
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly zone = inject(NgZone);
  private readonly now = signal(Date.now());
  private readonly entryId = computed(() => this.entry().id);
  private readonly loadsOnSight = computed(() => this.entry().comments === 'auto');

  readonly state = computed(() => this.service.state(this.entryId())());
  readonly retryInSeconds = computed(() => {
    const state = this.state();
    if (state.status !== 'throttled') return 0;
    return Math.max(0, Math.ceil((state.retryAt - this.now()) / 1000));
  });

  constructor() {
    effect((onCleanup) => {
      if (!this.loadsOnSight()) return;
      onCleanup(this.loadOnSight(this.entryId()));
    });
    effect((onCleanup) => {
      if (this.state().status !== 'throttled') return;
      onCleanup(this.tickEverySecond());
    });
  }

  load(): void {
    this.service.load(this.entryId());
  }

  reload(): void {
    this.service.reload(this.entryId());
  }

  when(iso: string): string {
    return relativeTime(iso, this.language.lang());
  }

  private loadOnSight(id: number): () => void {
    const host = this.host.nativeElement;
    const observer = new IntersectionObserver(
      ([sighting]) => {
        if (sighting?.isIntersecting) this.service.load(id);
      },
      { root: nearestScroller(host), rootMargin: LOOKAHEAD },
    );
    observer.observe(host);
    return () => observer.disconnect();
  }

  // Outside the zone: an in-zone interval never lets whenStable() settle.
  private tickEverySecond(): () => void {
    this.now.set(Date.now());
    const ticker = this.zone.runOutsideAngular(() =>
      setInterval(() => this.now.set(Date.now()), 1000),
    );
    return () => clearInterval(ticker);
  }
}
