# Image Alt Text Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Capture the alt text Bluesky, Mastodon and Media RSS feeds declare for their pictures, and show it behind an "ALT" badge in the reader.

**Architecture:** Alt text already travels `ParsedMediumModel::$alt` → `EntryMedium::$alt` → `EntryMediumDto.alt`. Three ingest paths drop it (Mastodon API images, Bluesky video, Media RSS `<media:description>`); each gets the field filled. In the frontend, every cell of `app-post-media` becomes a wrapper so a new `app-alt-text` badge can sit over an image or GIF; a video takes its alt as its accessible label.

**Tech Stack:** Symfony 7.4 / PHP 8.4 (PHPUnit 12), Angular 20 standalone + signals (Jest), Transloco.

**Spec:** GitHub issue #1827.

## Global Constraints

- Only the media element's **own** `<media:description>` is alt text; a `<media:group>`'s description is the item body (YouTube) and must not become alt.
- `type="html"` descriptions are reduced to plain text with `PlainText::fromHtmlBlocks()`; plain ones are trimmed; empty → `null`.
- Alt text is rendered as text (Angular interpolation), never as HTML.
- A tap on the badge or the text never reaches the parent (rows open the entry, GIFs toggle playback, quoted posts open the post).
- No hex colours or ad-hoc `px` outside `src/app/theme/`; component styles in a sibling `.scss`.
- Commits: `type(#1827): summary`. Clean Code rules of CLAUDE.md apply; no new comments unless they clear the bar.

---

### Task 1: Mastodon images and Bluesky videos keep their alt text

**Files:**
- Modify: `backend/src/Service/Social/Support/MastodonAttachments.php` (`mediumOf`, image arm)
- Modify: `backend/src/Service/Bluesky/Support/BlueskyEmbedMedia.php` (`video`)
- Test: `backend/tests/Service/Social/Support/MastodonAttachmentsTest.php`
- Test: `backend/tests/Service/Bluesky/PostEmbedRendererTest.php`

**Interfaces:** Produces `ParsedMediumModel::$alt` on these two paths; no signature changes.

- [ ] **Step 1: Failing tests**

`MastodonAttachmentsTest`:

```php
    public function testAnImageKeepsItsDescriptionAsAltText(): void
    {
        $media = MastodonAttachments::of([
            JsonNodeModel::of([
                'type' => 'image',
                'url' => 'https://files.example/a.jpg',
                'description' => 'A donkey in a field',
            ]),
        ]);

        self::assertSame('A donkey in a field', $media[0]->alt);
    }
```

`PostEmbedRendererTest`, next to `testAVideoWithoutAThumbnailHasNoPreviewAndNoLeadImage`:

```php
    public function testAVideoKeepsItsAltText(): void
    {
        $rendered = $this->renderedPost([
            '$type' => 'app.bsky.embed.video#view',
            'playlist' => 'https://video.example/p.m3u8',
            'alt' => 'A donkey trots past',
        ]);

        self::assertSame('A donkey trots past', $rendered->media[0]->alt);
    }
```

- [ ] **Step 2:** `cd backend && php bin/phpunit --filter 'testAnImageKeepsItsDescriptionAsAltText|testAVideoKeepsItsAltText'` → both FAIL (null).

- [ ] **Step 3: Implement**

`MastodonAttachments::mediumOf`, image arm:

```php
            'image' === $type => new ParsedMediumModel(
                $url,
                VisualMediaKind::Image,
                $size->int('width'),
                $size->int('height'),
                alt: $attachment->string('description'),
            ),
```

`BlueskyEmbedMedia::video`:

```php
        return new ParsedMediumModel(
            $playlist,
            VisualMediaKind::Video,
            $ratio->int('width'),
            $ratio->int('height'),
            HttpsImageUrl::orNull($embed->string('thumbnail')),
            $embed->string('alt'),
        );
```

- [ ] **Step 4:** rerun the filter → PASS; run the two whole test classes → PASS.
- [ ] **Step 5:** `git commit -m "feat(#1827): mastodon images and bluesky videos keep their alt text"`

### Task 2: A Media RSS element's own description is its alt text

**Files:**
- Modify: `backend/src/Service/Parser/Pass/FeedMediaNode.php` (`toImage`, `toVideo`, new private `alt()`)
- Test: `backend/tests/Service/Parser/ItemMediaExtractorTest.php`

