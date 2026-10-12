# List Short referrer (#1594) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A YouTube Short played inline in the magazine list plays on an iPhone instead of failing with YouTube error 153, and single-item list media get a lower height cap.

**Architecture:** iOS WebKit honours an iframe's `referrerpolicy` only when `src` is set before the frame enters the DOM; otherwise the document-wide `<meta name="referrer" content="no-referrer">` (#497) wins and YouTube gets no Referer. The article builds its frames imperatively (src first) and works; the list binds `[src]` on a template `<iframe>`, which Angular always sets after insertion. The fix routes the list player through the article's builder: `media-embeds.ts` exports `embedFrame(url, title)` returning a detached, fully attributed iframe, and a new `PlayingEmbedDirective` appends it into its host element. `EmbedFrameDirective` is deleted, so every embed frame comes from one builder.

**Tech Stack:** Angular 20 (standalone, signals), Jest (jsdom), SCSS.

**Spec:** issue #1594 (cause, evidence and fix are in its body).

## Global Constraints

- Frontend tests run in the Docker frontend container: `docker compose exec -T frontend npm test -- <path>`; the CI gate is `docker compose exec -T frontend npm run check`.
- Commit format: `type(#1594): subject`.
- Comments: default to none; the only one this plan adds is the iOS invariant on the directive (a reader would get it wrong without it).
- Component styles stay in the sibling `.scss`; no hex colours or ad-hoc `px` outside `src/app/theme/`.
- The iOS behaviour cannot be reproduced in jsdom or the iOS simulator; the final check is Lars tapping a Short in the list on his iPhone.

---

### Task 1: The list's Short player is built by the shared embed builder

**Files:**
- Modify: `frontend/src/app/reader/media/media-embeds.ts`
- Create: `frontend/src/app/reader/media/playing-embed.directive.ts`
- Delete: `frontend/src/app/reader/embed-frame.directive.ts`
- Modify: `frontend/src/app/reader/list/magazine/blocks/entry-media/entry-media.component.ts`
- Modify: `frontend/src/app/reader/list/magazine/blocks/entry-media/entry-media.component.html:15-25`
- Modify: `frontend/src/app/reader/list/magazine/blocks/entry-media/entry-media.component.scss:85-87`
- Test: `frontend/src/app/reader/list/magazine/blocks/entry-media/entry-media.component.spec.ts`

**Interfaces:**
- Produces: `export function embedFrame(url: string, title: string): HTMLIFrameElement` in `media-embeds.ts` — a detached iframe with `src`, `title`, `loading="lazy"` and every `EMBED_FRAME_ATTRIBUTES` entry set.
- Produces: `PlayingEmbedDirective`, selector `[appPlayingEmbed]`, inputs `appPlayingEmbed: string` (the player URL, required) and `embedTitle: string` (required).

- [ ] **Step 1: Write the failing tests**

In `entry-media.component.spec.ts`, add inside the `describe` block, after the test `'plays a Short inline in the allow-listed player without opening the post'`:

```ts
  it('sets a Short player’s src before the frame enters the document, so iOS keeps its referrer policy', async () => {
    const connectedAtSrc: boolean[] = [];
    const srcSetter = Object.getOwnPropertyDescriptor(HTMLIFrameElement.prototype, 'src')!.set!;
    const setAttribute = Element.prototype.setAttribute;
    const srcProperty = jest
      .spyOn(HTMLIFrameElement.prototype, 'src', 'set')
      .mockImplementation(function (this: HTMLIFrameElement, value: string) {
        connectedAtSrc.push(this.isConnected);
        srcSetter.call(this, value);
      });
    const srcAttribute = jest
      .spyOn(Element.prototype, 'setAttribute')
      .mockImplementation(function (this: Element, name: string, value: string) {
        if (this instanceof HTMLIFrameElement && name === 'src') connectedAtSrc.push(this.isConnected);
        setAttribute.call(this, name, value);
      });
    const fixture = await mountFixture(short());
    const element = fixture.nativeElement as HTMLElement;
    expect(element.isConnected).toBe(true);

    element.querySelector<HTMLButtonElement>('.play')!.click();
    fixture.detectChanges();
    srcProperty.mockRestore();
    srcAttribute.mockRestore();

    expect(element.querySelector('iframe')).not.toBeNull();
    expect(connectedAtSrc).not.toHaveLength(0);
    expect(connectedAtSrc).not.toContain(true);
  });
```

In the existing test `'plays a Short inline in the allow-listed player without opening the post'`, add after the `allowfullscreen` expectation:

```ts
    expect(frame.getAttribute('allow')).toBe('autoplay; fullscreen');
```

- [ ] **Step 2: Run the tests to verify the new one fails**

Run: `docker compose exec -T frontend npm test -- src/app/reader/list/magazine/blocks/entry-media/entry-media.component.spec.ts`
Expected: the new test FAILS on `expect(connectedAtSrc).not.toContain(true)` (Angular sets the bound `src` property on an already-inserted frame). The `allow` expectation passes already. If the new test passes instead, the fixture is not attached to the document — stop and report; the guard would prove nothing.

- [ ] **Step 3: Export the builder from `media-embeds.ts`**

