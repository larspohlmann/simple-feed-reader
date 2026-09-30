# Proxy removePassword keeps the draft — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (inline; one task). Steps use checkbox (`- [ ]`) syntax.

**Goal:** Removing the proxy password no longer discards typed-but-unsaved proxy edits (#1296).

**Architecture:** `ProxySettingsService.removePassword()` spreads `this.draft()` into the PUT body, exactly like `MailSettingsService.removePassword()`. The duplication between the two is removed later in #1299 — do not dedupe here.

**Tech Stack:** Angular 20, Jest (run inside the Docker frontend container).

## Global Constraints

- Branch `fix/1296-proxy-remove-password-draft` off `develop`. Commit format `type(#1296): summary`.
- First commit: copy this plan to `docs/superpowers/plans/2026-09-30-1296-proxy-remove-password-draft.md` (`docs(#1296): plan …`).
- Frontend gate: `docker compose exec -T frontend npm run check`.

---

### Task 1: Carry the draft in removePassword

**Files:**
- Modify: `frontend/src/app/settings/admin/proxy/proxy-settings.service.ts` (`removePassword`, ~line 62)
- Test: `frontend/src/app/settings/admin/proxy/proxy-settings.service.spec.ts` (after the existing `removePassword()` test, ~line 145)

- [ ] **Step 1: Write the failing test** — add after the `removePassword() PUTs removePassword:true …` test:

```ts
  it('removePassword() carries a pending typed edit rather than discarding it', () => {
    loadState({ hasPassword: true, host: 'old.example' });

    service.setTypedField('host', 'new.example');
    service.removePassword();

    const put = http.expectOne(ENDPOINT);
    expect(put.request.body.removePassword).toBe(true);
    expect(put.request.body.host).toBe('new.example');
    put.flush(state({ hasPassword: false, host: 'new.example' }));
  });
```

- [ ] **Step 2: Run it, expect FAIL** (`host` is `'old.example'`):

`docker compose exec -T frontend npx jest src/app/settings/admin/proxy/proxy-settings.service.spec.ts`

- [ ] **Step 3: Fix** — in `removePassword()` replace the `put(...)` body argument:

```ts
    this.put({ ...this.bodyFromState(current), ...this.draft(), removePassword: true }, (state) => {
```

- [ ] **Step 4: Run the spec, expect PASS**, then the gate `docker compose exec -T frontend npm run check`.

- [ ] **Step 5: Commit, push, PR** (`Closes #1296`), merge when green.

```bash
git add frontend/src/app/settings/admin/proxy/proxy-settings.service.ts frontend/src/app/settings/admin/proxy/proxy-settings.service.spec.ts
git commit -m "fix(#1296): proxy removePassword keeps unsaved typed edits"
```
