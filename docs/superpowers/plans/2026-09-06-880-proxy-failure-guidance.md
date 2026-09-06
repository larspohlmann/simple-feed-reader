# #880 Proxy Failure Guidance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make egress-proxy failures actionable everywhere they surface (mail, fetch, Test button), force IPv4 on the proxied egress path the way the mail path already does, and show the common proxy pitfall inline in the admin form.

**Architecture:** `ProxyHandshakeFailure::explain()` already maps SOCKS5 reply codes to admin-actionable sentences and is applied on the Test button and the fetch path. We (1) broaden its `(4)`/`(8)` wording, (2) route proxied SMTP failures through it via a small static-factory exception mirroring `FetchException::from`, (3) add the `#861` IPv4 forcing to `EgressOptions::proxied()` (via the Symfony HttpClient `extra.curl` option), and (4) add a short always-visible pitfalls note to the proxy settings form.

**Tech Stack:** Symfony 7.4 / PHP 8.4 (backend, PHPUnit), Angular 20 standalone + signals + Transloco (frontend, Jest).

**Spec:** GitHub issue #880 (`https://github.com/larspohlmann/simple-feed-reader/issues/880`).

## Global Constraints

- Branch `fix/880-proxy-failure-guidance` off `develop`; commit per task; PR into `develop` with `Closes #880`.
- PHP: `declare(strict_types=1)` in every file; PSR-12; PHPStan level max; **every touched `src` file must be PHPMD-clean**; Clean Code (names reveal intent, guard clauses, `final`, few params).
- Comments: one line, three at the absolute most, and only for the *why*.
- Frontend: standalone + signals; component styles in the sibling `.scss`; no hex colours or ad-hoc `px` in `.scss` outside `src/app/theme/`; add every new Transloco key to **both** `public/i18n/en.json` and `public/i18n/de.json`.
- Run frontend tests/lint **inside the Docker frontend container** (`docker compose exec -T frontend …`).
- Native iOS readiness: no change to request/response contracts; these are message-text and transport-option changes only.

---

### Task 1: Broaden the `(4)`/`(8)` guidance wording

**Files:**
- Modify: `backend/src/Service/Fetch/ProxyHandshakeFailure.php` (the `REMOTE_DNS_HINT` constant)
- Test: `backend/tests/Service/Fetch/ProxyHandshakeFailureTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: no signature change. `ProxyHandshakeFailure::explain(string): string` still, but a `(4)`/`(8)` reply now also names the IPv4-only-on-a-dual-stack-host cause.

- [ ] **Step 1: Add a failing test for the IPv4 cause being named**

Add to `ProxyHandshakeFailureTest.php`:

```php
    public function testTheHostUnreachableHintAlsoNamesTheIpv4OnlyProxyCause(): void
    {
        $explained = ProxyHandshakeFailure::explain(
            'cannot complete SOCKS5 connection to api.ipify.org. (4)',
        );

        self::assertStringContainsString('does not resolve host names', $explained);
        self::assertStringContainsString('IPv4', $explained);
    }
```

- [ ] **Step 2: Run it and watch it fail**

Run: `cd backend && php bin/phpunit --filter testTheHostUnreachableHintAlsoNamesTheIpv4OnlyProxyCause`
Expected: FAIL — the current hint has no `IPv4` text.

- [ ] **Step 3: Broaden the hint text**

In `ProxyHandshakeFailure.php` replace the `REMOTE_DNS_HINT` constant with:

```php
    private const string REMOTE_DNS_HINT = 'Two proxy limits cause this. With "Resolve DNS at the proxy" on, '
        . 'a proxy that does not resolve host names — Private Internet Access is one — reports every name '
        . 'unreachable; turn it off. With it off, an IPv4-only proxy can reject a name this host resolved to IPv6.';
```

- [ ] **Step 4: Run the whole file green**

Run: `cd backend && php bin/phpunit tests/Service/Fetch/ProxyHandshakeFailureTest.php`
Expected: PASS (the existing `does not resolve host names` assertions for `(4)` and `(8)` still hold; the new `IPv4` assertion passes).

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Fetch/ProxyHandshakeFailure.php backend/tests/Service/Fetch/ProxyHandshakeFailureTest.php
git commit -m "fix(#880): name the IPv4-only proxy cause in the SOCKS5 reason hint"
```

