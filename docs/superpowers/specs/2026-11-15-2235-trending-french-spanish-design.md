# Trending: French and Spanish after #2227 (#2235)

## Intent

#2227 gave every feed and social post a language and a Snowball stemmer in de/en/fr/es. Its measurement reported the
overall and the German numbers; French and Spanish were too small to show in the shared window. Its *Limitation*
recorded English Bluesky posts tagged `fr`, and #2229's Outcome recorded long English `politi.co` link cards guessed as
`fr` or `es`. Nobody had measured how often a French or Spanish tag is wrong, where the wrong tags come from, or what
they cost in grouped pairs.

This change measures French and Spanish on their own, checks the language tags of social posts, link cards and feeds
by eye, prices the wrong tags in grouped pairs, and changes the rule only if a change pays.

**Result: no rule change.** Short French and Spanish guesses are mostly wrong, but the posts they land on link to
nothing, so fixing them moves no pair; both candidate rules lose (−102 day) or tie (+1 day, −6 week). The only
deliverable is this record and the Outcome note in the #2227 spec.

## Goals

- French-only and Spanish-only windows, day and week, measured from the six-character cut (before #2227) to develop.
- An error rate per tag source (stated by the post, guessed from its text, the card's guess, a feed's language), counted
  by eye, both ways: English or German text tagged fr/es, and French or Spanish text tagged otherwise.
- The cost of the wrong tags in grouped pairs, per language pair, and the measured gain and loss of each candidate rule.
- A decision, with its numbers.

## Non-goals

- A different detector, a confidence model, or a stored `link_card.language`. Costed in *Approaches considered*.
- Rewriting the dev database's stored languages. Its social tags come from a fill script run before 49bf22822 (see M2);
  a measurement note, not a code defect, and the dev database is read-only for this work.
- French or Spanish compounds, elisions or seeds: #2230 and #2231 settled them.

## Anti-goals

- **Curation from the window.** No account, word or tuned value comes from the current news. The window measures a
  general rule; it never supplies one. A rule may reuse a value that already exists in the code, or data that does
  not depend on the news (the bundled Snowball stopword lists).
- **A rule that pays in French and Spanish by losing German or English pairs.** Every rule is measured on all four.

## Constraints

- Dev MySQL, read-only; "now" pinned to **2026-11-15 12:00:00 UTC**; the #2227 kit (`simp-explain-at.php`,
  `compare.py`); A and B back to back on the same entries.
- MIT-compatible data only.
- A rule change would touch `Service/Ingestion/Language` only, and keep a native iOS client viable (no new input).

## Measurements

Scripts and outputs: session scratchpad `2235/` (`run.sh`, `social-dump.php`, `cards.php`, `feeds.php`, `pairs.py`,
`runs/`). Each run's header showed the same entry count for A and B.

### M1. French-only and Spanish-only windows, A → B

A is develop before #2227 (a0f702721, the six-character cut for every entry), with its `Service/Curation/Trending`
tree, the three Trending repositories and `TrendingExplainCommand` swapped in by `git archive`. B is develop at
3cca6677f. Both restrict the window's query to `COALESCE(e.language, f.language)` = fr, then es, using the stored tags.

| Window | Entries | Groups A → B | Grouped pairs A → B | New / lost / **net** |
|---|---|---|---|---|
| French day | 472 | 54 → 55 | 181 → 175 | 13 / 19 / **−6** |
| French week | 591 | 68 → 68 | 209 → 212 | 22 / 19 / **+3** |
| Spanish day | 410 | 15 → 17 | 19 → 23 | 5 / 1 / **+4** |
| Spanish week | 887 | 39 → 39 | 62 → 62 | 8 / 8 / **0** |

Every changed group, by eye:

- **French, gained:** the *Bilibili* League of Legends final (2 → 3), the GTA 6 leave requests (2 → 3), the two Zidane
  interview groups (one 3-member group), the Ukraine concessions group (4 → 5), the *Eurovision Asia* pair, the
  Teddy Riner poll pair. All real stories.
- **French, lost or cut differently:** the Portugal housing protest loses its Le Monde piece (4 → 3); the Teddy Riner
  candidacy group is cut differently (6 → 5 + 3); the Hérault disappearance swaps the franceinfo *Candillargues* piece
  for a broader franceinfo flood piece; the US flight stop splits 4 → 3; the Donbass group splits 4 → 3. Reshuffles of
  real stories by the greedy grouper.