**Interfaces:** Consumes `CoreElement::extension($element, FeedNamespace::MediaRss)->child('description')` (already used by `title()`), `PlainText::fromHtmlBlocks()` (`App\Service\Text\Support\PlainText`).

- [ ] **Step 1: Failing tests**

```php
    public function testAnImagesOwnMediaDescriptionIsItsAltText(): void
    {
        $bundle = $this->extractor->extract($this->rssItem(
            '<media:content url="https://i/one.jpg" medium="image">'
            . '<media:description type="plain"> A donkey &lt;3 </media:description>'
            . '</media:content>'
            . '<media:content url="https://i/two.jpg" medium="image">'
            . '<media:description type="html">&lt;p&gt;Two &lt;b&gt;donkeys&lt;/b&gt;&lt;/p&gt;</media:description>'
            . '</media:content>'
            . '<media:content url="https://i/three.jpg" medium="image"><media:description> </media:description>'
            . '</media:content>',
        ));

        self::assertSame(
            ['A donkey <3', 'Two donkeys', null],
            array_map(static fn ($medium) => $medium->alt, $bundle->media),
        );
    }

    public function testAVideosOwnMediaDescriptionIsItsAltText(): void
    {
        $bundle = $this->extractor->extract($this->rssItem(
            '<media:content url="https://v/clip.mp4" medium="video" type="video/mp4">'
            . '<media:description>A donkey trots past</media:description>'
            . '</media:content>',
        ));

        self::assertSame('A donkey trots past', $bundle->media[0]->alt);
    }

    public function testAGroupsDescriptionIsNoAltText(): void
    {
        $bundle = $this->extractor->extract($this->rssItem(
            '<media:group>'
            . '<media:content url="https://i/photo.jpg" medium="image" width="800"/>'
            . '<media:description>The whole video description</media:description>'
            . '</media:group>',
        ));

        self::assertNull($bundle->media[0]->alt);
    }
```

- [ ] **Step 2:** `php bin/phpunit --filter 'MediaDescriptionIsItsAltText|GroupsDescriptionIsNoAltText'` → the first two FAIL, the group one passes (guard).

- [ ] **Step 3: Implement** in `FeedMediaNode`:

```php
    public function toImage(): ParsedMediumModel
    {
        return new ParsedMediumModel(
            $this->url(),
            VisualMediaKind::Image,
            $this->width(),
            $this->intAttribute('height'),
            alt: $this->alt(),
        );
    }

    public function toVideo(?string $previewImageUrl): ParsedMediumModel
    {
        return new ParsedMediumModel(
            $this->url(),
            VisualMediaKind::Video,
            $this->width(),
            $this->intAttribute('height'),
            $previewImageUrl,
            $this->alt(),
        );
    }

    private function alt(): ?string
    {
        $description = CoreElement::extension($this->element, FeedNamespace::MediaRss)->child('description');
        if ($description === null) {
            return null;
        }

        return $description->getAttribute('type') === 'html'
            ? PlainText::fromHtmlBlocks($description->textContent)
            : self::nonEmpty($description->textContent);
    }
```

Add `use App\Service\Text\Support\PlainText;` (the Parser module already depends on Text).

- [ ] **Step 4:** rerun → PASS; `php bin/phpunit tests/Service/Parser` → PASS.
- [ ] **Step 5:** `git commit -m "feat(#1827): a media rss element's own description is its alt text"`

### Task 3: An ALT badge over described pictures

**Files:**
- Create: `frontend/src/app/reader/media/alt-text/alt-text.component.ts`, `.html`, `.scss`, `.spec.ts`
- Modify: `frontend/src/app/reader/media/post-media/post-media.component.html`, `.ts`, `.scss`, `.spec.ts`
- Modify: `frontend/src/app/theme/tokens.scss` (one token)
- Modify: `frontend/public/i18n/en.json`, `de.json` (`reader.media`)

**Interfaces:** Consumes `EntryMediumDto.alt?: string`. Produces `<app-alt-text [text]="…" />`.

- [ ] **Step 1: i18n and token**

