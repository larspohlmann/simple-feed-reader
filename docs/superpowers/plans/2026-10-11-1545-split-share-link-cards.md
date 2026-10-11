# #1545 — Split ShareLinkCards into a queue runner and an entry card writer

**Goal:** `ShareLinkCards` keeps budget selection, claim/release/markDone and the orchestration; writing the cards
onto the entry moves to its own service, card numbering gets one home, the time check moves onto the budget, and the
page-card read sits behind an interface so queue tests need no fetch stack. No behaviour change.

## Rulings

- **Writer:** `Service/LinkCard/EntryCardWriter::write(EntryCardsModel)` appends the cards, drops the carded quote
  lines, re-sanitizes and re-snippets, and settles the media target. `EntryCardsModel` (entry, the cards it already
  holds, the page cards just read) keeps the writer and the private `store()` at few parameters. No interface: the
  queue tests run the real writer against the DB; only the reader is the expensive collaborator.
- **Numbering:** `LinkCardRepository::add(Entry, int $firstPosition, …)` becomes
  `append(Entry, list<LinkCardContent> $stored, list<LinkCardContent> $cards)`: the repository numbers after what the
  caller read, counted in memory (no `MAX(position)` query, so unflushed cards cannot be missed). `EntryEmbedWriter`
  passes `[]` — a freshly filled post holds no cards.
- **One query fewer:** the stored cards read before the budget selection are reused when storing; the second
  `contentsByEntryIds` goes. Only a holder that lost its lease could write between the two reads, and `stillHeld`
  already drops such rows.
- **Time math:** `LinkCardBudgetModel::fitsAfter(float $elapsedSeconds, int $fetches)` owns "can N more fetches still
  start after X seconds"; `ShareLinkCards` only measures the elapsed time.
- **Reader interface:** `PageCardReader` moves to `Service/LinkCard/PageCardReader/` beside
  `PageCardReaderInterface` (as `EntryEmbedWriter` does). Autowiring aliases the single implementation.
- **Tests:** `ShareLinkCardsTest` keeps the lease/budget/queue tests over a `StubPageCardReader` (no HTTP); quote-line,
  media-flip and position tests move to `EntryCardWriterTest`. `ShareLinkCardsWiring::build` stays for the
  integration tests that want the real fetch path (sweep, completion, Bluesky, bounds).
- `ReaderCacheService::VERSION` unchanged: pure refactor.

## Tasks

1. `LinkCardBudgetModel::fitsAfter` + test.
2. `LinkCardRepository::append` + repository test; `EntryEmbedWriter` switches.
3. `PageCardReaderInterface`, move the reader; `EntryCardsModel`, `EntryCardWriter`; slim `ShareLinkCards`.
4. `StubPageCardReader`, split `ShareLinkCardsTest` into queue tests and `EntryCardWriterTest`.
5. Gates: cs, stan, tramp, md, native suite, infection:diff, MySQL leg, PhpStorm inspections. Simplify pass.