- **French, one wrong merge undone:** under A the Ardennes cellar death joined the La Courneuve garage death (two
  stories; B separates them). **One wrong merge kept:** the `atlasflux.saynete.com` template-title posts (2 + 2 → 4),
  already recorded in #2229's Outcome; never shown.
- **Spanish, gained:** the Canarias rescue record (2 → 3), the *Voyager 1* pair, the Spain–England match pair, the PP
  Gipuzkoa incident pair, Rosalía at the Latin Grammys (2 + 2 → 4), the PSOE complaint (2 → 3). All real.
- **Spanish, lost:** the *Feijóo … habrá* pair (*habrá* is a Snowball seed) and the US flight stop splitting 5 → 3 + 2
  (*estados* is a Snowball seed). Both are the limitations #2231 already records.

**French and Spanish do not lose on their own windows**: −6 / +3 French, +4 / 0 Spanish, all moves of real stories.

The shared window, same pin, A → B: day 1,836 → 2,144 pairs (**+308**: de–de +115, en–en +199, fr–fr +1, es–es +1,
mixed or none −8; 4,963 entries, 2.9 s → 5.4 s, peak RSS 85 → 97 MB); week 15,516 → 20,112 pairs (**+4,596**: de–de
+1,470, en–en +3,124, fr–fr +9, es–es +3, mixed or none −10; 29,661 entries, 62 s → 76 s, peak RSS 224 → 260 MB).
These include #2227, #2230, #2231 and #2229 together.

### M2. Where the stored social tags come from

The dev database's social posts mostly carry tags written by #2227's fill script, which ran with the detector loaded
with only the four languages; 49bf22822 (2026-11-15 00:29 UTC) loaded every profile, so text outside the four now
guesses no language. Evidence: of the 126 week posts whose stored fr/es tag differs from today's guess, 119 equal the
four-language detector's guess and were written before 49bf22822; the other 7 were written after it and carry the
post's stated language.

So **the stored fr/es tags measure the fill, not today's rule**. Of 105 stored-fr posts, 32 are French (30 %); of
117 stored-es posts, 28 are Spanish (24 %). Today's rule would tag most of the rest with no language. #2227's
*Limitation* (English Eurovision posts tagged fr) and the fr/es columns of #2229's card table were measured on these
tags. Strato had no fill: its social posts are tagged at ingest by today's rule.

### M3. Tag accuracy by source, counted by eye

Week window, 8,133 social posts (Bluesky, Mastodon, Lemmy), 3,201 link cards, 231 feeds.

