# Trending: a short post the detector reads as no feed language gets the stopword vote (#2243) — design

## Intent

Since 49bf22822 the language guess loads every profile the detector ships, so text outside de/en/fr/es guesses no
language instead of being forced into one of the four (Italian no longer reads as Spanish, Dutch no longer as German).
The cost, found in #2235 (D6): on a short social post that states no language, the full detector often ranks a rare
language first (Interlingua, Frisian, Bislama, Afrikaans, Latin, Chamorro, Occitan) for plain English or German. The
post is stored with no language, Trending stems it with the six-character cut, and it stops joining its story.

This change gives such a post a language from news-independent evidence the codebase already bundles: the Snowball
and closed-class stopword lists (`backend/resources/stopwords/stopwords.php`). Below 150 characters, where the
detector is unreliable (#2235 M3), a post the detector reads as none of the four takes the language whose stopwords it
holds most, when that language wins outright. Nothing else changes: a stated language, a long text, and a short guess
the detector places in one of the four all stay as they are.

## Goals

- G1. A short social post (body under 150 characters) that states no language and that the full detector places
  outside the four gets the stopword vote's language, or none when the vote has no outright winner.
- G2. Recover the English and German pairs #2235 D6 priced, without new wrong French or Spanish tags that cost a pair,
  and without Italian- or Catalan-as-Spanish on any text the detector reads reliably (150 characters and more).
- G3. French and Spanish measured first-class: per language pair, and French-only and Spanish-only windows, day and
  week.

## Non-goals

- **Rejecting a short guess the vote contradicts** (#2235's G2). Measured again below; not built (D4).
- **A one-off fill of stored languages.** Languages are written at ingest only; the stored nulls age out of Trending's
  week window in seven days (D6).
- **A different detector, a confidence margin, or more stopword languages.** Costed under *Approaches considered*.
- **Feed languages and link-card languages.** `FeedLanguageResolver`, `CardLanguage` and `PreviewLanguage` keep their
  rules (D5).

## Anti-goals

- **Curation from the window.** No account, word, language list or tuned value comes from the current news. The rule
  reuses the existing 150-character limit and the bundled lists; the window only measures it.
- **A rule that pays in English or German by costing French or Spanish.** Every rule is measured on all four.

## Constraints

- Dev MySQL, read only; measured on the copy `feedreader_2243_copy` (dump of `feedreader`, 2026-11-15 23:38 CET);
  "now" pinned to **2026-11-15 12:00:00 UTC**; the #2227 kit (`simp-explain-at.php`) and #2235's `pairs.py`.
- The rule lives in `Service/Ingestion/Language`; `Service/Curation/Trending` already depends on that module, never the
  other way round (`ServiceModuleCycleRule`).
- Native iOS: no new input, no response change.

## Measurements

Scripts and outputs: session scratchpad `2243/` (`dump.php`, `census.py`, `rules.py`, `eye.py`, `proto-vote.php`,
`lang-of.php`, `run.sh`, `pairs.py`, `runs/`). The copy holds 125,133 entries; the pinned week has 7,412 social posts
(Bluesky, Mastodon, Lemmy), fewer than #2235's 8,133 because the social retention has since pruned 2026-11-06 to
2026-11-08. Every run's header shows the same entry count for the rules it compares (day 7,314, week 30,961).

The rules are simulated as #2235 M4 did: each social post's stored tag is replaced in the repository's row mapping by
the rule's language for that post *as if it stated none* (the dev database keeps no stated language). `G0` is today's
rule; `lang-of.php A`, which runs develop's own `SocialEntryLanguage::of()` on every post, gives exactly G0's tags
(0 of 7,412 differ).

### M1. The issue's numbers, reproduced

- **336** short posts (body under 150 characters) stored `en` by the four-language fill get no language under G0
  (the issue: about 359). Of the 793 short posts G0 gives no language, 292 hold no letter at all; the other 501 are
  where the full detector ranks a language outside the four first: Interlingua 44, Frisian 41, Bislama 34, Afrikaans
  34, Latin 31, Chamorro 29, Swedish 23, Occitan 23, Bokmål 22, Dutch 18, Catalan 14, …
- Stored tags (S) → G0: day **−10** (de–de −4, en–en −6), week **−154** (de–de −20, en–en −128, es–es −1, mixed −5).
  The issue's −255 week was measured before the retention pruned the window's first two days.
- Long posts (150 characters and more) G0 gives no language: 34 a week, mostly Galician and Portuguese read right.

Those 501 short posts by what the four-language detector says and what the vote (V2, below) says:

| Four-language guess | Posts | Vote en | Vote de | Vote fr | Vote es | No vote |
|---|---|---|---|---|---|---|
| en | 275 | 151 | 16 | 1 | 0 | 107 |
| de | 105 | 11 | 35 | 1 | 0 | 58 |
| fr | 58 | 13 | 0 | 3 | 0 | 41 |
| es | 64 | 19 | 3 | 0 | 3 | 39 |

The four-language detector's fr and es on these posts are almost all wrong (*#meme #humour* fr, *Amen.* es,
*playing pokopia* es, *Van Hollen rules out 2028 presidential run* fr); the vote moves 32 of them to en.

### M2. The candidate rules

Every rule keeps G0 where G0 names a language, and acts only on a short post G0 gives none, except V1G2.

- **F** — the four-language detector's guess (the fallback #2235 rejected).
- **FV** — the four-language guess when the stopword vote agrees with it.
- **V1** — the stopword vote over every word: the language whose bundled list holds the most of the text's word
  tokens, when it holds strictly more than each other; else none.
- **V2** — V1 over words of two letters or more.
- **V3** — V2 counting distinct words, with at least two.
- **V1G2** — V1, plus #2235's G2: a short G0 guess the vote does not confirm becomes none.

Tags each rule changes against G0 (all from no language):

| Rule | → en | → de | → fr | → es | Wrong fr / es, by eye |
|---|---|---|---|---|---|
| F | 275 | 105 | 57 | 64 | nearly all: near-empty posts and English headlines |
| FV | 148 | 35 | 4 | 3 | fr 3 of 4 (*Laravel AI SDK…* ×2, an English shout); es 3 of 3 (Catalan ×2, Turkish) |
| V1 | 187 | 54 | 13 | 3 | fr 12 of 13; es 3 of 3 |
| V2 | 194 | 54 | 5 | 3 | fr 4 of 5 (English *AI* read as French *ai*: *Laravel AI SDK…* ×2, *share AI generated memes*, *#ai*); es 3 of 3 |
| V3 | 122 | 37 | 1 | 2 | fr 0 of 1; es 2 of 2 (Catalan) |
| V1G2 | as V1, and 240 en, 59 de, 16 es, 15 fr become none | | | | |

V2 by eye: en 70 of 70 sampled right (*Palantir exec nominated…*-style headlines, one-liners); de 51 of 54 right (wrong:
*doodle cause im injured*, *Jensen Huang will return in Avengers*, *Funniest man alive*). V1's extra fr tags are
English contractions: the French list holds the single letters *s*, *t*, *d*, *l*, *m*, *n*, *c*, *j*, *y*, so
*Silicon Valley plots Jensen Huang's comeback* and *2028 Dems: Don't label me* voted French. Dropping one-letter words
removes 8 of the 12 wrong French tags and none of the right ones.

Grouped pairs against G0, new / lost / **net** (a lost pair is classed by G0's tags, a new one by the rule's):

| | V1 week | **V2 week** | V3 week | F week | FV week | V1G2 week |
|---|---|---|---|---|---|---|
| de–de | 25 / 9 / **+16** | 25 / 9 / **+16** | 25 / 9 / **+16** | 25 / 9 / **+16** | 25 / 9 / **+16** | 25 / 9 / **+16** |
| en–en | 145 / 22 / **+123** | 153 / 28 / **+125** | 137 / 22 / **+115** | 126 / 25 / **+101** | 110 / 16 / **+94** | 163 / 103 / **+60** |
| fr–fr | 0 / 0 / **0** | 0 / 0 / **0** | 0 / 0 / **0** | 0 / 0 / **0** | 0 / 0 / **0** | 0 / 0 / **0** |
| es–es | 0 / 0 / **0** | 0 / 0 / **0** | 0 / 0 / **0** | 0 / 0 / **0** | 0 / 0 / **0** | 0 / 0 / **0** |
| mixed or none | 6 / 1 / **+5** | 9 / 2 / **+7** | 4 / 1 / **+3** | 11 / 1 / **+10** | 4 / 1 / **+3** | 15 / 1 / **+14** |
| **all** | **+144** | **+148** | **+134** | **+127** | **+113** | **+90** |
| all, day | +7 | +7 | +6 | +10 (1 lost) | +7 | −9 |

V2 recovers 148 of the 154 week pairs S → G0 lost. The week build takes 81–89 s and peaks at 197 MB under every
rule (the rule changes no Trending code).

**French-only and Spanish-only windows**, G0 → V2 (feed entries of the language plus every social post any rule tags
with it): French day 283 → 283 pairs, week 326 → 326; Spanish day 54 → 54, week 96 → 96; **0 new and 0 lost in all
four**. The wrong fr/es tags V2 adds link to nothing in their language's window.

**Changed groups, V2 week, by eye** (20 new or changed groups): the Alaska Senate count (15 → 20), the Tim Cook as
Huang's successor group (4 + 5 → 9), the Boebert defeat (7 → 9), Aigner's nomination (12 → 13), the Hamas commander
(6 → 7), the Huang and Lutnick testimony (5 + 6 → 8), the Microsoft outage pair growing to 4. All real stories; the
39 lost pairs are the greedy grouper cutting those stories differently, none a wrong merge undone.

### M3. Languages outside the four

The vote cannot tell a language it has no list for. On the window, it tags 2 Catalan posts (*Nova entrada al blog…
Comença la COP 31*) and 1 Turkish post Spanish; no Italian or Portuguese short post voted. On standard sentences (not
from the window), 3 of 9 short Italian, Portuguese and Catalan sentences vote Spanish or French (*Ho visto un film con
mio fratello* es, *Eu gosto de café* fr, *Bom dia a todos* es); 1 of 3 Dutch and Danish votes English. At 150
characters and more nothing changes, so 49bf22822's fix (Italian no longer read as Spanish) stands where the detector
is reliable.

## Approaches considered

1. **The stopword vote on a short post the detector places outside the four (V2, chosen).** +148 week, +7 day, 0 on
   the French-only and Spanish-only windows; 5 wrong fr/es tags a week, which link to nothing.
2. **The four-language detector as fallback (F).** +127 week, but 57 fr and 64 es tags a week, nearly all wrong — the
   #2235 objection, measured. Rejected.
3. **The four-language guess confirmed by the vote (FV).** Fewer wrong tags than F, but it drops every post the
   four-language detector misreads that the vote reads right (32 English among them): +113. Rejected.
4. **A stricter vote (V3: two distinct stopwords).** 1 fr and 2 es tags a week fewer than V2, but it loses English
   headlines with one stopword (*Palantir exec nominated to lead CISA*, *Tim Kaine to chair Senate Budget Committee*):
   −14 pairs against V2. The "two" would also be a number chosen on this window. Rejected.
5. **The vote also rejecting an unconfirmed short guess (V1G2, #2235's G2).** It nulls 240 right en and 59 right de
   short guesses: +90, −9 day. Rejected again.
6. **The vote in Trending's caller**, over stored nulls at rebuild. A stored null is also a post that *stated* a
   language outside the four (an Italian post stating `it`); the caller cannot tell them apart and would tag stated
   Italian as Spanish. It would also leave `entry.language` wrong for every other reader of it. Rejected.
7. **More stopword lists (Italian, Portuguese, Catalan, Dutch) as competitors**, to stop M3. New bundled data, a
   generator change and a licence check for a cost of 3 posts a week. Not built; recorded for a later change.
8. **Excluding words written in capitals (*AI*) or hashtags.** Would remove 3 of V2's 4 wrong French tags; each is one
   more rule to explain for a cost of 0 pairs. Not built.

## Design

Ingestion owns the rule; the stopword data moves to the module that now reads it.

- **Move** `Service/Curation/Trending/StopWords/{StopWordsInterface,BundledStopWords}` to
  `Service/Ingestion/Language/StopWords/` (namespace `App\Service\Ingestion\Language\StopWords`), unchanged.
  `HeadlineFiller` imports them from there. `Curation/Trending → Ingestion/Language` already exists (`CardLanguage`),
  so no cycle. Their tests move to `tests/Service/Ingestion/Language/StopWords/`.
- **Move** `HeadlineStems::words()` to `App\Service\Foundation\Text\Support\CasefoldedWords::of(string $text):
  list<string>`, unchanged (casefold, NFKC, split on anything but `\p{L}\p{M}\p{N}`). The vote must tokenize exactly
  as the lists are normalized and as Trending's words are, so there is one definition. `HeadlineEquivalents` calls it;
  its tests move to `tests/Service/Foundation/Text/Support/CasefoldedWordsTest.php`.
- **New** `App\Service\Ingestion\Language\StopWordVote` (`final readonly`, module root):
  - constructor `(StopWordsInterface $stopWords)` builds, for each `FeedLanguage`, a set of its words;
  - `languageOf(string $text): ?FeedLanguage` — counts, per language, the word tokens of `CasefoldedWords::of($text)`
    of at least two characters (`mb_strlen`) that its set holds; returns the language whose count is greater than
    each other language's, or `null` when the highest count is 0 or shared.
- **`SocialEntryLanguage::guess()`**: the detector's `bestFor(text)`; when that is `null` and the body is short
  (`!LanguageGuess::overrulesAStatedLanguage($body)`), `StopWordVote::languageOf(text)`. `text` stays
  `headline . "\n" . body`. Its constructor gains `StopWordVote $stopWordVote` (autowired). Everything else in the
  class keeps its behaviour: a stated language in the four, a stated language outside the four (still none), the long
  text that overrules a stated one.
- Unchanged: `LanguageGuess`, `FeedLanguageResolver`, `CardLanguage`, `PreviewLanguage`, Trending, every response.

## Decisions

- **D1. The rule is V2: below 150 characters, a post with no stated language that the full detector places outside the
  four gets the language whose stopwords it holds most, outright, counting words of two letters or more.** — It
  recovers 148 of the 154 week pairs (+7 day), the most of any rule, with 0 change on the French-only and
  Spanish-only windows, from news-independent data and the existing 150. — If wrong: about 4 English posts a week with
  *AI* stem in French and about 3 Catalan or Turkish posts in Spanish; they link to nothing in this window (M2, M3).
- **D2. One-letter words do not vote.** — A single letter is an English contraction's tail or an initial, and the
  French list holds nine of them: V1 tagged 12 English posts French. A structural rule, not a fitted value. — If
  wrong: a short French post whose only stopwords are elided letters (*l'*, *d'*) gets no language, as today.
- **D3. The rule lives in `SocialEntryLanguage`, not in `LanguageGuess` or Trending.** — Only a social post has no
  other source for its language; feeds read 4,000 characters, and link cards use the four-language detector, which
  always names one of the four. In Trending's caller a stored null cannot be told from a stated non-four language
  (approach 6). — If wrong: a short feed whose entries read outside the four keeps no language (D4 of #2235 found
  none).
- **D4. A short guess the vote contradicts stands (no G2).** — Measured again (V1G2, on V1's vote): −58 week and −16
  day against V2, through 299 mostly right en/de guesses nulled. — If wrong: up to about 15 wrong short fr and 16 wrong short es guesses a week
  stay, linking to nothing (#2235 D1).
- **D5. Stopword data moves to `Ingestion/Language/StopWords`, and word splitting to `Foundation/Text/Support`.** — The
  vote needs both, and `Ingestion` may not depend on `Curation/Trending`; one tokenizer keeps the vote, the lists and
  Trending's stems in step. — If wrong: a later Trending-only list change touches the Ingestion module; a move back.
- **D6. No one-off fill.** — `entry.language` is written at ingest only, and Trending reads a week: the nulls on
  Strato and dev age out within seven days of the deploy. A fill would be a command, a test, two runs and a deletion
  commit for one week of pairs on a test server. — If wrong: about 150 week pairs stay unjoined for up to a week after
  the deploy.
- **D7. No more stopword lists and no capital or hashtag rule.** — 3 and 4 posts a week, 0 pairs (approaches 7, 8). —
  If wrong: an instance with many short Italian, Portuguese or Catalan posts sees them stemmed in Spanish or French; a
  later change adds competitor lists.
- **D8. A word written in capitals does not vote** (controller ruling, overrides D7's "no capital rule" for capitals
  only). — An acronym is no function word: English *AI* voted French *ai* in 4 of V2's 5 wrong French tags. A
  structural rule, no word list. — If wrong: a shouted short post (*AUS DEM ARCHIV*) loses its vote and keeps no
  language, as today.

## Measurement plan

The implementation plan's measurement task (A = develop 7eeb43652, B = HEAD): `lang-of.php A` and `lang-of.php B`
compute every week social post's language with each checkout's own `SocialEntryLanguage` (as if it stated none);
Trending runs on `feedreader_2243_copy` with each override, pinned to 2026-11-15 12:00 UTC, day and week, per language
pair, and French-only and Spanish-only. Expected: B's tags equal V2's (any difference is a finding), and the numbers
above. Plus the vote's cost per post at ingest.

## Limitations

- The dev database keeps no stated language; the rules were simulated as if every post stated none, like #2235 M4.
- The vote cannot recognise a language without a list (M3).
- Stored nulls stay until they age out (D6).

## Outcome

