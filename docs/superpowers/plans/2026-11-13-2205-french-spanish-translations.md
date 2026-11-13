# French and Spanish Translations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship `fr` and `es` as full UI and email languages next to `en` and `de`.

**Architecture:** No new mechanism. The frontend's `LANGS` list and the backend's `SupportedLocale::ALL` each gain two entries; everything that reads those lists (Transloco config, the settings picker, `UpdateLocaleRequest`, `SignupUserFactory`, `translation.yaml`) follows. Two new dictionaries and two new email catalogues carry the text. Dates and relative times already come from `Intl` / ICU keyed by the language tag, so they need no code.

**Tech Stack:** Angular 20 + Transloco (JSON dictionaries in `frontend/public/i18n/`), Symfony 8.1 translator (YAML in `backend/translations/`), Jest, PHPUnit.

**Spec:** GitHub issue [#2205](https://github.com/larspohlmann/simple-feed-reader/issues/2205).

## Global Constraints

- Branch `feature/2205-french-spanish-translations`; commits `type(#2205): …`; no attribution lines.
- `fr.json` and `es.json` hold exactly the keys of `en.json`, in the same order, with every `{{placeholder}}` kept byte-for-byte. Translate from `en.json`; read `de.json` only to settle what an ambiguous English string means.
- Spanish is one neutral variant: no `vosotros`, no regionalisms (`dispositivo`, never `ordenador`/`computadora`; `Configuración`, never `Ajustes`).
- Frontend tests run in the container: `docker compose exec -T frontend npm test -- <path>`. One Jest run at a time.
- No comment added unless it clears the CLAUDE.md bar.

### Translation rules (the "fits this app" part)

Register — German already addresses the user as `du`:

| | Address | Why |
|---|---|---|
| French | **vous** | What French readers and Fediverse apps use (FreshRSS, Mastodon); `tu` reads as a children's or gaming app |
| Spanish | **tú** | The software norm in Spain and Latin America alike; `usted` reads as a bank |

Buttons and menu items are infinitives in both (`Enregistrer`, `Guardar`), sentence case, never title case. Labels stay as short as the English: many sit in segmented controls, pills and the narrow sidebar.

Glossary — one term per concept, everywhere:

| English | French | Spanish |
|---|---|---|
| feed | flux (invariable) | feed / feeds |
| entry | article | entrada |
| post (social) | publication | publicación |
| tag / untagged | étiquette / Sans étiquette | etiqueta / Sin etiqueta |
| subscribe / subscription | s'abonner / abonnement | suscribirse / suscripción |
| saved search | recherche enregistrée | búsqueda guardada |
| read / unread | lu / non lu | leído / no leído |
| mark all read | Tout marquer comme lu | Marcar todo como leído |
| favorite | favori | favorito |
| For you | Pour vous | Para ti |
| Trending | Tendances | Tendencias |
| magazine (layout) | magazine | revista |
| reader view | mode lecture | vista de lectura |
| digest (email) | récapitulatif | resumen |
| feed directory | annuaire de flux | directorio de feeds |
| recommendation run | cycle de recommandations | ejecución de recomendaciones |
| refresh | actualiser | actualizar |
| repost / quote / reply | republier / citer / répondre | republicar / citar / responder |
| transcript / chapter | transcription / chapitre | transcripción / capítulo |
| passkey | clé d'accès | llave de acceso |
| sign in / sign out / sign up | se connecter / se déconnecter / s'inscrire | iniciar sesión / cerrar sesión / registrarse |
| settings | paramètres | configuración |
| email | e-mail | correo electrónico (`correo` where space is tight) |
| Organise | Organiser | Organizar |

Left untranslated: product and network names (Simple Feed Reader, Bluesky, Mastodon, YouTube, OPML, RSS, Atom, AI provider and model names), and anything `en.json` and `de.json` both leave identical.

Typography: French puts U+202F before `; ! ?`, U+00A0 before `:` and inside `« »`; Spanish opens questions and exclamations with `¿ ¡` and quotes with `“ ”`. Both use `…` (one character) wherever English does.

Where "entry" and "article" appear in one English string with different meanings, French keeps `article` for the entry and uses `texte intégral` / `page` for the fetched article — judge by the string.

## Review Focus

1. **A label too long for its control** (segmented choices, sidebar rows, pills at 375 px) — it must not wrap or clip. Pinned by the live check in Task 7.
2. **A dropped or renamed `{{placeholder}}`** renders a hole — pinned by the placeholder test in Task 1.
3. **`fr-CA`, `es-MX`, `es-419`, `FR-be` browser tags** must boot into fr/es; `fy`, `eo`, `et` must not — Task 4.
4. **A stored `sfr.lang` of `fr` whose dictionary request fails** must land on bundled English, not a blank page — already covered by `boot-language.spec.ts`, which is language-agnostic; Task 4 re-runs it.
5. **An email placeholder (`%link%`) lost in translation** sends a mail without its link — pinned by the catalogue test in Task 5.

---

### Task 1: Dictionary guard covers every language

**Files:**
- Modify: `frontend/src/app/core/i18n/i18n-dictionaries.spec.ts`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json` (the `lang` block)

**Interfaces:**
- Produces: a `dictionaries: Record<string, unknown>` map at the top of the spec that Tasks 2 and 3 each add one import to.

- [ ] **Step 1:** Rewrite the spec around `const dictionaries = { en, de }`. Three tests, each iterating every non-English entry: same key set as `en`; no blank value; **new** — for every key, the set of `{{…}}` placeholders (whitespace inside the braces ignored) is a subset of English's for that key. Failures list `lang:key`.
- [ ] **Step 2:** Run `docker compose exec -T frontend npm test -- i18n-dictionaries`. If the placeholder test fails on `de`, the German string is wrong: fix the string, not the test.
- [ ] **Step 3:** Add to the `lang` block, after `de`: en.json `"fr": "French", "es": "Spanish"`; de.json `"fr": "Französisch", "es": "Spanisch"`. (The picker keeps today's convention: language names in the active language.)
- [ ] **Step 4:** Run the spec — PASS. Commit `test(#2205): dictionary guard checks every language and its placeholders`.

### Task 2: French dictionary

**Files:**
- Create: `frontend/public/i18n/fr.json`
- Modify: `frontend/src/app/core/i18n/i18n-dictionaries.spec.ts` (add `fr`)

- [ ] **Step 1:** Add `fr` to `dictionaries`; run the spec — FAIL (module not found).
- [ ] **Step 2:** Write `fr.json` under the translation rules above: all 1,516 values, key order of `en.json`, 2-space indent, trailing newline. `lang` block: `Langue`, `Anglais`, `Allemand`, `Français`, `Espagnol`.
- [ ] **Step 3:** Run the spec — PASS. Run `npx prettier --check public/i18n/fr.json` in the container.
- [ ] **Step 4:** Self-review pass over the whole file against the glossary: `grep -n` each English glossary term's rejected alternatives (`tag`, `feed`, `courriel`, `tu `, ` ton `, ` ta `, ` tes `) and fix every hit that is not a proper noun.
- [ ] **Step 5:** Commit `feat(#2205): French dictionary`.

### Task 3: Spanish dictionary

Same shape as Task 2 with `es.json`. `lang` block: `Idioma`, `Inglés`, `Alemán`, `Francés`, `Español`. Step 4 greps for `vosotros`, `vuestr`, `ordenador`, `Ajustes`, `usted`, `fuente` (as a feed), `etiquetar` misuse. Commit `feat(#2205): Spanish dictionary`.

Tasks 2 and 3 touch disjoint files apart from one line each in the spec and may run in parallel.

### Task 4: Frontend recognises fr and es

**Files:**
- Modify: `frontend/src/app/core/i18n/language.ts`, `language.spec.ts`
- Modify: `frontend/src/testing/transloco-testing.ts` (`availableLangs` from `LANGS`)
- Modify: `deploy/strato/.htaccess:62`
- Create: `frontend/src/app/core/i18n/dictionary-cache-rule.spec.ts`
- Modify: `frontend/src/app/reader/format.ts:53` (comment names `'en' | 'de'`)

**Interfaces:**
- Produces: `type Lang = 'en' | 'de' | 'fr' | 'es'`; `LANGS = ['en', 'de', 'fr', 'es']`; `detectLang(tag): Lang` unchanged in signature.

- [ ] **Step 1: Failing tests in `language.spec.ts`**

```ts
it.each([
  ['fr', 'fr'], ['fr-CA', 'fr'], ['FR-be', 'fr'],
  ['es', 'es'], ['es-MX', 'es'], ['es-419', 'es'],
  ['de-AT', 'de'], ['DE-ch', 'de'],
])('maps the browser tag %s to %s', (tag, lang) => {
  expect(detectLang(tag)).toBe(lang);
});

it('falls back to English for an untranslated or missing tag', () => {
  for (const tag of ['en', 'en-GB', 'nl-NL', 'fy', 'eo', 'et', 'des', '', null, undefined]) {
    expect(detectLang(tag)).toBe('en');
  }
});
```

`asLang`: `fr` and `es` accepted, `it` → null. Replace the two old `detectLang` tests.

- [ ] **Step 2:** Run — FAIL.
- [ ] **Step 3:** Extend `Lang` and `LANGS`. `detectLang` matches the tag's **primary subtag** (text before the first `-`, lower-cased) against `LANGS` through `asLang`, else `FALLBACK_LANG` — no per-language branch, so a fifth language needs no edit here. Update the docblock's "the two locales" wording.
- [ ] **Step 4: Failing test `dictionary-cache-rule.spec.ts`** — read `deploy/strato/.htaccess` with `node:fs` (path resolved from `process.cwd()`, the container's `/app`; check the mount reaches `../deploy` first — if it does not, place the test where the file is reachable, e.g. a `node --test` beside the deploy scripts, and say so in the commit). Assert the `FilesMatch` pattern for the dictionaries is exactly `^(${LANGS.join('|')})\.json$`.
- [ ] **Step 5:** Change the rule to `^(en|de|fr|es)\.json$` and "the two dictionary files" to "the dictionary files" in the comment above it. In `language.ts`, cut the docblock's "costs no test" sentence now that one exists; keep the pointer to `.htaccess`.
- [ ] **Step 6:** `transloco-testing.ts`: `availableLangs: [...LANGS]`. Fix the `format.ts` comment.
- [ ] **Step 7:** Run `docker compose exec -T frontend npm test -- core/i18n settings/preferences shared/segmented-choice` — PASS. Commit `feat(#2205): the UI offers and detects French and Spanish`.

### Task 5: Backend locales and account emails

**Files:**
- Modify: `backend/src/Enum/SupportedLocale.php`
- Create: `backend/translations/emails.fr.yaml`, `emails.es.yaml`
- Create: `backend/tests/Translation/EmailCataloguesTest.php`
- Modify: `backend/tests/Controller/Api/MeControllerTest.php`, `backend/tests/Service/Auth/Factory/SignupUserFactoryTest.php`, `backend/tests/Service/Mail/AccountMailer/AccountMailerTest.php`
- Modify: `backend/src/Entity/User.php:69` (comment names `'en' | 'de'`)

**Interfaces:**
- Produces: `SupportedLocale::FRENCH = 'fr'`, `SupportedLocale::SPANISH = 'es'`, `ALL = [ENGLISH, GERMAN, FRENCH, SPANISH]`.

- [ ] **Step 1: Failing test `EmailCataloguesTest`** — a data provider over `SupportedLocale::ALL` minus English; for each, parse `translations/emails.<locale>.yaml` and `emails.en.yaml` with `Yaml::parseFile`, flatten, and assert (a) identical key lists, (b) per key, the identical sorted set of `%name%` placeholders, (c) the `\n`-delimited line that holds `%link%` or `%review_url%` is that placeholder alone (the header comment in the YAML explains why: the link must sit on its own unindented line).
- [ ] **Step 2:** Add to `MeControllerTest` a data-provided case that `PATCH` of the locale accepts `fr` and `es` (mirror the existing `de` case) and one that `it` is rejected with `Response::HTTP_UNPROCESSABLE_ENTITY`; to `SignupUserFactoryTest` that `fr` and `es` are kept; to `AccountMailerTest` that a user with locale `fr` gets subject `Confirmez votre adresse e-mail` and one with `es` gets `Confirma tu dirección de correo electrónico`.
- [ ] **Step 3:** Run `php bin/phpunit tests/Translation tests/Controller/Api/MeControllerTest.php tests/Service/Auth tests/Service/Mail/AccountMailer` — FAIL.
- [ ] **Step 4:** Add the two constants. Write both catalogues from `emails.en.yaml` under the translation rules, same key order, same line-break structure as the English bodies, header comment in the catalogue's own language as the German one does. The two subjects in Step 2 are fixed copy.
- [ ] **Step 5:** `bin/console cache:clear && bin/console lint:yaml translations && bin/console debug:translation fr --domain=emails --only-missing` (and `es`) — nothing missing. Re-run Step 3 — PASS.
- [ ] **Step 6:** Fix the `User.php` comment to name `SupportedLocale` instead of listing codes. Commit `feat(#2205): account emails in French and Spanish`.

### Task 6: French and Spanish stand-alone date lines

The issue asks to check `DateLineRecognizer`. Finding: it recognises a publication date standing alone in an article body by letting ICU parse it in `de`, `en_US`, `en_GB`. `13 novembre 2026` and `13 de noviembre de 2026` parse in none of them, so French and Spanish articles keep a date line that German and English ones lose. This concerns article language, not UI language, but it is the same audience. Cost: six more formatters per process, six more parse attempts on a line that is under 48 characters and holds a digit.

**Files:**
- Modify: `backend/src/Service/Reader/DateLineRecognizer.php:15,31-35`
- Modify: `backend/tests/Service/Reader/DateLineRecognizerTest.php`

- [ ] **Step 1: Failing test**

```php
public function testDateLineMatchesFrenchAndSpanishForms(): void
{
    self::assertTrue($this->recognizer->isDateLine('13 novembre 2026'));
    self::assertTrue($this->recognizer->isDateLine('vendredi 13 novembre 2026'));
    self::assertTrue($this->recognizer->isDateLine('1 août 2026'));
    self::assertTrue($this->recognizer->isDateLine('13 de noviembre de 2026'));
    self::assertTrue($this->recognizer->isDateLine('viernes, 13 de noviembre de 2026'));
}

public function testDateLineRejectsFrenchAndSpanishSentences(): void
{
    self::assertFalse($this->recognizer->isDateLine('En 2026, tout a changé'));
    self::assertFalse($this->recognizer->isDateLine('13 novembre 2026 à Paris'));
    self::assertFalse($this->recognizer->isDateLine('noviembre'));
}
```

In `testBuildsOneFormatterPerLocaleAndStyleAndReusesItOnEveryLaterCall`, `9` becomes `15`.

- [ ] **Step 2:** Run `php bin/phpunit tests/Service/Reader/DateLineRecognizerTest.php` — FAIL.
- [ ] **Step 3:** `DATE_LINE_LOCALES = ['de', 'en_US', 'en_GB', 'fr', 'es']`. If a form in Step 1 that a French or Spanish site really prints still fails under ICU's strict parse, drop that assertion and note it in the commit rather than hand-listing patterns. Drop "German and English" from the `isDateLine` docblock's first sentence (it becomes "ICU supplies the forms, so none is hand-listed; …").
- [ ] **Step 4:** Run the test and `composer check && composer md` — PASS. Commit `feat(#2205): recognise French and Spanish date lines`.

### Task 7: Docs, live check, gates

**Files:**
- Modify: `README.md:82`, `docs/features.md:152` ("English, German, French and Spanish")

- [ ] **Step 1:** Update both lines.
- [ ] **Step 2: Live check on `http://localhost:4200`** (Docker stack up; `verify-containers-are-current` first). In French, then Spanish, at 1280 px and 375 px: settings → Preferences (the four-option language picker must fit its row), the reader list in magazine layout, the sidebar, an open article, the feed directory, Organise, login and register. Record every label that wraps, clips or overflows; shorten the translation, never the layout. Check relative times ("il y a 5 min", "hace 5 min") and a long date render localised. Reload with `sfr.lang` removed and the browser language set to `fr-CA` — boots French.
- [ ] **Step 3:** Request `i18n/fr.json` and `i18n/es.json` through the Docker stack — 200, JSON.
- [ ] **Step 4: Gates.** Frontend: `docker compose exec -T frontend npm run check`. Backend, in parallel: `composer check`, `composer md`, `php bin/phpunit`, `docker compose exec php composer test:parallel`, then `composer infection:diff`. Scan today's dev log.
- [ ] **Step 5:** `/simplify`, then commit `docs(#2205): the interface ships four languages` and open the PR into `develop` with `Closes #2205`.

## Not in this plan

- Feed directory content for France, Spain or Latin America (out of scope per the issue).
- Showing language names as endonyms (`Deutsch`, `Français`) in the picker regardless of UI language — arguably better with four languages, but it changes how English and German look today. Say so if wanted.
- French plural of zero (`0 flux` takes the singular in French). The app picks `…One` only for a count of exactly 1, and its counted nouns either are invariable or read acceptably; changing the plural selection is a separate change.
