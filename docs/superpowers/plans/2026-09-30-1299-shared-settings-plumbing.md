# One home for confirm dialogs and failure messages — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans (inline). Steps use checkbox (`- [ ]`) syntax.

**Goal:** Remove the two most-copied pieces of plumbing (#1299). The confirm-then-act dialog is written out at 18 sites; it becomes one `ConfirmService`. The "detail or title" `failureMessage` is copied 3 times; it moves onto `DraftSettingsService`.

**Architecture:** `ConfirmService` is a root service in `shared/confirm-dialog/`. It opens `ConfirmDialogComponent` with the options every site already passes (`role: 'alertdialog'`, `panelClass: 'app-dialog'`). Existing specs stub `Dialog` at TestBed level, and the root service receives that stub, so the specs keep working unchanged. **Out of scope (lean):** field-error helpers (only 2 copies), `formatDateTime` (a one-line wrapper), the form-dialog clones, and ai-settings' `run()`.

**Tech Stack:** Angular 20, CDK Dialog, Jest in the frontend container.

## Global Constraints

- Starts after #1298 (and #1306, if it gets unblocked) has merged. Branch `refactor/1299-shared-settings-plumbing` off `develop`. Commit format `type(#1299): summary`. First commit copies this plan to `docs/superpowers/plans/`.
- Gate: `docker compose exec -T frontend npm run check`.

---

### Task 1: ConfirmService

**Files:**
- Create: `frontend/src/app/shared/confirm-dialog/confirm.service.ts`, `confirm.service.spec.ts`

- [ ] **Step 1: Failing spec**

```ts
import { TestBed } from '@angular/core/testing';
import { Dialog } from '@angular/cdk/dialog';
import { of } from 'rxjs';
import { ConfirmDialogComponent } from './confirm-dialog.component';
import { ConfirmService } from './confirm.service';

describe('ConfirmService', () => {
  const data = { title: 'Delete?', message: 'Gone for good.', confirmLabel: 'Delete' };

  function serviceClosingWith(result: boolean | undefined): { service: ConfirmService; open: jest.Mock } {
    const open = jest.fn(() => ({ closed: of(result) }));
    TestBed.configureTestingModule({ providers: [{ provide: Dialog, useValue: { open } }] });
    return { service: TestBed.inject(ConfirmService), open };
  }

  it('opens the confirm dialog as an alert dialog', () => {
    const { service, open } = serviceClosingWith(true);
    service.confirmThen(data, () => undefined);
    expect(open).toHaveBeenCalledWith(ConfirmDialogComponent, {
      data,
      role: 'alertdialog',
      panelClass: 'app-dialog',
    });
  });

  it('runs the action only on confirm', () => {
    const action = jest.fn();
    serviceClosingWith(true).service.confirmThen(data, action);
    expect(action).toHaveBeenCalledTimes(1);
  });

  it.each([false, undefined])('skips the action when the dialog closes with %s', (result) => {
    const action = jest.fn();
    serviceClosingWith(result).service.confirmThen(data, action);
    expect(action).not.toHaveBeenCalled();
  });

  it('ask() maps a dismissed dialog to false', (done) => {
    serviceClosingWith(undefined).service.ask(data).subscribe((confirmed) => {
      expect(confirmed).toBe(false);
      done();
    });
  });
});
```

- [ ] **Step 2: Run it and expect FAIL** (module not found): `docker compose exec -T frontend npx jest src/app/shared/confirm-dialog`

- [ ] **Step 3: Implement**

```ts
import { Injectable, inject } from '@angular/core';
import { Dialog } from '@angular/cdk/dialog';
import { Observable, map } from 'rxjs';
import { ConfirmData, ConfirmDialogComponent } from './confirm-dialog.component';

@Injectable({ providedIn: 'root' })
export class ConfirmService {
  private readonly dialog = inject(Dialog);

  ask(data: ConfirmData): Observable<boolean> {
    return this.dialog
      .open<boolean>(ConfirmDialogComponent, { data, role: 'alertdialog', panelClass: 'app-dialog' })
      .closed.pipe(map((confirmed) => confirmed === true));
  }

  confirmThen(data: ConfirmData, action: () => void): void {
    this.ask(data).subscribe((confirmed) => {
      if (confirmed) action();
    });
  }
}
```

- [ ] **Step 4: Run it and expect PASS.** Commit: `refactor(#1299): confirm service`.

### Task 2: Replace the 18 call sites

**Sites** (on develop at the time of writing; re-run `grep -rn "open<boolean>(ConfirmDialogComponent" frontend/src/app`):
- `reader/reader-shell.component.ts`: 1001, 1018, 1196, 1232, 1295
- `reader/manage/manage-actions.service.ts`: 197, 240, 296
- `settings/account-section.component.ts`: 61
- `settings/recommendation-settings-card.component.ts`: 372
- `settings/ai-section.component.ts`: 254
- `settings/passkeys-group.component.ts`: 139
- `settings/admin/mail/mail-section.component.ts`: 335
- `settings/admin/admin-settings/admin-settings.component.ts`: 204
- `admin/admin-users.component.ts`: 150
- `admin/admin-user-detail.component.ts`: 137, 159
- `admin/admin-catalog.component.ts`: 161

- [ ] **Step 1: Rewrite each site.** Before:

```ts
    const ref = this.dialog.open<boolean>(ConfirmDialogComponent, {
      data,
      role: 'alertdialog',
      panelClass: 'app-dialog',
    });
    ref.closed.subscribe((confirmed) => {
      if (confirmed) this.markReadNow(target);
    });
```

After:

```ts
    this.confirm.confirmThen(data, () => this.markReadNow(target));
```

- A site whose callback does more than one thing after `if (!ok) return;` becomes `this.confirm.confirmThen(data, () => { … });`.
- A site that reacts to the *false* branch uses `this.confirm.ask(data).subscribe(…)`.
- Add `private readonly confirm = inject(ConfirmService);`.
- Drop `Dialog`, `ConfirmDialogComponent` and `inject(Dialog)` from a file once nothing else there uses them. Many files still open other dialogs, so check first.
- Delete the copied "a destructive confirmation is an alert…" comments. The service now owns the role.

- [ ] **Step 2: Specs.** The specs stub `Dialog` via TestBed, so they should pass as they are. Where a spec asserts `open` was called with exactly `(ConfirmDialogComponent, {...})`, the options are unchanged. Fix only what fails; don't rewrite passing specs.

- [ ] **Step 3: Verify.** `grep -rn "open<boolean>(ConfirmDialogComponent" frontend/src/app` prints only `confirm.service.ts`. The gate is green. Commit: `refactor(#1299): every confirm goes through ConfirmService`.

### Task 3: failureMessage on DraftSettingsService

**Files:**
- Modify: `frontend/src/app/shared/settings/draft-settings.service.ts`, plus its spec
- Modify: `settings/admin/proxy/proxy-section.component.ts:93`, `settings/admin/mail/mail-section.component.ts:167`, `settings/admin/grafana/grafana-section.component.ts:83`

- [ ] **Step 1: Failing spec** in `draft-settings.service.spec.ts`. Use that spec's existing concrete test subclass and failing-PUT setup, and assert:

```ts
  it('failureMessage falls back from detail to title', () => {
    service.failure.set({ type: 'about:blank', title: 'Request failed', status: 502 });
    expect(service.failureMessage()).toBe('Request failed');
    service.failure.set({ type: 'about:blank', title: 'Request failed', status: 400, detail: 'Bad port' });
    expect(service.failureMessage()).toBe('Bad port');
    service.failure.set(null);
    expect(service.failureMessage()).toBeNull();
  });
```

If `Problem` requires more fields, match `core/problem.ts`.

- [ ] **Step 2: Implement** in `DraftSettingsService`, next to `failure` (import `computed` if missing):

```ts
  readonly failureMessage = computed(() => {
    const failure = this.failure();
    return failure ? (failure.detail ?? failure.title) : null;
  });
```

- [ ] **Step 3:** In the three section components, replace the local `failureMessage` computed with `readonly failureMessage = this.svc.failureMessage;`. That keeps the templates untouched. `recommendation-settings-card` keeps its own version, because it filters dismissed field errors. Make its `purgeFailureMessage` doc comment one line or delete it.

- [ ] **Step 4:** Run the gate, commit `refactor(#1299): failure message has one home`, open the PR (`Closes #1299`) and merge when green.
