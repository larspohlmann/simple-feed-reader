import {
  DestroyRef,
  ElementRef,
  Injectable,
  computed,
  effect,
  inject,
  signal,
  untracked,
} from '@angular/core';
import { ListAutoplayService } from '../../../core/preferences/list-autoplay.service';
import { AutoplayPlayer } from './autoplay-player';
import { UnlockedVideoElements } from './unlocked-video-elements';

const VISIBLE_ENOUGH = 0.6;
const THRESHOLDS = [0, 0.2, 0.4, 0.6, 0.8, 1];

/** Plays the list's most visible video, one at a time. Provided by the entry list, so nothing outside it autoplays. */
@Injectable()
export class ListAutoplayCoordinator {
  private readonly setting = inject(ListAutoplayService);
  private readonly unlockedVideos = inject(UnlockedVideoElements);
  private readonly suspended = signal(false);
  private readonly active = computed(() => this.setting.enabled() && !this.suspended());
  private readonly players = new Map<Element, AutoplayPlayer>();
  private readonly visibility = new Map<Element, number>();
  private readonly observer =
    typeof IntersectionObserver === 'undefined'
      ? null
      : new IntersectionObserver((changes) => this.measure(changes), { threshold: THRESHOLDS });
  private current: { player: AutoplayPlayer; byHand: boolean } | null = null;
  private soundOn = false;

  constructor() {
    effect(() => {
      const active = this.active();
      untracked(() => (active ? this.choose() : this.stopCurrent()));
    });
    inject(DestroyRef).onDestroy(() => this.observer?.disconnect());
    // Capture phase: rows and the video slot stop propagation.
    inject(ElementRef<HTMLElement>, { optional: true })?.nativeElement.addEventListener(
      'click',
      () => this.tapped(),
      { capture: true },
    );
  }

  register(player: AutoplayPlayer): void {
    this.players.set(player.element, player);
    this.observer?.observe(player.element);
  }

  unregister(player: AutoplayPlayer): void {
    this.players.delete(player.element);
    this.visibility.delete(player.element);
    this.observer?.unobserve(player.element);
    if (this.current?.player !== player) return;
    this.stopCurrent();
    this.choose();
  }

  nowPlaying(player: AutoplayPlayer): void {
    if (this.current?.player === player) return;
    this.current?.player.stop();
    this.current = { player, byHand: true };
  }

  soundChanged(soundOn: boolean): void {
    this.soundOn = soundOn;
  }

  /** Must run synchronously inside a tap's event handler. */
  tapped(): void {
    for (const player of this.players.values()) player.unlockSound?.();
    this.unlockedVideos.refill();
  }

  suspend(): void {
    this.suspended.set(true);
  }

  resume(): void {
    this.suspended.set(false);
  }

  private measure(changes: IntersectionObserverEntry[]): void {
    for (const change of changes) this.visibility.set(change.target, change.intersectionRatio);
    this.choose();
  }

  private choose(): void {
    if (!this.active()) return;
    if (this.current && this.holdsOn(this.current)) return;
    const candidate = this.mostVisible();
    if (candidate === (this.current?.player ?? null)) return;
    this.stopCurrent();
    if (candidate === null) return;
    this.current = { player: candidate, byHand: false };
    candidate.start(this.soundOn);
  }

  private holdsOn({ player, byHand }: { player: AutoplayPlayer; byHand: boolean }): boolean {
    const ratio = this.visibility.get(player.element) ?? 0;
    return byHand ? ratio > 0 : ratio >= VISIBLE_ENOUGH;
  }

  private mostVisible(): AutoplayPlayer | null {
    let best: AutoplayPlayer | null = null;
    let bestRatio = 0;
    for (const [element, ratio] of this.visibility) {
      const player = this.players.get(element);
      if (!player || !element.isConnected || ratio < VISIBLE_ENOUGH || ratio <= bestRatio) continue;
      best = player;
      bestRatio = ratio;
    }
    return best;
  }

  private stopCurrent(): void {
    this.current?.player.stop();
    this.current = null;
  }
}