**Guessed from the text** (today's rule when a post states no language, or its body is at least 150 characters): every
post the full detector names fr or es, simulated over all 8,133 posts.

| Guess | Body | Posts | Right | Wrong | Wrong ones |
|---|---|---|---|---|---|
| fr | < 150 | 28 | 8 | 20 | English headlines (*Watch: Inside Ukraine power plant…*, *New Medicaid work requirements delayed*), English one-liners, two German posts |
| fr | ≥ 150 | 24 | 23 | 1 | an English art caption with a French title in it |
| es | < 150 | 24 | 3 | 21 | English one-liners and hashtags (*#SilentSunday #Photography*), German posts, @-handles |
| es | ≥ 150 | 24 | 21 | 3 | a German heise teaser, an English `[News]` translation, a Galician post |
| de | < 150 | 733 | 40 of 40 sampled | 0 of 40 | – |
| en | < 150 | 2,116 | 40 of 40 sampled | 0 of 40 | – |

**Below 150 characters the detector is reliable for German and English and wrong for French (71 %) and Spanish (88 %);
at 150 and above it is right for both (96 % and 88 %).** The short fr/es misses are posts with almost no words; the
detector's scores for them sit within a few hundredths of each other (*fr 0.46, oc 0.45, ca 0.43, en 0.43*).

**Stated by the post** (only the 960 posts ingested after 49bf22822 carry today's rule). Tagged fr: 9, of which 4
French; tagged es: 7, of which 1 Spanish. The wrong stated tags: a Bluesky *dude* stated fr, two English one-liners
stated es, four English `[News]` translations on a Spanish Lemmy community stated es. Too few to price a rule on.

**The reverse** (French or Spanish text tagged otherwise): none tagged en or de. Six French or Spanish posts that state a
language keep it although the detector ranks Catalan or Galician first; without the stated tag they would get no
language. No French or Spanish text was found under an en or de tag.

**Link cards** (`CardLanguage`, the four-language detector for a card of 150 characters or more). Where the card's
language differs from its post's: fr → en 11, es → en 6, fr → de 3, es → de 1 are all right (they correct a wrong
post tag: BBC, Laravel News, heise cards under fill-tagged posts); en → es 4 are 3 Turkish or Indonesian cards forced
into one of the four (harmless: they link to nothing) and 1 right (infobae); en → fr 1 is wrong (a Washington
Examiner card). The #2229 `politi.co` misguesses do not recur in this window: those posts now carry fill tags fr/es,
and their cards' text reads as Italian or Esperanto to the full detector, so no detector fixes them.

**Feeds:** the 4 French and 4 Spanish feeds (Le Monde, franceinfo, Le Figaro, Le Parisien; El País, El Mundo,
elDiario.es, a Spanish tech feed) are right; 97–100 % of each one's week entries read as its language. No en or de
feed's entries read as fr or es. Feed-language detection needs nothing.

### M4. What the wrong tags cost, and the candidate rules

B's code, the shared window, the social posts' tags replaced in the repository's row mapping by one of:

- **S** — the stored tags (B as measured above);
- **G0** — today's rule with no stated language: the full detector's guess at every length;
- **G1** — no guess below 150 characters (the existing `LONG_TEXT_LENGTH`): a short post without a stated language gets
  no language and takes the six-character cut;
- **G2** — a short guess stands only when the text holds more of the guessed language's bundled stopwords than of any
  other of the four; otherwise no language.

Tags changed against G0: G1 nulls 2,116 en, 733 de, 28 fr and 24 es posts; G2 nulls 255 en, 68 de, 16 fr and 21 es.
By eye, G2 removes 16 of the 20 wrong short fr guesses and all 21 wrong short es guesses, and keeps all 8 right fr and 3
right es; the en and de posts it nulls are mostly right guesses on near-empty texts (*Herrlich!😂*, *#Verkehrswende*),
with a few English posts guessed de.

| Grouped pairs, new / lost / **net** | S → G0, day | S → G0, week | G0 → G1, day | G0 → G2, day | G0 → G2, week |
|---|---|---|---|---|---|
| de–de | 0 / 0 / **0** | 24 / 7 / **+17** | 0 / 0 / **0** | 0 / 0 / **0** | 4 / 23 / **−19** |
| en–en | 7 / 8 / **−1** | 71 / 319 / **−248** | 13 / 125 / **−112** | 11 / 11 / **0** | 64 / 78 / **−14** |
| fr–fr | 0 / 0 / **0** | 0 / 0 / **0** | 0 / 0 / **0** | 0 / 0 / **0** | 0 / 0 / **0** |
| es–es | 0 / 0 / **0** | 0 / 1 / **−1** | 0 / 0 / **0** | 0 / 0 / **0** | 0 / 0 / **0** |
| mixed or none | 0 / 0 / **0** | 4 / 27 / **−23** | 17 / 7 / **+10** | 1 / 0 / **+1** | 27 / 0 / **+27** |
| **all** | 7 / 8 / **−1** | 99 / 354 / **−255** | 30 / 132 / **−102** | 12 / 11 / **+1** | 95 / 101 / **−6** |

Day runs read 4,963 entries each; G0 and G2 week 29,649 each, S week 29,652 (the worker added 3 entries between the
runs). G1's week was not run: its day already loses 102 pairs.

A lost pair is classed by the old tags, a new one by the new tags.

French-only and Spanish-only windows over the entries that are fr (es) under G0 (each language's feed entries plus
the social posts G0 tags with it), G0 → G2: French day 174 → 174, week 209 → 209; Spanish day 26 → 26, week 61 → 61;
**0 new and 0 lost in all four**. The wrong short fr/es guesses link to nothing in their own language's window either.

**What S → G0 shows.** The fill's four-language tags beat today's guess by 255 week pairs, almost all en–en. The
cause is English, not French or Spanish: the full detector ranks Interlingua, Occitan or Latin first for 359 short
English posts that the four-language fill tagged en (*Palantir exec nominated to lead CISA*, *Time to catch Mr
Altman…*), so today's rule gives them no language when they state none. 81 of the 354 lost pairs have such a member;
the rest fall out of two large groups through transitivity (the Alaska count, Peltola's lead). The fr/es part of the
fill's wrong tags costs 1 pair. Recorded as a limitation, not built here (D6).

## Approaches considered

1. **No change** (chosen). The wrong fr/es tags sit on posts with almost no words; they group with nothing in any
   language, so correcting them moves no pair (M4: G0 → G2 0 on the French-only and Spanish-only windows; fr–fr and
   es–es 0 or −1 in every shared run).
2. **A minimum length below which no guess is made** (G1), reusing the existing 150. It removes every wrong short
   fr/es guess, but also 2,849 short en/de guesses (40 of 40 right in each sample), and the six-character cut loses their English pairs: −102 day.
   Rejected.
3. **A short guess confirmed by stopwords** (G2). The right rule for French and Spanish, by eye, and neutral in pairs
   (+1 day, −6 week, 0 on the French-only and Spanish-only windows).
   It would need the stopword lists in `Service/Ingestion/Language`, which today live in `Curation/Trending` (whose
   `CardLanguage` already depends on `Ingestion/Language`), so the interface and the data would move to a lower
   module: two production classes, a service move and their tests, for a gain the window cannot measure. Rejected as
   scope without a measured return; recorded so a later change that does pay can pick it up.
4. **A stated language the text contradicts.** The detector cannot contradict reliably where stated languages matter
   (below 150 characters, M3), and the wrong stated tags found are on texts too short for any test. Not built.
5. **The guesser's confidence** (a margin between the top two scores). Any margin would be a value fitted to this
   window; the anti-goal forbids it, and approach 3 reaches the same posts with news-independent data. Not built.
6. **The full detector for link cards.** It does not fix the `politi.co` cards (they read as Italian or Esperanto) and
   costs about 9 s on a week run (#2229 R2). Not built.

## Design

No code change. The #2227 spec's Outcome gets a short *French and Spanish (#2235)* note with M1–M4's headline numbers
and the fill finding (M2), so a later measurement on the dev database knows its social fr/es tags are the fill's.

## Decisions

Lars delegated every decision. Each is "what — why — cost if wrong".

- **D1. No language rule change** — neither candidate pays: G1 loses 102 day pairs (en–en), G2 ties (+1 day,
  −6 week), and the wrong tags themselves cost 1 day pair against today's rule. — If wrong: up to 20 short French
  and 21 short Spanish guesses a week stay wrong; on a French- or Spanish-reading instance they are near-empty posts in
  that language's window, which link to nothing (0 new, 0 lost on the French-only and Spanish-only windows, M4).
- **D2. The stopword-confirmed short guess (G2) is recorded, not built** — it is right by eye and neutral in pairs, and
  it needs a module move; "keep it small" weighs scope against a gain of zero. — If wrong: a later change that needs
  correct short-post languages (a per-language reader filter, a language badge) builds G2 then, from this record.
- **D3. The dev database's fill tags stay** — the database is read-only for this work, and the tags only affect dev
  measurements. — If wrong: a later dev measurement of fr/es reads fill tags; the #2227 Outcome note warns it.
- **D4. Feed-language detection stays as it is** — all 8 fr/es feeds are right and no en/de feed reads as fr/es. — If
  wrong: a mislabelled feed is fixed by hand in the edit dialog (#2227).
- **D5. `CardLanguage` stays on the four-language detector** — its fr/es verdicts are right except one, and the full
  detector does not fix the `politi.co` cards. — If wrong: one wrong English card a week stems in French.

- **D6. Short English posts that read as another language stay without one** — the S → G0 loss (−255 week, −1 day) is
  an English effect outside #2235's French and Spanish scope, concentrated in two large groups, and the obvious fix
  (fall back to the four-language detector) would bring back the wrong fr/es tags of M2 and the Italian-as-Spanish
  guesses 49bf22822 removed. — If wrong: up to about 250 week pairs of English stories stay unjoined until a follow-up
  (for example G2's stopword vote used to assign a language where the detector names none) is measured.

## Limitations

- Stated languages could be judged only on the 960 posts ingested after 49bf22822 (M3); the dev database keeps no
  stated language for older posts.
- The fill tags (M2) make every dev measurement of social fr/es before a refetch a measurement of the fill.
- Short English posts with no stated language often get no language (D6).

## Outcome

No code changed: the audit's result is D1 (no rule change), so the plan's confirming A/B run is A = B by construction and was not run (ruling in the ledger). The numbers above (M1–M4) are the outcome; the #2227 spec's Outcome carries a summary under *French and Spanish (#2235)*. The English finding D6 is followed up in #2243.