---

### Task 2: Route proxied SMTP failures through the explainer

**Files:**
- Create: `backend/src/Service/Mail/Transport/Exception/ProxiedSmtpSendException.php`
- Modify: `backend/src/Service/Mail/Transport/CurlSmtpTransport.php` (the `curl_exec` failure branch, ~line 63)
- Test: `backend/tests/Service/Mail/Transport/Exception/ProxiedSmtpSendExceptionTest.php`

**Interfaces:**
- Consumes: `ProxyHandshakeFailure::explain()` from Task 1.
- Produces: `ProxiedSmtpSendException::fromCurlError(string $curlError): self` — a `TransportExceptionInterface` whose message is `"Proxied SMTP send failed: "` + the explained text.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Service/Mail/Transport/Exception/ProxiedSmtpSendExceptionTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Transport\Exception;

use App\Service\Mail\Transport\Exception\ProxiedSmtpSendException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

final class ProxiedSmtpSendExceptionTest extends TestCase
{
    public function testItExplainsASocksReplyCodeInsteadOfShowingTheRawByte(): void
    {
        $exception = ProxiedSmtpSendException::fromCurlError(
            'cannot complete SOCKS5 connection to smtp.gmail.com. (4)',
        );

        self::assertInstanceOf(TransportExceptionInterface::class, $exception);
        self::assertStringContainsString('Proxied SMTP send failed:', $exception->getMessage());
        self::assertStringContainsString('does not resolve host names', $exception->getMessage());
    }

