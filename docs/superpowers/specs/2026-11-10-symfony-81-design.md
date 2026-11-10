# Symfony 8.1, persistent connections and a connect cap (#2155)

## Problem

Every reader and feed fetch pays DNS, TCP and TLS setup from scratch. The same article measured ~1 s on a first fetch
and ~280 ms once warm in one process. Since #2148 the readability stage is small (#2158: TechCrunch 650 → 34 ms), so
the fetch is now the largest stage for every article. Separately, `HtmlPageFetcher` and `ConcurrentRequestEngine` only
set `timeout` (20 s) and `max_duration` (40 s): a host that never completes a connection holds the reader for up to
20 s.

Symfony 8.1's HttpClient adds `max_connect_duration` and, on PHP 8.5, `extra.use_persistent_connections`. Persistent
connections use a `curl_share_init_persistent()` handle that shares the DNS cache, TLS sessions and the connection pool
across requests in the same process: a PHP-FPM or FastCGI worker on Strato, and the long-running messenger worker.

## Decision

1. Upgrade every `symfony/*` package from 7.4 to 8.1, together with DoctrineBundle 3.
2. Every pinned request also pins with `CURLOPT_CONNECT_TO`, so a pooled connection is reused only when it goes to the
   address the guard approved for this request.
3. Enable persistent connections on the reader page fetch and the feed fetch.
4. Give both of those fetches `max_connect_duration: 5` seconds.

7.4 and 8.0 share one feature set (8.0 only drops deprecations), so the target is 8.1. 8.2 is in beta and out of scope.

## 1. Upgrade

- `symfony/*` constraints and `extra.symfony.require` go to `8.1.*`. `symfony/flex`, `monolog-bundle` and the
  `ai-mate` packages stay on their own version lines, moving only if the resolver requires it.
- DoctrineBundle `^2.18.3` → `^3.3`. `composer prohibits symfony/framework-bundle 8.1.0` names it as the only package
  that blocks the move. DBAL 4.4, Persistence 4.2 and ORM 3.6 already meet its requirements.
- `config/packages/doctrine.yaml` uses options that DoctrineBundle 3 removes or turns into fixed defaults:
  `auto_generate_proxy_classes`, `proxy_dir` (prod), `enable_native_lazy_objects`, `use_savepoints`,
  `report_fields_where_declared` and `controller_resolver.auto_mapping`. Each is checked against the bundle's
  `UPGRADE-3.0.md`: an option whose behaviour is now the default is deleted, and any other is migrated as that file says.
- `composer update` re-resolves only what the upgrade needs: the `symfony/*` packages, `doctrine/doctrine-bundle` and
  the packages they require. The lock diff in the PR lists every other package that moved and why.
- Flex recipe updates (`composer recipes:update`) are reviewed one by one. Files that recipes own and that were
  customised (`public/index.php`, among others) are not overwritten. Config changes a recipe brings are applied only
  where 8.1 requires them.
- Deprecations: 7.4's deprecation layer was clean (the dev log had none). Anything 8.1 removes or newly deprecates
  surfaces in the test run or the dev log and is fixed in the same change.

## 2. The pin covers connection reuse

`EgressOptions::pinned()` sets `resolve` (`CURLOPT_RESOLVE`), which steers only new connections. curl pools
connections by host and port. A request pinned to address B reuses an open connection to the same host and port that
goes to address A, and B is ignored. `EgressOptions` already works around this for IPv4/IPv6 failover with
`CURLOPT_FRESH_CONNECT`.

A spike on PHP 8.5.12 and curl 8.23 confirmed this. It used two keep-alive servers on 127.0.0.1 and 127.0.0.2 behind
one hostname and a persistent share:

| Pin | `resolve` only | `resolve` + `CURLOPT_CONNECT_TO` |
|---|---|---|
| .1 | server-1, new connection | server-1, new connection |
| .2 | **server-1, reused** | server-2, new connection |
| .1 | server-1, reused | server-1, reused |

Today this is contained: every pooled connection was approved by `UrlGuard` when it was opened, and the pool lives
only as long as one request's multi handle. Persistent connections extend the pool across requests and users, so the
pin has to cover reuse.

`EgressOptions::pinned()` adds `CURLOPT_CONNECT_TO` with `host:port:pinned-ip:port`, built from the same `GuardedUrl`
values. Symfony allows the option in `extra.curl`; it is not one of the options Symfony itself sets. curl makes the
connect-to target part of the connection's identity: a matching pin reuses the connection, a different one opens a new
connection.