Replace the private `embedFrame` (the last function in the file) with a box builder plus an exported frame builder, and call the box builder from `upgradeMediaEmbeds`:

```ts
export function upgradeMediaEmbeds(host: HTMLElement): void {
  for (const anchor of Array.from(host.querySelectorAll('a'))) {
    const url = anchor.getAttribute('href') ?? '';
    const frame = ALLOWED.find(({ pattern }) => pattern.test(url));
    if (!frame) continue;
    anchor.replaceWith(embedBox(url, frame, anchor.textContent?.trim() || 'Embedded media'));
  }
}
```

```ts
function embedBox(url: string, player: EmbedPlayer, title: string): HTMLElement {
  const box = document.createElement('div');
  box.classList.add(...boxClasses(player));
  box.appendChild(embedFrame(url, title));

  return box;
}

/** An embed frame, still detached: insert it only once built, since iOS WebKit honours `referrerpolicy` only on a frame whose src precedes insertion (#1594). */
export function embedFrame(url: string, title: string): HTMLIFrameElement {
  const frame = document.createElement('iframe');
  frame.setAttribute('src', url);
  frame.setAttribute('title', title);
  frame.setAttribute('loading', 'lazy');
  for (const [name, value] of Object.entries(EMBED_FRAME_ATTRIBUTES)) {
    frame.setAttribute(name, value);
  }

  return frame;
}
```

Change the doc comment on `EMBED_FRAME_ATTRIBUTES` from "in the article and in the list alike" to "set by `embedFrame`".

- [ ] **Step 4: Create `frontend/src/app/reader/media/playing-embed.directive.ts`**

```ts
import { Directive, ElementRef, effect, inject, input } from '@angular/core';
import { embedFrame } from './media-embeds';

/** Fills its host with a player that starts on load; the frame comes whole from `embedFrame`, never a template `[src]` (#1594). */
@Directive({ selector: '[appPlayingEmbed]' })
export class PlayingEmbedDirective {
  readonly appPlayingEmbed = input.required<string>();
  readonly embedTitle = input.required<string>();
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef).nativeElement;

  constructor() {
    effect(() => {
      const frame = embedFrame(this.appPlayingEmbed(), this.embedTitle());
      frame.setAttribute('allow', 'autoplay; fullscreen');
      this.host.replaceChildren(frame);
    });
  }
}
```

- [ ] **Step 5: Switch the list component to the directive**

In `entry-media.component.html`, replace the `<iframe … ></iframe>` inside `@if (playingShort())` with:

```html
          <div
            class="item frame"
            [appPlayingEmbed]="player"
            [embedTitle]="headline() || ('reader.playShort' | transloco)"
          ></div>
```

In `entry-media.component.ts`:
- replace the import of `EmbedFrameDirective` with `import { PlayingEmbedDirective } from '../../../../media/playing-embed.directive';` and put `PlayingEmbedDirective` in `imports` where `EmbedFrameDirective` was;
- remove the `DomSanitizer` import and the `sanitizer` field;
- replace the `shortPlayer` computed and the comment above it with:

```ts
  readonly shortPlayer = computed(() => autoplayingShortPlayer(this.entry().shortPlayerUrl));
```

(`autoplayingShortPlayer` admits only allow-listed URLs, and the directive sets the URL as a DOM attribute, so no Angular resource-URL trust is involved any more.)

In `entry-media.component.scss`, replace

```scss
iframe.item {
  border: 0;
}
```

with

```scss
.frame iframe {
  display: block;
  width: 100%;
  height: 100%;
  border: 0;
}
```

Delete `frontend/src/app/reader/embed-frame.directive.ts`, then confirm nothing references it: `grep -rn "EmbedFrameDirective\|appEmbedFrame" frontend/src` → no output.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `docker compose exec -T frontend npm test -- src/app/reader/list/magazine/blocks/entry-media src/app/reader/media src/app/reader/article/decorators`
Expected: all PASS, including the new order test and the existing play / pause / scroll-away / title tests (they query `iframe`, which is now the directive's child).

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/reader
git commit -m "fix(#1594): the list builds its Short player through the shared embed builder, src before insertion"
```

---

### Task 2: Lower the single-item media height cap

**Files:**
- Modify: `frontend/src/app/theme/tokens.scss:104`

- [ ] **Step 1: Change the token**

```scss
  --media-share-max-h: 480px;
```

(was `560px`; a 9:16 Short always meets the cap, so its card gets 80px shorter. A portrait image in a Media block shrinks with it — the cap is shared by every single-item share.)

- [ ] **Step 2: Commit**

```bash
git add frontend/src/app/theme/tokens.scss
git commit -m "style(#1594): lower the single-item media cap to 480px"
```

---

### Task 3: Gate and device check

- [ ] **Step 1: Run the CI gate**

Run: `docker compose exec -T frontend npm run check`
Expected: ESLint, Prettier, Stylelint, tsc and Jest all green.

- [ ] **Step 2: Hand over the device check**

Ask Lars to open the YouTube feed in list view on his iPhone (Safari and Brave), tap a Short's play button, and confirm it plays with no error 153 and that the card is shorter. Do not claim the fix works on iOS before he confirms.
