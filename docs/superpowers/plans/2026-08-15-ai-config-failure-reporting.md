# AI Configuration Failure Reporting Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make a rejected AI configuration report the server's own reason, in the card that caused it, without discarding the typed values.

**Architecture:** `AiSettingsService.failure` is today one global signal shared by ten writes with no record of which write failed. Every symptom in [#415](https://github.com/larspohlmann/simple-feed-reader/issues/415) follows from that. We give the failure a **scope** (`load`, `add`, or `row` + id), so each surface renders its own banner; we let `add()` report success so the component clears the draft only then; and we carry the server's text — including the per-field `errors` map — into the banner for the kinds whose next move really is "correct the form and retry".

**Tech Stack:** Angular 20 standalone components, signals, Transloco, Jest + jsdom, Playwright.

## Global Constraints

- **Frontend only.** No backend file changes. `ApiExceptionListener` withholds a production 500's `detail` on purpose (an exception message can hold connection strings, tokens or row data) — do not touch it.
- **Branch:** `fix/415-ai-config-failure-reporting`, off `develop`. The name embeds the issue number.
- **Shared checkout.** Another Claude session may be mid-edit. Check `git status` before any `checkout`, `reset` or `stash`. At the time of writing, `develop` is not checked out: the tree is on `feature/414-magazine-card-actions` with uncommitted work in `frontend/src/app/reader/magazine/entry-compact.component.scss` and an untracked `frontend/e2e/magazine-compact-actions-flush.spec.ts`. **Do not stash or discard those.**
- **Gate:** `npm run check` from `frontend/` (ESLint + Prettier + Stylelint + Jest) must pass before every commit.
- **Prettier is 100 columns.** Break long chains; write compliant the first time rather than running the formatter blind.
- **No hex colours and no raw `px`** in `.scss` outside `src/app/theme/`. This plan adds no styles, so nothing here should trip it.
- **Component styles live in a sibling `.scss`**, never inline in the `.ts`.
- **Both locales, always.** Every key added to `frontend/public/i18n/en.json` gets the same key in `frontend/public/i18n/de.json`. A missing German key renders the raw key path.
- **Commit after every task.** Conventional commits, scoped `(#415)` — matching the recent history, e.g. `fix(#414): flush the compact card's actions to the card edge`.

---

## File Structure

| File | Responsibility after this change |
|---|---|
| `frontend/src/app/settings/ai-failure.ts` | **Modify.** The failure vocabulary: what went wrong (`AiFailure`, unchanged job, plus a `validation` kind and per-field messages) and where it happened (`AiFailureScope`, `ScopedAiFailure`). Still a pure mapper — it never learns which call was made. |
| `frontend/src/app/settings/ai-failure.spec.ts` | **Modify.** Covers the new kind, the carried detail and the field messages. |
| `frontend/src/app/settings/ai-settings.service.ts` | **Modify.** Every write now names its own scope. `add()` takes an `AiDraft` and a success callback so the caller learns the add landed. |
| `frontend/src/app/settings/ai-settings.service.spec.ts` | **Modify.** Asserts the scope each write records and the success callback. |
| `frontend/src/app/settings/ai-section.component.ts` | **Modify.** Composes the banner sentence (it owns the translator), routes it to the right surface, and clears the draft from the success path only. |
| `frontend/src/app/settings/ai-section.component.html` | **Modify.** Three banner sites: the list card, each row, the add card. |
| `frontend/src/app/settings/ai-section.component.spec.ts` | **Modify.** Asserts placement, retention and message text. |
| `frontend/public/i18n/en.json`, `de.json` | **Modify.** A fallback for the new `validation` kind, and the field labels the banner names. |
| `frontend/e2e/ai-config-rejected.spec.ts` | **Create.** One Playwright smoke over a stubbed route: rejected add keeps the values and shows the server's reason under the form. |

**Why the draft stays in the component.** The obvious alternative — move `newName` / `newBaseUrl` / `newApiKey` into the service so `add()` can clear them itself — is rejected. `AiSettingsService`'s class comment records a deliberate decision: *"The typed key is a parameter and never a field: it goes into one request body and is gone."* A draft signal on the service would make the plaintext API key a service field. The success callback keeps that promise.

---

### Task 1: Carry the server's text and name the validation failure

`aiFailure()` gets a `validation` kind and stops discarding what the server sent. It stays pure: no Angular, no translator, no notion of which endpoint was called.

**Files:**
- Modify: `frontend/src/app/settings/ai-failure.ts`
- Test: `frontend/src/app/settings/ai-failure.spec.ts`

**Interfaces:**
- Consumes: `parseProblem` from `../core/problem` (already imported), which returns `{ type, title, status, detail?, errors? }`.
- Produces:
  - `type AiFailureKind = 'unreadableKey' | 'rateLimited' | 'notConfigured' | 'provider' | 'limit' | 'validation' | 'unknown'`
  - `interface AiFieldError { readonly field: string; readonly messages: readonly string[] }`
  - `interface AiFailure { readonly kind: AiFailureKind; readonly detail: string | null; readonly fieldErrors: readonly AiFieldError[] }`
  - `const SERVER_TEXT_KINDS: ReadonlySet<AiFailureKind>`
  - `function aiFailure(error: HttpErrorResponse): AiFailure`

- [ ] **Step 1: Write the failing tests**

Append to `frontend/src/app/settings/ai-failure.spec.ts`, inside the existing `describe('aiFailure', …)`:

```ts
  it('reads a rejected body as a validation failure and keeps every field message', () => {
    const failure = aiFailure(
      response(422, {
        type: 'validation_error',
        title: 'Validation failed',
        status: 422,
        detail: 'One or more fields are invalid.',
        errors: {
          apiKey: ['This value is too short. It should have 8 characters or more.'],
          baseUrl: ['This value should not be blank.'],
        },
      }),
    );

    expect(failure.kind).toBe('validation');
    expect(failure.detail).toBe('One or more fields are invalid.');
    expect(failure.fieldErrors).toEqual([
      { field: 'apiKey', messages: ['This value is too short. It should have 8 characters or more.'] },
      { field: 'baseUrl', messages: ['This value should not be blank.'] },
    ]);
  });

  it('keeps the server sentence on an unmapped problem type', () => {
    const failure = aiFailure(response(400, problem('request_error', 'The body is not valid JSON.', 400)));

    expect(failure.kind).toBe('unknown');
    expect(failure.detail).toBe('The body is not valid JSON.');
  });

  // A production 500 sends no detail on purpose, so there is nothing to show
  // and the translated fallback has to carry the banner.
  it('shows no server text when a 500 withholds its detail', () => {
    const failure = aiFailure(
      response(500, { type: 'internal_error', title: 'Internal server error', status: 500 }),
    );

    expect(failure.kind).toBe('unknown');
    expect(failure.detail).toBeNull();
  });

  it('reports no field errors for the kinds that have none', () => {
    expect(aiFailure(response(422, problem('ai_provider_rejected', 'Nope.'))).fieldErrors).toEqual([]);
  });

  it('names the kinds whose banner shows the server sentence', () => {
    expect([...SERVER_TEXT_KINDS].sort()).toEqual(['provider', 'unknown', 'validation']);
  });
```

Extend the import at the top of the file:

```ts
import { SERVER_TEXT_KINDS, aiFailure } from './ai-failure';
```

The existing test `'falls back to the unknown kind, and shows no server text, when the body is not a problem'` stays exactly as it is — `response(0, null)` still yields `detail: null`.

- [ ] **Step 2: Run the tests to verify they fail**

```bash
cd frontend && npx jest src/app/settings/ai-failure.spec.ts
```

Expected: FAIL. `SERVER_TEXT_KINDS` is not exported, and the validation case reports `kind: 'unknown'` with `detail: null`.

- [ ] **Step 3: Write the implementation**

Replace the body of `frontend/src/app/settings/ai-failure.ts` below the imports with:

```ts
/**
 * What went wrong, in the terms the section has to answer in.
 *
 * `provider` is the ordinary refusal — the endpoint did not answer, or it
 * rejected the key — and carries the server's own sentence, which already says
 * which of the two happened. `validation` is the same shape one layer earlier:
 * the body never reached the provider, and the server named the fields.
 *
 * The other kinds get a message of their own because the account's next move
 * differs: wait, configure first, enter the key again, or delete a
 * configuration to make room for a new one. The server's prose does not say
 * any of that, so showing it there would be a downgrade — and in German, a
 * downgrade into English.
 *
 * Every kind is decided by the problem type alone. The detail is prose the
 * backend is free to reword or a proxy to reflow, so classifying on it would
 * put one rule on both sides of the wire with no test able to see them drift.
 */
export type AiFailureKind =
  | 'unreadableKey'
  | 'rateLimited'
  | 'notConfigured'
  | 'provider'
  | 'limit'
  | 'validation'
  | 'unknown';

/** One rejected field, as `validation_error` reports it. */
export interface AiFieldError {
  readonly field: string;
  readonly messages: readonly string[];
}

export interface AiFailure {
  readonly kind: AiFailureKind;
  /** The server's own reason. Null only when the server sent none: a
   *  production 500, which withholds it deliberately, or no answer at all. */
  readonly detail: string | null;
  /** Empty for every kind but `validation`. */
  readonly fieldErrors: readonly AiFieldError[];
}

/**
 * The kinds whose banner shows the server's sentence instead of a translated
 * one. Kept as data rather than a chain of `if`s in the component, so the
 * choice is one list a test can read back.
 */
export const SERVER_TEXT_KINDS: ReadonlySet<AiFailureKind> = new Set<AiFailureKind>([
  'provider',
  'validation',
  'unknown',
]);

/** Which surface a failure belongs to, so each renders its own banner
 *  instead of one shared line above the wrong card. Assigned by the service,
 *  which knows the call; never by the mapper below, which sees only the
 *  response. */
export type AiFailureScope =
  | { readonly action: 'load' }
  | { readonly action: 'add' }
  | { readonly action: 'row'; readonly configId: number };

export interface ScopedAiFailure {
  readonly failure: AiFailure;
  readonly scope: AiFailureScope;
}

export function aiFailure(error: HttpErrorResponse): AiFailure {
  const problem = parseProblem(error);
  const detail = problem.detail ?? null;
  const kind = kindOf(problem.type, problem.status);

  return {
    kind,
    detail,
    fieldErrors: kind === 'validation' ? fieldErrors(problem.errors) : [],
  };
}

function kindOf(type: string, status: number): AiFailureKind {
  if (status === 429) return 'rateLimited';
  if (type === 'ai_not_configured') return 'notConfigured';
  if (type === 'ai_key_unreadable') return 'unreadableKey';
  if (type === 'ai_provider_rejected') return 'provider';
  if (type === 'ai_configuration_limit') return 'limit';
  if (type === 'validation_error') return 'validation';

  return 'unknown';
}

function fieldErrors(errors: Record<string, string[]> | undefined): readonly AiFieldError[] {
  if (!errors) return [];

  return Object.entries(errors)
    .filter(([, messages]) => Array.isArray(messages) && messages.length > 0)
    .map(([field, messages]) => ({ field, messages }));
}
```

Keep the two existing import lines untouched at the top of the file.

- [ ] **Step 4: Run the tests to verify they pass**

```bash
cd frontend && npx jest src/app/settings/ai-failure.spec.ts
```

Expected: PASS, all cases including the seven pre-existing ones.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/settings/ai-failure.ts frontend/src/app/settings/ai-failure.spec.ts
git commit -m "fix(#415): keep the server's reason and name the validation failure"
```

---

### Task 2: Give every write a scope, and let `add` report success

**Files:**
- Modify: `frontend/src/app/settings/ai-settings.service.ts`
- Test: `frontend/src/app/settings/ai-settings.service.spec.ts`

**Interfaces:**
- Consumes: `AiFailure`, `AiFailureScope`, `ScopedAiFailure`, `aiFailure` from `./ai-failure` (Task 1).
- Produces:
  - `interface AiDraft { readonly name: string | null; readonly baseUrl: string; readonly apiKey: string }`
  - `readonly failure: WritableSignal<ScopedAiFailure | null>` — replaces the old `WritableSignal<AiFailure | null>`
  - `add(draft: AiDraft, onAdded: () => void): void` — replaces `add(name, baseUrl, apiKey)`
  - Every other public write keeps its signature.

- [ ] **Step 1: Write the failing tests**

Append inside `describe('AiSettingsService', …)` in `frontend/src/app/settings/ai-settings.service.spec.ts`:

```ts
  const DRAFT = {
    name: 'My provider',
    baseUrl: 'https://api.example.test/v1',
    apiKey: 'sk-secret',
  };

  const reject = (request: TestRequest, body: unknown, status = 422): void =>
    request.flush(body, { status, statusText: 'Unprocessable Content' });

  it('scopes a failed add to the add form', () => {
    const onAdded = jest.fn();
    svc.add(DRAFT, onAdded);

    reject(ctrl.expectOne(`${base}/api/me/ai/configs`), {
      type: 'validation_error',
      title: 'Validation failed',
      status: 422,
      detail: 'One or more fields are invalid.',
      errors: { apiKey: ['This value is too short.'] },
    });

    expect(svc.failure()?.scope).toEqual({ action: 'add' });
    expect(svc.failure()?.failure.kind).toBe('validation');
    expect(onAdded).not.toHaveBeenCalled();
  });

  it('tells the caller the add landed, so the draft is cleared only then', () => {
    const onAdded = jest.fn();
    svc.add(DRAFT, onAdded);

    ctrl.expectOne(`${base}/api/me/ai/configs`).flush({ ...config({ id: 4 }), models: ['gpt-4o'] });

    expect(onAdded).toHaveBeenCalledTimes(1);
    expect(svc.failure()).toBeNull();
  });

  it('scopes a failed row write to that row', () => {
    svc.load();
    ctrl.expectOne(`${base}/api/me/ai`).flush({ configs: [config({ id: 9 })], activeId: null });

    svc.loadModels(9);
    reject(ctrl.expectOne(`${base}/api/me/ai/configs/9/models`), {
      type: 'ai_provider_rejected',
      title: 'The AI provider could not be used',
      status: 422,
      detail: 'That address did not answer.',
    });

    expect(svc.failure()?.scope).toEqual({ action: 'row', configId: 9 });
    expect(svc.failure()?.failure.detail).toBe('That address did not answer.');
  });

  it('scopes a failed list load to the list', () => {
    svc.load();
    reject(ctrl.expectOne(`${base}/api/me/ai`), null, 500);

    expect(svc.failure()?.scope).toEqual({ action: 'load' });
  });

  it('clears the previous failure when the next write starts', () => {
    svc.load();
    reject(ctrl.expectOne(`${base}/api/me/ai`), null, 500);
    expect(svc.failure()).not.toBeNull();

    svc.activate(3);
    expect(svc.failure()).toBeNull();
    ctrl.expectOne(`${base}/api/me/ai/configs/3/active`).flush(config({ id: 3, active: true }));
  });
```

Add `TestRequest` to the existing testing import at the top of the file:

```ts
import { HttpTestingController, TestRequest, provideHttpClientTesting } from '@angular/common/http/testing';
```

Then update the one pre-existing `add` test (currently at line 73, `'adds a configuration, stores its models and opens the model picker for it'`) to the new signature. Its first line becomes:

```ts
    svc.add(DRAFT, jest.fn());
```

Its `expect(request.request.body).toEqual({ … })` assertion is unchanged — the wire body still carries `name`, `baseUrl` and `apiKey`.

- [ ] **Step 2: Run the tests to verify they fail**

```bash
cd frontend && npx jest src/app/settings/ai-settings.service.spec.ts
```

Expected: FAIL. `add` takes three arguments, and `failure()` has no `scope`.

- [ ] **Step 3: Write the implementation**

In `frontend/src/app/settings/ai-settings.service.ts`:

**3a.** Replace the `AiFailure` import:

```ts
import { AiFailureScope, ScopedAiFailure, aiFailure } from './ai-failure';
```

**3b.** Add the draft type below the `AiConfigList` interface:

```ts
/**
 * What the add form holds. A parameter object rather than three arguments,
 * and deliberately not a field on this service: the plaintext key goes into
 * one request body and is gone.
 */
export interface AiDraft {
  readonly name: string | null;
  readonly baseUrl: string;
  readonly apiKey: string;
}
```

**3c.** Change the failure signal:

```ts
  readonly failure = signal<ScopedAiFailure | null>(null);
```

**3d.** Give every call its scope, and let `add` report success. Replace each method below; the rest of the class is untouched.

```ts
  load(): void {
    this.run({ action: 'load' }, this.http.get<AiConfigList>(`${this.base}/api/me/ai`), (list) => {
      this.configs.set(list.configs);
      this.applyAvailability();
    });
  }

  /**
   * `onAdded` runs on success only. The caller owns the draft — see AiDraft —
   * so this is how it learns the values are safe to clear. A rejected add
   * leaves the form exactly as the account typed it.
   */
  add(draft: AiDraft, onAdded: () => void): void {
    this.run(
      { action: 'add' },
      this.http.post<AiConfig & { models: string[] }>(`${this.base}/api/me/ai/configs`, {
        name: draft.name,
        baseUrl: draft.baseUrl,
        apiKey: draft.apiKey,
      }),
      (added) => {
        const { models, ...configuration } = added;
        this.upsert(configuration);
        this.models.set(models);
        this.choosingModelFor.set(configuration.id);
        onAdded();
      },
    );
  }

  loadModels(id: number): void {
    this.run(
      { action: 'row', configId: id },
      this.http.get<{ models: string[] }>(`${this.base}/api/me/ai/configs/${id}/models`),
      (answer) => {
        this.models.set(answer.models);
        this.choosingModelFor.set(id);
      },
    );
  }

  chooseModel(id: number, model: string): void {
    this.run(
      { action: 'row', configId: id },
      this.http.put<AiConfig>(`${this.base}/api/me/ai/configs/${id}/model`, { model }),
      (config) => this.upsert(config),
    );
  }

  rename(id: number, name: string | null): void {
    this.run(
      { action: 'row', configId: id },
      this.http.put<AiConfig>(`${this.base}/api/me/ai/configs/${id}/name`, { name }),
      (config) => this.upsert(config),
    );
  }

  setReasoning(id: number, suppressReasoning: boolean): void {
    this.run(
      { action: 'row', configId: id },
      this.http.put<AiConfig>(`${this.base}/api/me/ai/configs/${id}/reasoning`, {
        suppressReasoning,
      }),
      (config) => this.upsert(config),
    );
  }

  setBatchConcurrency(id: number, batchConcurrency: number): void {
    if (this.savedConcurrencyTimer !== null) clearTimeout(this.savedConcurrencyTimer);
    this.savedConcurrencyId.set(null);

    this.run(
      { action: 'row', configId: id },
      this.http.put<AiConfig>(`${this.base}/api/me/ai/configs/${id}/batch-concurrency`, {
        batchConcurrency,
      }),
      (config) => {
        this.upsert(config);
        this.savedConcurrencyId.set(config.id);
        this.savedConcurrencyTimer = setTimeout(() => this.savedConcurrencyId.set(null), 2500);
      },
    );
  }

  activate(id: number): void {
    this.run(
      { action: 'row', configId: id },
      this.http.put<AiConfig>(`${this.base}/api/me/ai/configs/${id}/active`, {}),
      (config) => this.upsert(config),
    );
  }

  duplicate(id: number): void {
    this.run(
      { action: 'row', configId: id },
      this.http.post<AiConfig>(`${this.base}/api/me/ai/configs/${id}/duplicate`, {}),
      (config) => this.upsert(config),
    );
  }

  remove(id: number): void {
    this.run(
      { action: 'row', configId: id },
      this.http.delete<void>(`${this.base}/api/me/ai/configs/${id}`),
      () => this.drop(id),
    );
  }
```

**3e.** Replace `run()` at the bottom of the class. The scope comes first, so every call site reads as "this write, over this request":

```ts
  private run<T>(
    scope: AiFailureScope,
    request: Observable<T>,
    onSuccess: (value: T) => void,
  ): void {
    this.busy.set(true);
    this.failure.set(null);

    request.subscribe({
      next: (value) => {
        this.busy.set(false);
        onSuccess(value);
      },
      error: (error: HttpErrorResponse) => {
        this.busy.set(false);
        this.failure.set({ failure: aiFailure(error), scope });
      },
    });
  }
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
cd frontend && npx jest src/app/settings/ai-settings.service.spec.ts
```

Expected: PASS. `AiSectionComponent` does not compile yet; that is Task 3.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/settings/ai-settings.service.ts frontend/src/app/settings/ai-settings.service.spec.ts
git commit -m "fix(#415): record which write a failure came from"
```

---

### Task 3: Add the translations the banner needs

Two keys per locale: the fallback for the new kind, and the field labels the banner names when it lists field messages. Done before the component so Task 4's tests have real strings to assert against.

**Files:**
- Modify: `frontend/public/i18n/en.json`
- Modify: `frontend/public/i18n/de.json`

**Interfaces:**
- Produces: `settings.ai.errors.validation`, and `settings.ai.fields.{name,baseUrl,apiKey}`.

- [ ] **Step 1: Add the English keys**

In `frontend/public/i18n/en.json`, inside `settings.ai.errors`, after `"limit"`:

```json
        "validation": "Check the values you entered.",
```

And add a new `fields` object as a sibling of `errors` inside `settings.ai`:

```json
      "fields": {
        "name": "Optional name",
        "baseUrl": "Endpoint",
        "apiKey": "API key"
      },
```

These three deliberately repeat the wording of `settings.ai.configs.namePlaceholder`, `settings.ai.baseUrl` and `settings.ai.apiKey`. They are a separate map because they are keyed by the server's property path, not by the form's layout: reusing the form keys would silently break the banner the day a label is reworded for the form alone.

- [ ] **Step 2: Add the German keys**

In `frontend/public/i18n/de.json`, inside `settings.ai.errors`, after `"limit"`:

```json
        "validation": "Prüfe die eingegebenen Werte.",
```

And as a sibling of `errors` inside `settings.ai`:

```json
      "fields": {
        "name": "Optionaler Name",
        "baseUrl": "Endpunkt",
        "apiKey": "API-Schlüssel"
      },
```

- [ ] **Step 3: Verify both files still parse and hold the same keys**

```bash
cd frontend && python3 -c "
import json
en=json.load(open('public/i18n/en.json'))['settings']['ai']
de=json.load(open('public/i18n/de.json'))['settings']['ai']
assert sorted(en['errors'])==sorted(de['errors']), (sorted(en['errors']), sorted(de['errors']))
assert sorted(en['fields'])==sorted(de['fields']), (sorted(en['fields']), sorted(de['fields']))
assert 'validation' in en['errors']
print('ok', sorted(en['errors']), sorted(en['fields']))
"
```

Expected: `ok ['limit', 'notConfigured', 'provider', 'rateLimited', 'unknown', 'unreadableKey', 'validation'] ['apiKey', 'baseUrl', 'name']`

- [ ] **Step 4: Commit**

```bash
git add frontend/public/i18n/en.json frontend/public/i18n/de.json
git commit -m "fix(#415): add the validation fallback and the field labels"
```

---

### Task 4: Render each failure where it happened, and keep the draft

The component owns the translator, so it composes the sentence. Three surfaces get a banner: the list card, each row, and the add card.

**Files:**
- Modify: `frontend/src/app/settings/ai-section.component.ts`
- Modify: `frontend/src/app/settings/ai-section.component.html`
- Test: `frontend/src/app/settings/ai-section.component.spec.ts`

**Interfaces:**
- Consumes: `ScopedAiFailure`, `AiFailure`, `SERVER_TEXT_KINDS` from `./ai-failure` (Task 1); `AiDraft`, the scoped `failure` signal and `add(draft, onAdded)` from `./ai-settings.service` (Task 2); the keys from Task 3.
- Produces (all read from the template):
  - `readonly listFailure: Signal<string | null>`
  - `readonly addFailure: Signal<string | null>`
  - `rowFailure(configId: number): string | null`

- [ ] **Step 1: Write the failing tests**

In `frontend/src/app/settings/ai-section.component.spec.ts`, first update the stub so it compiles against Task 2. Change the `AiSettingsStub` interface member:

```ts
  failure: WritableSignal<ScopedAiFailure | null>;
```

and its initialiser in `createStub()`:

```ts
    failure: signal<ScopedAiFailure | null>(null),
```

and the import on line 11:

```ts
import { AiFailure, ScopedAiFailure } from './ai-failure';
```

Add a helper next to the existing `row` / `addDetails` helpers:

```ts
  const banners = (host: HTMLElement): string[] =>
    Array.from(host.querySelectorAll('app-error-banner')).map((banner) =>
      (banner.textContent ?? '').trim(),
    );

  // Same idiom as the pre-existing card-layout test at line 519: find the card
  // by the thing only that card contains.
  const card = (fixture: ComponentFixture<AiSectionComponent>, marker: string): HTMLElement =>
    (
      Array.from(
        (fixture.nativeElement as HTMLElement).querySelectorAll('app-settings-card'),
      ) as HTMLElement[]
    ).find((each) => each.querySelector(marker)) as HTMLElement;

  const addCard = (fixture: ComponentFixture<AiSectionComponent>): HTMLElement =>
    card(fixture, '.add-config');

  const listCard = (fixture: ComponentFixture<AiSectionComponent>): HTMLElement =>
    card(fixture, '.configs');

  const scoped = (failure: AiFailure, scope: ScopedAiFailure['scope']): ScopedAiFailure => ({
    failure,
    scope,
  });
```

Then replace the two pre-existing banner tests (at lines 457 and 464, which set `ai.failure.set({ kind: …, detail: … })` directly) with the scoped form, and add the new cases:

```ts
  it('shows a failed add under the add form, not above the configs list', () => {
    const fixture = mountWithConfigs([CONFIG]);

    ai.failure.set(
      scoped(
        { kind: 'validation', detail: 'One or more fields are invalid.', fieldErrors: [
          { field: 'apiKey', messages: ['This value is too short.'] },
        ] },
        { action: 'add' },
      ),
    );
    fixture.detectChanges();

    expect(banners(addCard(fixture))).toEqual(['API key: This value is too short.']);
    expect(banners(listCard(fixture))).toEqual([]);
  });

  it('shows a failed row write inside that row', () => {
    const fixture = mountWithConfigs([config({ id: 7 }), config({ id: 8 })]);
    expandRow(fixture, 1);

    ai.failure.set(
      scoped(
        { kind: 'provider', detail: 'That address did not answer.', fieldErrors: [] },
        { action: 'row', configId: 8 },
      ),
    );
    fixture.detectChanges();

    expect(banners(row(fixture, 1))).toEqual(['That address did not answer.']);
    expect(banners(row(fixture, 0))).toEqual([]);
    expect(banners(addCard(fixture))).toEqual([]);
  });

  it('shows a failed list load on the list card', () => {
    const fixture = mountWithConfigs([CONFIG]);

    ai.failure.set(
      scoped({ kind: 'unknown', detail: null, fieldErrors: [] }, { action: 'load' }),
    );
    fixture.detectChanges();

    expect(banners(listCard(fixture))).toEqual(['Something went wrong. Try again.']);
  });

  it('keeps the translated message for the kinds whose next move is not "retry"', () => {
    const fixture = mountWithConfigs([CONFIG]);

    ai.failure.set(
      scoped(
        { kind: 'limit', detail: 'This account already holds the maximum.', fieldErrors: [] },
        { action: 'add' },
      ),
    );
    fixture.detectChanges();

    expect(banners(addCard(fixture))).toEqual([
      'You have reached the maximum number of saved configurations.',
    ]);
  });

  it('names an unrecognised field by its raw path rather than dropping it', () => {
    const fixture = mountWithConfigs([CONFIG]);

    ai.failure.set(
      scoped(
        {
          kind: 'validation',
          detail: null,
          fieldErrors: [{ field: 'somethingNew', messages: ['Nope.'] }],
        },
        { action: 'add' },
      ),
    );
    fixture.detectChanges();

    expect(banners(addCard(fixture))).toEqual(['somethingNew: Nope.']);
  });
```

Now replace the pre-existing add test at line 187 (`'adds a configuration and clears the typed key'`) with the pair below, and update the one at line 202 (`'sends no name when the optional field is left blank'`) to the new call shape:

```ts
  it('adds a configuration and clears the draft once the add lands', () => {
    const fixture = mount();
    (addDetails(fixture).querySelector('summary') as HTMLElement).click();
    fixture.detectChanges();

    fixture.componentInstance.newName.set('My provider');
    fixture.componentInstance.newBaseUrl.set('https://api.example.test/v1');
    fixture.componentInstance.newApiKey.set('sk-secret');

    fixture.componentInstance.add();

    expect(ai.add).toHaveBeenCalledWith(
      { name: 'My provider', baseUrl: 'https://api.example.test/v1', apiKey: 'sk-secret' },
      expect.any(Function),
    );
    expect(fixture.componentInstance.newApiKey()).toBe('sk-secret');

    (ai.add.mock.calls[0][1] as () => void)();

    expect(fixture.componentInstance.newName()).toBe('');
    expect(fixture.componentInstance.newBaseUrl()).toBe('');
    expect(fixture.componentInstance.newApiKey()).toBe('');
  });

  it('keeps every typed value when the add is rejected', () => {
    const fixture = mount();
    (addDetails(fixture).querySelector('summary') as HTMLElement).click();
    fixture.detectChanges();

    fixture.componentInstance.newName.set('My provider');
    fixture.componentInstance.newBaseUrl.set('https://api.example.test/v1');
    fixture.componentInstance.newApiKey.set('sk-short');

    fixture.componentInstance.add();
    ai.failure.set(
      scoped({ kind: 'validation', detail: 'Invalid.', fieldErrors: [] }, { action: 'add' }),
    );
    fixture.detectChanges();

    expect(fixture.componentInstance.newName()).toBe('My provider');
    expect(fixture.componentInstance.newBaseUrl()).toBe('https://api.example.test/v1');
    expect(fixture.componentInstance.newApiKey()).toBe('sk-short');
  });

  it('sends no name when the optional field is left blank', () => {
    const fixture = mount();
    (addDetails(fixture).querySelector('summary') as HTMLElement).click();
    fixture.detectChanges();

    fixture.componentInstance.newBaseUrl.set('https://api.example.test/v1');
    fixture.componentInstance.newApiKey.set('sk-secret');

    fixture.componentInstance.add();

    expect(ai.add).toHaveBeenCalledWith(
      { name: null, baseUrl: 'https://api.example.test/v1', apiKey: 'sk-secret' },
      expect.any(Function),
    );
  });
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
cd frontend && npx jest src/app/settings/ai-section.component.spec.ts
```

Expected: FAIL. `listFailure` / `addFailure` / `rowFailure` do not exist, and `add()` still calls the old three-argument signature.

- [ ] **Step 3: Write the component**

In `frontend/src/app/settings/ai-section.component.ts`:

**3a.** Replace the `Signal` imports and the failure computeds. Change the `@angular/core` import to include `Signal`:

```ts
import {
  ChangeDetectionStrategy,
  Component,
  Signal,
  computed,
  inject,
  linkedSignal,
  signal,
} from '@angular/core';
```

**3b.** Replace the `AiConfig, AiSettingsService` import line:

```ts
import { AiFailure, SERVER_TEXT_KINDS } from './ai-failure';
import { AiConfig, AiSettingsService } from './ai-settings.service';
```

**3c.** Delete the two existing computeds `failureDetail` and `failureKey` (lines 99–109) and put these in their place:

```ts
  /** The list card answers for the initial load; a row's own write answers
   *  in the row, and the add form answers under itself. One shared banner
   *  could only ever be right for one of the three (#415). */
  readonly listFailure: Signal<string | null> = computed(() => this.messageFor('load'));
  readonly addFailure: Signal<string | null> = computed(() => this.messageFor('add'));

  rowFailure(configId: number): string | null {
    const scoped = this.ai.failure();
    if (!scoped || scoped.scope.action !== 'row') return null;
    if (scoped.scope.configId !== configId) return null;

    return this.message(scoped.failure);
  }

  private messageFor(action: 'load' | 'add'): string | null {
    const scoped = this.ai.failure();
    if (!scoped || scoped.scope.action !== action) return null;

    return this.message(scoped.failure);
  }

  /**
   * The server's own sentence, for the kinds whose next move really is
   * "correct the form and retry". The rest keep a translated message, because
   * the backend's prose does not say "enter the key again" or "wait a few
   * minutes" — and in German it would not say it in German.
   *
   * A production 500 and a dead connection carry no sentence at all, which is
   * the one case the generic fallback is for.
   */
  private message(failure: AiFailure): string {
    if (!SERVER_TEXT_KINDS.has(failure.kind)) return this.errorText(failure.kind);
    if (failure.fieldErrors.length) return this.fieldText(failure);

    return failure.detail ?? this.errorText(failure.kind);
  }

  /** `apiKey` becomes "API key"; a path this build does not know keeps its
   *  raw name, which is still more use than dropping the message. */
  private fieldText(failure: AiFailure): string {
    return failure.fieldErrors
      .map((fieldError) => {
        const key = `settings.ai.fields.${fieldError.field}`;
        const label = this.i18n.translate(key);
        const name = label === key ? fieldError.field : label;

        return `${name}: ${fieldError.messages.join(' ')}`;
      })
      .join(' ');
  }

  private errorText(kind: AiFailure['kind']): string {
    return this.i18n.translate(`settings.ai.errors.${kind}`);
  }
```

**3d.** Replace `add()` (line 128):

```ts
  add(): void {
    this.ai.add(
      {
        name: this.newName().trim() || null,
        baseUrl: this.newBaseUrl().trim(),
        apiKey: this.newApiKey().trim(),
      },
      () => this.clearDraft(),
    );
  }

  /** Runs on success only. A rejected add leaves the endpoint and the key
   *  exactly as the account typed them. */
  private clearDraft(): void {
    this.newName.set('');
    this.newBaseUrl.set('');
    this.newApiKey.set('');
  }
```

- [ ] **Step 4: Write the template**

In `frontend/src/app/settings/ai-section.component.html`:

**4a.** Replace the shared banner block at the end of the list card (lines 178–182) with the load-scoped one:

```html
  @if (listFailure(); as message) {
    <app-error-banner [message]="message" />
  }
```

**4b.** Inside the row, add the row banner. Put it at the end of `<div class="config-body">`, immediately before its closing `</div>` (currently line 171):

```html
              @if (rowFailure(config.id); as message) {
                <app-error-banner [message]="message" />
              }
```

**4c.** Inside the add card, add the add banner. Put it at the end of `<div class="group add-group">`, immediately after the `<app-button …>` block that closes on line 234 and before that div's closing `</div>`:

```html
    @if (addFailure(); as message) {
      <app-error-banner [message]="message" />
    }
```

- [ ] **Step 5: Run the tests to verify they pass**

```bash
cd frontend && npx jest src/app/settings/ai-section.component.spec.ts
```

Expected: PASS, including the pre-existing tests at lines 519, 537 and 562 that assert the card layout.

- [ ] **Step 6: Run the whole gate**

```bash
cd frontend && npm run check
```

Expected: ESLint, Prettier, Stylelint and the full Jest suite all pass. If Prettier complains, it is almost certainly a line over 100 columns in the test code above — break it rather than reflowing the source.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/settings/ai-section.component.ts frontend/src/app/settings/ai-section.component.html frontend/src/app/settings/ai-section.component.spec.ts
git commit -m "fix(#415): answer each failure where it happened and keep the draft"
```

---

### Task 5: Prove it end to end against a stubbed rejection

A Jest test drives the component; this drives the browser, which is the only place the three faults were visible together.

**Files:**
- Create: `frontend/e2e/ai-config-rejected.spec.ts`

**Interfaces:**
- Consumes: nothing from earlier tasks at the code level; it asserts the shipped behaviour.

**Why both routes are stubbed.** **An e2e spec must own the data it asserts on.** Reading whatever the seeded account happens to hold passes on a developer machine and fails on a fresh database (#96). Stubbing also means the test never creates a configuration, so it leaves the fixture exactly as it found it — and never makes an outbound call to a real provider.

The login helper and the `test.skip` guard below are lifted from `frontend/e2e/magazine-kicker-one-line.spec.ts`, minus its `stubEntries` call, which this page does not need.

The AI section lives at `/settings/ai` (`frontend/src/app/settings/settings.routes.ts:35`), not at `/settings`.

- [ ] **Step 1: Write the spec**

Create `frontend/e2e/ai-config-rejected.spec.ts` with exactly this:

```ts
// e2e/ai-config-rejected.spec.ts
import { test, expect, Page } from '@playwright/test';

// The seeded e2e admin, as in `magazine-kicker-one-line.spec.ts`.
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'e2e-admin-password-123';

const ENDPOINT = 'https://api.example.test/v1';
const TOO_SHORT_KEY = 'short';
const SERVER_MESSAGE = 'This value is too short. It should have 8 characters or more.';

/**
 * An empty list and a rejected add. Both are stubbed so the spec owns every
 * value it asserts on: nothing is created, nothing seeded is read, and no
 * outbound call reaches a real provider.
 *
 * Matched on the pathname, so `/api/me/ai` and `/api/me/ai/configs` do not
 * catch each other.
 */
async function stubAi(page: Page): Promise<void> {
  await page.route(
    (url) => url.pathname === '/api/me/ai',
    (route) => route.fulfill({ status: 200, json: { configs: [], activeId: null } }),
  );

  await page.route(
    (url) => url.pathname === '/api/me/ai/configs',
    (route) => {
      if (route.request().method() !== 'POST') return route.fallback();
      return route.fulfill({
        status: 422,
        contentType: 'application/problem+json',
        body: JSON.stringify({
          type: 'validation_error',
          title: 'Validation failed',
          status: 422,
          detail: 'One or more fields are invalid.',
          errors: { apiKey: [SERVER_MESSAGE] },
        }),
      });
    },
  );
}

async function signInAsAdmin(page: Page): Promise<boolean> {
  await stubAi(page);
  await page.goto('/login');
  await page.locator('input[type=email]').fill(ADMIN_EMAIL);
  await page.locator('input[type=password]').fill(ADMIN_PASSWORD);
  await page.getByRole('button', { name: 'Sign in' }).click();

  const sidebar = page.getByRole('navigation', { name: 'Feeds' });
  const loginError = page.getByRole('alert');
  await expect(sidebar.or(loginError)).toBeVisible();
  return sidebar.isVisible();
}

/**
 * The three faults of #415 in one pass: the banner must name the field with
 * the server's own sentence, it must sit under the form that failed, and the
 * typed values must survive.
 */
test('a rejected configuration keeps the typed values and names the field', async ({ page }) => {
  const signedIn = await signInAsAdmin(page);
  test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');

  await page.goto('/settings/ai');

  // The add card is collapsed by default; its summary opens it.
  const addCard = page.locator('app-settings-card').filter({ has: page.locator('.add-config') });
  await addCard.locator('summary').click();

  await addCard.locator('input[type=url]').fill(ENDPOINT);
  await addCard.locator('input[type=password]').fill(TOO_SHORT_KEY);
  await addCard.locator('.add-config button').click();

  // 1. the server's sentence, naming the field — not "Something went wrong".
  // 2. under the add form, not on the configuration list above it.
  await expect(addCard.locator('app-error-banner')).toHaveText(`API key: ${SERVER_MESSAGE}`);

  const listCard = page.locator('app-settings-card').filter({ has: page.locator('.configs') });
  await expect(listCard.locator('app-error-banner')).toHaveCount(0);

  // 3. the values survive the rejection.
  await expect(addCard.locator('input[type=url]')).toHaveValue(ENDPOINT);
  await expect(addCard.locator('input[type=password]')).toHaveValue(TOO_SHORT_KEY);
});
```

Note on the `listCard` assertion: with the list stubbed empty there is no `.configs` list, so that locator resolves to nothing and `toHaveCount(0)` passes trivially. That is intended — the placement claim is carried by the `addCard` assertion above it, and the Jest test `'shows a failed add under the add form, not above the configs list'` in Task 4 is what proves the negative against a populated list.

- [ ] **Step 2: Bring up the stack and run it**

```bash
docker compose up -d
```

```bash
cd frontend && npx playwright test e2e/ai-config-rejected.spec.ts
```

Expected: PASS. The suite needs the Docker stack; it is deliberately outside the CI gate.

- [ ] **Step 3: Leave the fixture as you found it**

The spec stubs both routes, so it writes nothing. Confirm no configuration was created for the test account:

```bash
docker compose exec php bin/console dbal:run-sql "SELECT COUNT(*) FROM user_ai_settings"
```

Expected: the same count as before the run.

- [ ] **Step 4: Commit**

```bash
git add frontend/e2e/ai-config-rejected.spec.ts
git commit -m "test(#415): prove a rejected add keeps its values and names the field"
```

---

### Task 6: Check the real page, then open the PR

**Files:** none.

- [ ] **Step 1: Look at it**

With the stack up, open `https://localhost:8443/settings/ai`, enter a working endpoint with a 5-character key, and press **Add configuration**. Confirm all three by eye:

1. the banner names the API key and gives the server's reason,
2. it sits under the add form, not above the configuration list,
3. the endpoint and the key are still in their boxes.

Then enter a real endpoint that does not answer, and confirm the banner still shows the provider sentence (`That address did not answer.`) under the add form.

- [ ] **Step 2: Scan the backend log**

```bash
docker compose exec php tail -n 100 var/log/dev.log
```

Expected: no new deprecations or swallowed errors from the settings page. This change is frontend-only, so a backend entry here means something else is wrong.

- [ ] **Step 3: Run the full gate one more time**

```bash
cd frontend && npm run check
```

Expected: PASS. Do not claim the work is done on a remembered result — run it and read the output.

- [ ] **Step 4: Open the PR**

```bash
gh pr create --base develop --title "fix(#415): report a rejected AI configuration where it happened, with the server's reason" --body "Closes #415"
```

`develop` is the default branch, so `Closes #415` auto-closes the issue on merge. Verify it actually closed after the merge rather than closing it by hand.

---

## Notes on decisions taken

**Why the banner and not per-field errors.** The `FieldComponent` already has an unused `error` input, so per-field rendering was available. Banner-only was chosen deliberately: it keeps one render path for every failure kind, and the field name travels inside the sentence instead. If per-field rendering is wanted later, `AiFailure.fieldErrors` is already the right shape to feed it — that is why it is structured data and not a pre-joined string.

**Why a production 500 still shows the generic sentence.** The only text such a response carries is the title, `"Internal server error"` — untranslated, and less use than `"Something went wrong. Try again."`, which at least tells the account to retry. Passing `detail` through was rejected at the source: `ApiExceptionListener` withholds it on purpose.

**Why `SERVER_TEXT_KINDS` is a set and not a chain of `if`s.** The choice of which kinds show server prose is a single fact about the design. As data it is one line a test reads back; as branching it would be four places to keep in step.