- This applies to every pinned request, not only the persistent ones. It holds the existing guarantee more tightly
  everywhere.
- With connect-to in the identity, a failover attempt pinned to the other family can no longer reuse the first
  family's connection, so `freshConnectionAfter()` and its `CURLOPT_FRESH_CONNECT` go. The new test covers that case
  too.
- Proxied requests (`EgressOptions::proxied()`) carry no pin today (socks5h resolves at the proxy) and get no
  connect-to.
- `CURLOPT_CONNECT_TO` takes a port. The guarded URL's effective port (an explicit port, or 80/443 by scheme) must be
  the one in the URL, or curl ignores the entry.

`extra` is one nested option, and today's spreads (`[...$options, ...EgressOptions::pinned()]`) replace it wholesale.
A caller's `extra.use_persistent_connections` and the pin's `extra.curl` must both survive the merge. The merge of
`extra` becomes explicit, and a test asserts both keys reach the client.

## 3. Persistent connections

- **Scope:**
  - The reader page fetch: `HtmlPageFetcher` → `PageLanding` → `RedirectFollower` → `FailoverRequestSender`.
  - The feed fetch: `ConcurrentRequestEngine`.
  - Image downloads, the image probe, LLM and scoring clients, Meilisearch, OAuth, the catalog checker and the proxy
    tester keep per-request connections. Their hosts are local, one-off or not latency-critical.
- **How:** both paths pass `extra.use_persistent_connections: true` on their requests. Symfony picks the persistent
  share per request.
- **Lifetime:** under PHP-FPM, Strato's FastCGI and the worker, the share lives as long as the worker process. The
  first open of a host in a fresh process pays full setup; later opens in that process skip DNS, and reuse a TLS
  session or the whole connection.
- **Isolation:** connections, the DNS cache and TLS sessions are shared between users of one process. None of them
  carries user state: cookies, headers and bodies are per request. With the connect-to pin, a reused connection always
  goes to an address the guard approved for the current request.

## 4. Connect cap

`max_connect_duration: 5` on the reader page fetch and the feed fetch. In curl the connect phase includes DNS, TCP and
TLS.

Measured from the Docker host to the 12 benchmark hosts, median of 3: DNS 2–5 ms, TCP 14–28 ms, TLS 28–67 ms, with one
outlier at 251 ms (electronicgroove). 5 s is about 20× the slowest observed connect. That is ample for a distant or
slow host, and it ends a black-holed connect in 5 s instead of 20.

A connect timeout is a transport failure, so `FailoverRequestSender`'s family failover still applies. A host whose
IPv6 never connects now fails over to IPv4 after 5 s instead of 20 (cf. the dead-family case on Strato).

## Tests

- **Connect-to pin:** an integration test with two keep-alive servers on 127.0.0.1 and 127.0.0.2, one port and one
  hostname. It sends requests through the real `CurlHttpClient` with `EgressOptions::pinned()` and persistent
  connections on: pin .1, pin .2, pin .1. It asserts which server answered each request, and that the third request
  reused a connection. It must fail without the connect-to pin, shown once in the PR.
- The same test with two pin attempts (failover) replaces the reason for `CURLOPT_FRESH_CONNECT`.
- **Option merge:** for each path (reader, feed, proxied, pinned), the options that reach the client hold
  `use_persistent_connections`, the pin's `extra.curl` and `max_connect_duration`.
- **Connect cap:** a request to a non-routable address (for example 10.255.255.1 via a test-only pin, guard bypassed)
  fails within the cap with a transport exception.
- Both test legs, `composer check`, `composer md`, e2e green. No new deprecations in the dev log.

## Verification and measurement

- Docker stack rebuilt (`php` and `worker` images both) and running on 8.1. Reader smoke via `app:reader:audit`.
- #2158 rerun after the deploy: the same script and IDs, plus a cold first open per host and a second open on the same
  host in one process (the stages script and the Strato client). Numbers go on #2158 and in the PR.

## Out of scope

- Symfony 8.2 (beta).
- Persistent connections for the image, LLM, OAuth, search and catalog clients.
- An in-flight decoder for `PageHeadFetcher` (#2157 out of scope).

## Support

8.x minors are BC-promise upgrades every six months until 8.4 LTS (November 2027). 7.4 LTS keeps bug fixes until
November 2028. Leaving 7.4 commits the project to following the 8.x minors.
