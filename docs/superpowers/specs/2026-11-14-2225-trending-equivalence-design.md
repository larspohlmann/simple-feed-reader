# #2225 Trending: equivalence table and French/Spanish seed stems — design

## Intent

Trending groups articles into a story when their headline and lead stems overlap enough
(`HeadlineLinks`: shared weight ≥ 14, ≥ 40 % of the lighter entry, at least one shared headline stem).
The same thing spelled differently never overlaps: abbreviations (*U-Haft* / *Untersuchungshaft*, *EU* /
*Europäische Union*), names across the four app languages (*Deutschland* / *Germany* / *Allemagne* /
*Alemania*, *Selenskyj* / *Zelensky*), multi-word names (*United States* / *Vereinigte Staaten* /
*États-Unis* / *Estados Unidos*). Separately, French and Spanish function words are not seed stems, so on a
mixed instance where those feeds are a minority, *le, les, pour, el, los, para…* count as shared stems.

Goal: more correct links between same-story articles, across DE/EN/FR/ES, with no new wrong merges.

## Goals

- A curated equivalence table that maps spellings of one thing to one canonical stem, for abbreviations,
  proper names (countries, institutions, people in the news) and multi-word names.
- French and Spanish seed stems in `HeadlineFiller::SEED_STEMS`.
- A before/after `app:trending:explain` comparison on dev data for both windows.

## Non-goals

- No general synonym dictionary (common synonyms carry little rarity weight; each pair risks unrelated links).
- No automatic or fuzzy matching (edit distance, transliteration rules): every merge comes from a reviewed entry.
- No change to the six-character cut, the link thresholds, or the *Bundes…* compound folding.
- No language detection; feeds carry no language field and the table is language-agnostic.

## Anti-goals

- A wrong merge introduced by an entry (two unrelated stories joined) is a failure even if link counts rise.
- An abbreviation that is a common word once lowercased (*US* → *us*) must never fire on the common word.

## Constraints

- Lands before #2128 (stable story identity matches new groups to old by member overlap).
- Build time is not a constraint (Lars: "a slower build with a better quality is acceptable"); the work runs
  only in the worker rebuild (`TrendingSnapshotBuilder`), reader requests read stored stories.
- House rules: a hand-tuned table is an injected service, not a `Support/` static helper (architecture §10);
  `HeadlineStems` keeps the pure computation (fold, strip diacritics, split, length rule, cut).
- PHPMD-clean, PHPStan max, Infection on changed files.

## Approaches

1. **One table, two entry kinds** — case-sensitive exact abbreviations plus stem-level mappings; multi-word
   names joined only through one distinctive word. Cheapest; misses names whose words are each common
   (*United States*, *Nations unies*).
2. **Approach 1 plus multi-word phrases** — phrases matched on the folded word sequence before stemming.
   Catches country and institution names in all four languages. **Chosen by Lars.**
3. **Approach 2 plus fuzzy name matching** — merges rare stems one letter apart automatically. Rejected:
   merges nobody reviewed, invisible until a bad story shows up; works against the anti-goal.

## Design (decided)

A new injected service in the Trending module root, `HeadlineEquivalents`, holds three curated tables and
turns a text into its stems. `HeadlineWindow` asks it instead of calling `HeadlineStems::of` directly;
`TrendingGrouping` passes it into `new HeadlineWindow(...)` next to `HeadlineFiller`.

Per text (headline and body alike), in this order:

1. **Abbreviations** — exact, case-sensitive whole-word match on the raw text (word boundary = not a letter or
   digit, so *U-Haft* is matched as the hyphenated whole). Each match is replaced by its canonical stem and
   is exempt from the minimum word length. Examples: `US`, `USA` → `usa`; `EU` → `europa`; `UE` → `europa`;
   `WM` → `weltme`; `U-Haft` and `Untersuchungshaft` → `uhaft` (exact words, because the stem `unters` would also catch *unterstützen*). The same exact-word table holds long forms whose six-letter stem collides.
   *(Builder's call, from the builder check.)* All-caps text turns common words into exact matches
   (*LET US…*, *WHO IS…*). An abbreviation fires only when the text contains a lower-case letter and neither
   neighbouring word is itself all-caps (two or more letters); otherwise the word goes through the normal path.
2. **Fold and split** — unchanged `HeadlineStems` logic: casefold, strip diacritics, split on non-letters/digits.
3. **Phrases** — folded word sequences, matched longest first; a matched phrase *consumes* its words and
   yields one canonical stem, so *United States* counts once as `usa`, not also as `united` and `states`.
   Examples: `united states`, `vereinigte staaten`, `etats unis`, `estados unidos` → `usa`;
   `vereinte nationen`, `nations unies`, `naciones unidas`, `united nations` → `onu` (not `uno`, the Spanish word for "one").
4. **Length rule and cut** — unchanged, for words not consumed by steps 1 and 3.
5. **Stem mappings** — a cut stem is replaced by its canonical: `german`, `allema`, `aleman` → `deutsc`;
   `zelens` → `selens`; `europe` → `europa`.

Rules for the canonical stem:
- It is the stem the most common spelling already produces, so unlisted inflections of that spelling still
  match (`deutsc` covers *Deutschland, Deutschlands, deutsche*).
- It is never a seed stem; a unit test enforces this for every table value.
- Merged spellings share one stem id, so document frequency and rarity weighting stay correct with no
  change to `HeadlineWindow`'s weighting.

Stem mappings carry collision risk (every word sharing the six-letter prefix is merged). Each stem entry is
checked against the distinct words that produce that stem in the dev day and week windows before it goes in;
an entry whose stem also covers unrelated words is dropped or moved to a phrase or abbreviation entry.
*(Builder's call.)* The check runs as a throwaway script against the dev data, not committed; no new
command option.

*(Builder's call.)* Merging adds up document frequencies, so a canonical stem can cross the 3 % window-filler
share and stop linking. That is the existing rule working as designed (a stem that common carries no story
signal), so no exemption is added; but an entry whose canonical crosses the share on the dev windows is left
out, and **lost links** (pairs linked before, not after) are measured alongside new links.

**French and Spanish seed stems** are added to `SEED_STEMS` in the existing folded, cut form. A word that is
a content word in another of the four languages is left out (e.g. *son*, *die*-style clashes).

**Initial table content** comes from two sources: the dictionary-fixable near misses in the dev explain
output, and the recurring news names across the four languages (G7/EU countries, UN, NATO, EU institutions,
heads of government in the news). Kept small and reviewed; growth is a later edit, not a feature.

**Measurement** (success criteria):
- Reference (before the French/Spanish feeds): day = 4,398 entries, 641 groups, 3,127 near misses,
  2,454 ms, 40.7 MB.
- Lars approved French and Spanish feeds on dev; subscribed 2026-11-14 and fetched at once (Le Monde,
  franceinfo, Le Figaro, Le Parisien, El País, El Mundo, elDiario.es, La Vanguardia; 588 entries). The
  window filters on `effective_date`, so the first fetch fills it; no waiting.
- The dev stack keeps fetching, so before and after run **back to back** on the same data: *before* from a
  detached checkout of `develop`, *after* from this branch, both natively against the dev MySQL
  (`app:trending:explain` writes nothing), for `--window=day` and `--window=week`.
- Reported: near-miss count, new links, lost links, every new or grown group checked by eye for wrong
  merges, run time and peak memory.

## Left to the builder

- Exact class shape inside `HeadlineEquivalents` (constants vs. a static data file), and whether
  `HeadlineStems` exposes word-level pieces (fold/split, keep/cut) for it to reuse.
- Phrase matcher implementation (index by first word is enough at this table size).
- The exact FR/ES stop-word list and the table's entries, within the rules above.
- Test layout, following the existing `tests/Service/Curation/Trending` mirror.
