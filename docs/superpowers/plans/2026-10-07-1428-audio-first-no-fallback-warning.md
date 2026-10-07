# Audio-first entries owe no fallback note — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An entry with a playable audio enclosure whose page holds no article shows its summary and the Listen button without the "Couldn't load the full article" warning (#1428).

**Architecture:** Frontend only. `ArticleSource` already holds the failure reason; it gains a `pageHoldsNoArticle` signal. `ReaderViewComponent` already knows the audio enclosure (`audioAttachment`); it gains `fallbackNoticeShown`, which the template's warning block reads in place of `source.failed() && mode() === 'original'`.

**Tech Stack:** Angular 20 signals, Jest.

**Spec:** GitHub issue #1428, settled by the rulings below.

## Rulings (the issue's open questions)

1. **Keep the fetch; hide the note.** Audited one audio entry from each of the 16 feeds in the dev DB that carry audio enclosures (`app:reader:audit --entries=…`). 14 extracted show notes, between 0.9k and 99k chars (Substack podcasts, Dwarkesh, Last Week in AI, …). Skipping the fetch would lose all of them.
2. **"No article" = `unextractable`, `empty`, `mismatch`.** All three are deterministic, so Retry cannot change them. `mismatch` fires only when the feed body is itself substantial (`ExtractionCoverageGate::SUBSTANTIAL_FEED_LENGTH`), so its fallback is already the full article. The audited Good News entry is exactly this case: a text article with a read-aloud mp3 and a 20k-char feed body. The note stays for `fetch` and for transport errors and timeouts (`failure === null`). `no_url` never reaches the failed state, because `open()` sets `idle`.
3. **The toggle needs no change.** Every failure calls `readerMode.setOriginalOnly()`, so the Reader/Original toggle is already hidden for these entries.

## Global Constraints

- Entries without audio behave exactly as today.
- Jest covers: audio with `empty`/`unextractable`/`mismatch` → no note; audio with `fetch` → note; audio with a transport error → note; no audio with `empty` → note.
- Frontend gate: `npm run check` (run tests inside the Docker frontend container).
- Commit format `type(#1428): lower-case summary`, no attribution lines.

---

### Task 1: Suppress the note for audio-first entries whose page holds no article

**Files:**
- Modify: `frontend/src/app/reader/article/content/article-source.service.ts`
- Modify: `frontend/src/app/reader/article/reader-view/reader-view.component.ts`
- Modify: `frontend/src/app/reader/article/reader-view/reader-view.component.html`
- Test: `frontend/src/app/reader/article/reader-view/reader-view.component.spec.ts`

- [ ] **Step 1: Write the failing tests.** Add them to the `reader fallback: retry and error detail` describe. Use the spec's existing `failedContent`, `entry` and `mount` helpers, plus an audio enclosure `{ url: 'https://x.test/ep.mp3', mimeType: 'audio/mpeg' }`:

```ts
    describe('on an audio-first entry', () => {
      const audioEntry = () =>
        entry({ attachments: [{ url: 'https://x.test/ep.mp3', mimeType: 'audio/mpeg' }] });

      it.each(['empty', 'unextractable', 'mismatch'] as const)(
        'shows the summary and player with no note when the page holds no article (%s)',
        (reason) => {
          loadMock.mockReturnValue(of<ReaderContent>(failedContent({ reason })));
          const element = mount(audioEntry()).nativeElement as HTMLElement;

          expect(element.querySelector('.reader-fallback')).toBeNull();
          expect(element.querySelector('.listen')).not.toBeNull();
          expect(element.querySelector('.content')!.innerHTML).toContain('Body');
        },
      );

      it('keeps the note with Retry when the page could not be fetched', () => {
        loadMock.mockReturnValue(of<ReaderContent>(failedContent({ reason: 'fetch' })));
        const element = mount(audioEntry()).nativeElement as HTMLElement;

        expect(element.querySelector('.reader-fallback .note-link')).not.toBeNull();
      });

      it('keeps the note when the load fails at the transport', () => {
        loadMock.mockReturnValue(throwError(() => new HttpErrorResponse({ status: 502 })));
        const element = mount(audioEntry()).nativeElement as HTMLElement;

        expect(element.querySelector('.reader-fallback')).not.toBeNull();
      });
    });

    it('keeps the note on an entry without audio whose page holds no article', () => {
      loadMock.mockReturnValue(of<ReaderContent>(failedContent({ reason: 'empty' })));
      const element = mount(entry()).nativeElement as HTMLElement;

      expect(element.querySelector('.reader-fallback')).not.toBeNull();
    });
```

- [ ] **Step 2: Run them and see the three `it.each` cases fail.** Run `docker compose exec -T frontend npx jest src/app/reader/article/reader-view`.

- [ ] **Step 3: Add `pageHoldsNoArticle` to `ArticleSource`.** Put the set at module level, next to `READER_LOAD_TIMEOUT_MS`, and the signal after `failed`:

```ts
/** Failures that say the page holds no article, which Retry cannot change. */
const NO_ARTICLE_REASONS: ReadonlySet<ReaderFailure['reason']> = new Set([
  'unextractable',
  'empty',
  'mismatch',
]);
```

```ts
  readonly pageHoldsNoArticle = computed(() => {
    const state = this.state();
    return state.status === 'failed' && state.failure !== null && NO_ARTICLE_REASONS.has(state.failure.reason);
  });
```

- [ ] **Step 4: Add `fallbackNoticeShown` to `ReaderViewComponent`.** Put it after `audioAttachment`:

```ts
  /** Summary plus player is the whole of an audio-first entry whose page holds no article (#1428). */
  protected readonly fallbackNoticeShown = computed(
    () =>
      this.source.failed() &&
      this.mode() === 'original' &&
      !(this.audioAttachment() && this.source.pageHoldsNoArticle()),
  );
```

In the template, change `@if (source.failed() && mode() === 'original') {` to `@if (fallbackNoticeShown()) {`.

- [ ] **Step 5: Run the spec and the gate.** Both `docker compose exec -T frontend npx jest src/app/reader/article/reader-view` and `docker compose exec -T frontend npm run check` must pass.

- [ ] **Step 6: Commit** with `feat(#1428): an audio-first entry whose page holds no article shows no fallback note`.