`reader.media` in `en.json`: `"alt": "ALT", "showAlt": "Show image description", "hideAlt": "Hide image description"`; in `de.json`: `"alt": "ALT", "showAlt": "Bildbeschreibung anzeigen", "hideAlt": "Bildbeschreibung ausblenden"`.

`tokens.scss`, under `--on-media-scrim`: `--media-scrim-strong: rgb(0 0 0 / 80%);` and extend the comment above the pair to cover it ("…and the text panel laid over one").

- [ ] **Step 2: Failing component spec** `alt-text.component.spec.ts`:

```ts
import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { AltTextComponent } from './alt-text.component';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';

@Component({
  imports: [AltTextComponent],
  template: `<app-alt-text text="A donkey in a field" />`,
})
class HostComponent {}

async function mount() {
  TestBed.configureTestingModule({ imports: [HostComponent, provideTranslocoTesting()] });
  const fixture = TestBed.createComponent(HostComponent);
  const parentClicks = jest.fn();
  fixture.nativeElement.addEventListener('click', parentClicks);
  fixture.detectChanges();
  await fixture.whenStable();
  const badge = fixture.nativeElement.querySelector('button') as HTMLButtonElement;
  const panel = () => fixture.nativeElement.querySelector('.text') as HTMLElement | null;
  return { fixture, parentClicks, badge, panel };
}

describe('AltTextComponent', () => {
  it('shows a collapsed ALT badge', async () => {
    const { badge, panel } = await mount();

    expect(badge.textContent?.trim()).toBe('ALT');
    expect(badge.getAttribute('aria-expanded')).toBe('false');
    expect(badge.getAttribute('aria-label')).toBe('Show image description');
    expect(panel()).toBeNull();
  });

  it('opens the text on a tap and closes it on a tap of the text', async () => {
    const { fixture, badge, panel } = await mount();

    badge.click();
    fixture.detectChanges();
    expect(panel()?.textContent?.trim()).toBe('A donkey in a field');
    expect(badge.getAttribute('aria-expanded')).toBe('true');
    expect(badge.getAttribute('aria-label')).toBe('Hide image description');

    panel()!.click();
    fixture.detectChanges();
    expect(panel()).toBeNull();
  });

  it('closes on Escape', async () => {
    const { fixture, badge, panel } = await mount();
    badge.click();
    fixture.detectChanges();

    badge.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    fixture.detectChanges();

    expect(panel()).toBeNull();
  });

  it('keeps its taps from reaching the parent', async () => {
    const { fixture, parentClicks, badge, panel } = await mount();

    badge.click();
    fixture.detectChanges();
    panel()!.click();

    expect(parentClicks).not.toHaveBeenCalled();
  });
});
```

- [ ] **Step 3:** `docker compose exec -T frontend npx jest src/app/reader/media/alt-text` → FAIL (module missing).

- [ ] **Step 4: Component**

`alt-text.component.ts`:

```ts
import { ChangeDetectionStrategy, Component, input, signal } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';

@Component({
  selector: 'app-alt-text',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslocoPipe],
  templateUrl: './alt-text.component.html',
  styleUrl: './alt-text.component.scss',
  host: { '(keydown.escape)': 'close($event)' },
})
export class AltTextComponent {
  readonly text = input.required<string>();
  protected readonly open = signal(false);

  protected toggle(event: Event): void {
    event.stopPropagation();
    this.open.update((open) => !open);
  }

  protected close(event: Event): void {
    if (!this.open()) return;
    event.stopPropagation();
    this.open.set(false);
  }
}
```

`alt-text.component.html` (the panel comes first so the badge paints above it and stays tappable):

```html
@if (open()) {
  <!-- eslint-disable-next-line @angular-eslint/template/click-events-have-key-events, @angular-eslint/template/interactive-supports-focus -->
  <p class="text" (click)="toggle($event)">{{ text() }}</p>
}
<button
  type="button"
  class="badge"
  [attr.aria-expanded]="open()"
  [attr.aria-label]="(open() ? 'reader.media.hideAlt' : 'reader.media.showAlt') | transloco"
  (click)="toggle($event)"
>
  {{ 'reader.media.alt' | transloco }}
</button>
```

`alt-text.component.scss`:

```scss
// Above a quoted post's stretched link (#1612), so a tap reads the text rather than opening the post.
:host {
  position: absolute;
  inset: 0;
  z-index: 1;
  pointer-events: none;
}

.badge,
.text {
  pointer-events: auto;
}

.badge {
  position: absolute;
  inset-block-end: var(--space-2);
  inset-inline-end: var(--space-2);
  padding: var(--space-0) var(--space-2);
  border: 0;
  border-radius: var(--radius-pill);
  background: var(--media-scrim);
  color: var(--on-media-scrim);
  font: inherit;
  font-size: var(--fs-xs);
  font-weight: 600;
  cursor: pointer;

  &:focus-visible {
    outline: var(--focus-ring);
  }
}

.text {
  position: absolute;
  inset: 0;
  margin: 0;
  padding: var(--space-3) var(--space-3) var(--space-6);
  overflow-y: auto;
  background: var(--media-scrim-strong);
  color: var(--on-media-scrim);
  font-size: var(--fs-sm);
  white-space: pre-line;
  cursor: pointer;
}
```

- [ ] **Step 5:** rerun the alt-text spec → PASS.

- [ ] **Step 6: Failing post-media specs** — add to `post-media.component.spec.ts`:

```ts
  it('puts an ALT badge on a described picture and GIF only', async () => {
    const { fixture } = await mount([
      { ...image(1), alt: 'A donkey' },
      image(2),
      { ...gif, alt: 'Clapping' },
    ]);
    const cells = Array.from(
      fixture.nativeElement.querySelectorAll('app-post-media > .cell') as NodeListOf<HTMLElement>,
    );

    expect(cells.map((cell) => cell.querySelector('app-alt-text') !== null)).toEqual([
      true,
      false,
      true,
    ]);
  });

  it('names a video by its alt text before the given label', async () => {
    const { fixture } = await mount([{ ...clip, alt: 'A donkey trots past' }]);

    expect(fixture.nativeElement.querySelector('video').getAttribute('aria-label')).toBe(
      'A donkey trots past',
    );
  });
```

Update the existing GIF test: query `app-post-media > .cell > .item` instead of `app-post-media > .item`; the tag list stays `['app-gif', 'app-gif', 'img']`.

- [ ] **Step 7:** run the post-media spec → the two new tests FAIL.

- [ ] **Step 8: Implement**

`post-media.component.html`:

```html
@for (medium of media(); track medium.url) {
  <div class="cell">
    @if (isGif(medium)) {
      <app-gif class="item" [medium]="medium" />
    } @else if (medium.kind === 'video') {
      <!-- eslint-disable-next-line @angular-eslint/template/interactive-supports-focus -->
      <div
        class="item video-slot"
        (click)="$event.stopPropagation()"
        (keydown)="$event.stopPropagation()"
      ></div>
    } @else {
      <img
        class="item"
        appProxiedImage
        [src]="medium.url"
        [attr.width]="medium.width ?? null"
        [attr.height]="medium.height ?? null"
        [attr.alt]="medium.alt ?? ''"
        loading="lazy"
        decoding="async"
        referrerpolicy="no-referrer"
      />
    }
    @if (medium.alt && (isGif(medium) || medium.kind === 'image')) {
      <app-alt-text [text]="medium.alt" />
    }
  </div>
}
```

`post-media.component.ts`: add `AltTextComponent` to `imports`; in `mountVideos`, `video.setAttribute('aria-label', clips[index].alt || label);`.

`post-media.component.scss`: add

```scss
.cell {
  position: relative;
  min-height: 0;
}
```

and keep `.item` on the media (it already fills its box with `width/height: 100%`). Check the `[data-count='3'] > :first-child` span still applies (it targets the cell now — correct).

- [ ] **Step 9:** run the post-media spec → PASS; then `docker compose exec -T frontend npm run check` and `npx ng build` (template errors) → PASS. Then the specs that read `app-post-media img` (entry-thread, reader-view) still pass inside the check.
- [ ] **Step 10:** `git commit -m "feat(#1827): an alt badge shows a picture's description"`

### Task 4: Gates and live check

- [ ] `cd backend && composer check && composer md && composer test:parallel`; MySQL leg `docker compose exec php composer test`.
- [ ] `composer infection:diff` after committing.
- [ ] Live check on `localhost:4200` (containers current): a Bluesky post with a described image, re-ingested, shows the badge; tapping toggles the text without opening the entry.
