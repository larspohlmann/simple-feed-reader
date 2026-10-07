# List-view audio controls — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An audio entry's list row can be played and added to the playlist without opening the article (#1436).

**Architecture:** One signal-based helper, `EntryAudio` (`reader/audio/entry-audio.ts`), turns an entry into its track and its player state (queued, playing) and owns the three actions (play, play/pause, toggle queued). The article view's Listen row and the list's row controls both use it, so the toggle logic stops living in the reader view. The row controls render inside `app-entry-actions`, the one action cluster every list layout (list, pane, magazine blocks) already renders, so they inherit its spacing and tap-target math. The reader view, which renders the same cluster but has its own Listen row, opts out.

**Tech Stack:** Angular 20 (signals, standalone), Jest (jsdom), Playwright.

**Spec:** GitHub issue #1436.

## Rulings

- **Placement:** the two buttons sit first inside `app-entry-actions` (left of favorite / keep / read) instead of being a sibling component. The three list hosts lay the cluster out differently (`.tagline` flex, `app-entry-meta`, projected into the compact kicker's `<p>`), and only inside the cluster do the gap and the coarse-pointer padding already hold.
- **Opt-out:** `app-entry-actions` takes `audio = input(true)`; the reader view passes `false`, since its Listen / Add to playlist row already offers both.
- **Play control:** `play_arrow` → `AudioPlayerService.play(track)`; while this entry is the current track and playing, it shows `pause` and clicking calls `toggle()`. `aria-label` follows (`reader.audioPlayer.play` / `pause`); `aria-pressed` is not used, since play/pause is an action, not a flag.
- **Queue control:** `playlist_add` ↔ `playlist_add_check`, `[appFlagToggle]` = queued (so it reads like the other flags), label `addToPlaylist` / `inPlaylist`; click enqueues or dequeues.
- **No row side effects:** both buttons stop click and Enter/Space propagation, like the existing three.
- **Icon-only** with accessible names; glyph size follows the cluster's `size()`.

## Global Constraints

- Standalone components, signals; component styles in sibling `.scss`.
- No hex colours, no ad-hoc `px` (Stylelint).
- Prettier 100 columns; `npm run check:static && npx jest --maxWorkers=3` green inside the Docker frontend container.
- Comments only where a reader would get the code wrong without them.
- Commit format `feat(#1436): lower-case summary`, no attribution lines.

---

### Task 1: `EntryAudio`, and the reader view on it

**Files:** Create `frontend/src/app/reader/audio/entry-audio.ts` (+ spec). Modify `article/reader-view/reader-view.component.{ts,html}`.

```ts
export class EntryAudio {
  constructor(entry: () => EntryDto | null | undefined, player: AudioPlayerService);
  readonly attachment: Signal<EntryAttachmentDto | null>;
  readonly track: Signal<AudioTrack | null>;
  readonly queued: Signal<boolean>;
  readonly playing: Signal<boolean>;   // this track is current and playing
  play(): void;                        // Listen
  togglePlaying(): void;               // pause when playing, else play()
  toggleQueued(): void;
}
```

**Tests:** non-audio entry → null track, actions no-op; play / togglePlaying (pause when playing this track, play when another track plays) / toggleQueued both ways; queued and playing follow the service. The existing reader-view specs stay green unchanged.

### Task 2: Row controls in `app-entry-actions`

**Files:** Modify `entry/entry-actions/entry-actions.component.{ts,html,spec.ts}`; the reader view passes `[audio]="false"`.

**Tests:** non-audio entry renders three buttons; audio entry renders play + queue first; play click calls `play` and never opens the card (click, Enter, Space); shows pause while playing and clicking pauses; queue toggles and shows "In playlist"; `[audio]="false"` hides both.

### Task 3: Playwright

**Files:** Modify `frontend/e2e/audio-playlist.spec.ts`: a test that adds an episode from the list row and plays another from its row, asserting the URL never leaves the list and the bar shows the played title with the playlist count.