    public function testItLeavesAnUnrelatedCurlMessageIntact(): void
    {
        $exception = ProxiedSmtpSendException::fromCurlError('Failed to connect to proxy port 1080');

        self::assertStringContainsString('Failed to connect to proxy port 1080', $exception->getMessage());
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `cd backend && php bin/phpunit tests/Service/Mail/Transport/Exception/ProxiedSmtpSendExceptionTest.php`
Expected: FAIL — class does not exist.

- [ ] **Step 3: Create the exception**

Create `backend/src/Service/Mail/Transport/Exception/ProxiedSmtpSendException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Transport\Exception;

use App\Service\Fetch\ProxyHandshakeFailure;
use Symfony\Component\Mailer\Exception\TransportException;

/**
 * A proxied SMTP send that failed at the curl layer, with the raw curl message
 * run through the same admin-facing explainer the fetch path and the proxy Test
 * button use — so a SOCKS5 reply code becomes a reason, not a bare byte (#880).
 */
final class ProxiedSmtpSendException extends TransportException
{
    public static function fromCurlError(string $curlError): self
    {
        return new self(sprintf('Proxied SMTP send failed: %s', ProxyHandshakeFailure::explain($curlError)));
    }
}
```

- [ ] **Step 4: Run the test green**

Run: `cd backend && php bin/phpunit tests/Service/Mail/Transport/Exception/ProxiedSmtpSendExceptionTest.php`
Expected: PASS.

- [ ] **Step 5: Wire the transport to the factory**

In `backend/src/Service/Mail/Transport/CurlSmtpTransport.php`, add the import
`use App\Service\Mail\Transport\Exception\ProxiedSmtpSendException;` and replace the
`curl_exec` failure branch:

```php
            if (false === curl_exec($handle)) {
                throw ProxiedSmtpSendException::fromCurlError(curl_error($handle));
            }
```

- [ ] **Step 6: Confirm the existing transport test still passes**

Run: `cd backend && php bin/phpunit tests/Service/Mail/Transport/CurlSmtpTransportTest.php`
Expected: PASS — `ProxiedSmtpSendException` is a `TransportExceptionInterface`, so `testAnUnreachableProxyRaisesATransportException` still holds.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Service/Mail/Transport/Exception/ProxiedSmtpSendException.php backend/src/Service/Mail/Transport/CurlSmtpTransport.php backend/tests/Service/Mail/Transport/Exception/ProxiedSmtpSendExceptionTest.php
git commit -m "fix(#880): explain proxied SMTP failures instead of the raw SOCKS byte"
```

---

### Task 3: Force IPv4 on the proxied egress path

**Files:**
- Modify: `backend/src/Service/Fetch/EgressOptions.php` (the `proxied()` method + its docblock)
- Test: `backend/tests/Service/Fetch/EgressOptionsTest.php`

**Interfaces:**
- Consumes: `ProxyConfig::resolvesLocally(): bool` (already exists — true only for plain `socks5`).
- Produces: `EgressOptions::proxied(ProxyConfig): array{proxy: string, no_proxy: string, extra?: array{curl: array<int, int>}}`. When the proxy resolves locally, the map carries `extra.curl[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4`. Consumed unchanged by `ProxyConnectionTester`, `FailoverRequestSender::attemptProxied`, `ConcurrentFeedFetcher::send` (none of them pass their own `extra`, so nothing is clobbered).

- [ ] **Step 1: Write the failing tests**

Add to `backend/tests/Service/Fetch/EgressOptionsTest.php`:

```php
    public function testProxiedForcesIpv4WhenTheProxyResolvesNamesLocally(): void
    {
        $proxy = new ProxyConfig(ProxyType::Socks5, 'p', 1080, null, null); // remoteDns off → socks5

        $curl = EgressOptions::proxied($proxy)['extra']['curl'];

        self::assertSame(\CURL_IPRESOLVE_V4, $curl[\CURLOPT_IPRESOLVE]);
    }

    public function testProxiedDoesNotForceIpv4WhenTheProxyResolvesNames(): void
    {
        // remoteDns on → socks5h → curl hands the proxy the name, not an address.
        $proxy = new ProxyConfig(ProxyType::Socks5, 'p', 1080, null, null, true, true);

        self::assertArrayNotHasKey('extra', EgressOptions::proxied($proxy));
    }

    public function testProxiedDoesNotForceIpv4ForAnHttpProxy(): void
    {
        $proxy = new ProxyConfig(ProxyType::Http, 'p', 8080, null, null);

        self::assertArrayNotHasKey('extra', EgressOptions::proxied($proxy));
    }
```

- [ ] **Step 2: Run them and watch them fail**

Run: `cd backend && php bin/phpunit tests/Service/Fetch/EgressOptionsTest.php`
Expected: FAIL — no `extra` key is produced yet.

- [ ] **Step 3: Add the IPv4 forcing**

In `EgressOptions.php`, replace `proxied()` and its docblock:

```php
    /**
     * @return array{proxy: string, no_proxy: string, extra?: array{curl: array<int, int>}}
     */
    public static function proxied(ProxyConfig $proxy): array
    {
        $options = ['proxy' => $proxy->dsn(), 'no_proxy' => ''];
        if ($proxy->resolvesLocally()) {
            // Hand the proxy an IPv4 address; an IPv4-only SOCKS5 rejects a
            // locally resolved IPv6 on a dual-stack host (#861, as CurlSmtpOptions).
            $options['extra']['curl'][\CURLOPT_IPRESOLVE] = \CURL_IPRESOLVE_V4;
        }

        return $options;
    }
```

Keep the existing `no_proxy` docblock note; move it above the method if PHPMD/phpcs prefers a single docblock (either is fine as long as it stays accurate).

- [ ] **Step 4: Run the file green**

Run: `cd backend && php bin/phpunit tests/Service/Fetch/EgressOptionsTest.php`
Expected: PASS (the two original `proxied` tests still pass; the three new ones pass).

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Fetch/EgressOptions.php backend/tests/Service/Fetch/EgressOptionsTest.php
git commit -m "fix(#880): force IPv4 on the proxied egress path like the mail path"
```

---

### Task 4: Show the common proxy pitfall inline in the form

**Files:**
- Modify: `frontend/public/i18n/en.json` (add `settings.proxy.pitfalls`)
- Modify: `frontend/public/i18n/de.json` (add `settings.proxy.pitfalls`)
- Modify: `frontend/src/app/settings/admin/proxy/proxy-section.component.html`
- Test: `frontend/src/app/settings/admin/proxy/proxy-section.component.spec.ts`

**Interfaces:**
- Consumes: the existing `.consumers-note` style and the Transloco pipe already used in the template.
- Produces: an always-visible note paragraph directly under the *Resolve DNS at the proxy* row.

- [ ] **Step 1: Add the translation key (both locales)**

In `frontend/public/i18n/en.json`, inside `settings.proxy` (next to `remoteDnsHttpHint`), add:

```json
    "pitfalls": "Common pitfall: many SOCKS5 proxies (Private Internet Access included) cannot resolve host names. Leave \"Resolve DNS at the proxy\" off for those, or every connection reports the host as unreachable.",
```

In `frontend/public/i18n/de.json`, inside `settings.proxy`, add the German equivalent:

```json
    "pitfalls": "Häufige Stolperfalle: Viele SOCKS5-Proxys (auch Private Internet Access) können Hostnamen nicht auflösen. Lassen Sie „DNS am Proxy auflösen“ dafür aus, sonst meldet jede Verbindung den Host als nicht erreichbar.",
```

(First read the `settings.proxy` block of `de.json` and match its existing German wording/quote style for `remoteDns*`.)

- [ ] **Step 2: Write the failing component test**

Add to `proxy-section.component.spec.ts` (mirror the existing `toContain` style at line ~108):

```ts
  it('shows the DNS pitfalls note inline', () => {
    expect(fixture.nativeElement.textContent).toContain('cannot resolve host names');
  });
```

- [ ] **Step 3: Run it and watch it fail**

Run: `docker compose exec -T frontend npx jest --silent -t "pitfalls note inline"`
Expected: FAIL — the note is not rendered yet.

- [ ] **Step 4: Render the note under the remote-DNS row**

In `proxy-section.component.html`, immediately after the closing `</app-settings-row>` of the
*Resolve DNS at the proxy* row (the block ending at line ~81), add:

```html
      <p class="consumers-note">{{ 'settings.proxy.pitfalls' | transloco }}</p>
```

- [ ] **Step 5: Run the component tests green**

Run: `docker compose exec -T frontend npx jest --silent proxy-section`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add frontend/public/i18n/en.json frontend/public/i18n/de.json frontend/src/app/settings/admin/proxy/proxy-section.component.html frontend/src/app/settings/admin/proxy/proxy-section.component.spec.ts
git commit -m "feat(#880): show the DNS-at-proxy pitfall inline in the proxy form"
```

---

### Task 5: Full verification and PR

- [ ] **Step 1: Backend gate**

Run: `cd backend && bin/console cache:warmup && composer check && php bin/phpunit`
Expected: `cs` + `stan` + `tramp` clean; full suite green. If `composer tramp` is red, check `composer show larspohlmann/phptramp` first — CI runs its `develop` tip.

- [ ] **Step 2: Backend PHPMD on touched files + PhpStorm inspections**

Run: `cd backend && composer md`
Then run `mcp__phpstorm__lint_files` on the four changed/created PHP files; block on ERROR and WARNING.
Expected: no findings on touched files.

- [ ] **Step 3: Frontend gate (in Docker)**

Run: `docker compose exec -T frontend npm run check`
Expected: ESLint + Prettier + Stylelint + Jest all green.

- [ ] **Step 4: Scan the dev log**

Run: `ls -t backend/var/log/dev-*.log | head -1` then scan that file for new deprecations/errors.

- [ ] **Step 5: Open the PR**

```bash
git push -u origin fix/880-proxy-failure-guidance
gh pr create --base develop --title "fix(#880): actionable proxy failures, egress IPv4 forcing, in-form pitfalls note" --body "Closes #880"
```

After merge, verify #880 auto-closed.

---

## Self-Review

- **Spec coverage:** #880 item 1 (mail explainer) → Task 2; item 2 (wording names both causes) → Task 1; item 3 (EgressOptions IPv4) → Task 3; item 4 (in-form pitfalls note) → Task 4. Acceptance criteria: mail actionable → Task 2; `4`/`8` names both causes → Task 1; egress IPv4 + Test-button consistency → Task 3; form note → Task 4; tests + unchanged Test/fetch behaviour → Tasks 1–4 keep existing assertions.
- **Type consistency:** `ProxiedSmtpSendException::fromCurlError` used in Task 2 test and transport identically. `EgressOptions::proxied` return shape in Task 3 matches the `extra.curl` access in its tests and the `CURLOPT_FRESH_CONNECT` shape already used by `pinned()`.
- **Placeholders:** none — every step has concrete code or an exact command.
