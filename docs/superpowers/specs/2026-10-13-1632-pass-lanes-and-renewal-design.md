# #1632 — one engine run per pass, per-kind lanes, typed slots (design)

Issue: https://github.com/larspohlmann/simple-feed-reader/issues/1632. Follow-up to #1608 (PR #1631). Design agreed
2026-10-13: renewal approach A, lanes and typed slots as below. No user-visible behaviour change except where named.

## 1. The engine settles each answer through the pass (renewal inside the one run)

- `ConcurrentRequestEngine::sendAll` takes one pass-side collaborator instead of `?DispatchGateInterface`: a new
  interface in `Service/Fetch` (working name `AnswerSettlerInterface`, in its own interface folder) with
  - `admits(int|string $key): bool` — today's gate, asked once per attempt that is a first send;
  - `settle(int|string $key, FetchOutcomeModel $outcome): SettlementModel` — turns a raw outcome into either a
    finished value the engine yields under the key, or a follow-up `RequestTicketModel` for the same key.
- `SettlementModel` (Fetch `Model/`, PHPStan `@template`) has two named constructors: `outcome(T)` and
  `followUp(RequestTicketModel)`. The engine output becomes `\Generator<int|string, T>`.
- Every place the engine yields today (withheld by the gate, failed before sending, failed after retries, concluded)
  goes through `settle`. A follow-up is requeued as a fresh attempt for the same key (`FetchAttemptModel::start` with the
  batch proxy) through `FetchQueue`; it never passes through the ticket source, so `BudgetedFeedQueue` never counts it as
  a start. It is a first send, so the settler's `admits` is asked again — a just-rejected or throttled account stays off
  the wire. The unreadable-proxy path (`failEvery`) also settles each failed outcome; a follow-up there is a
  `\LogicException` (no queue exists; no settler answers a failure with a follow-up).
- Single-feed callers (`ConcurrentFeedFetcher`) use a pass-through settler (admits all, yields the outcome as is).
- `DispatchGateInterface` is deleted once nothing implements it.
- Abort: abandoning the generator still cancels everything in flight, follow-ups included.

### Renewal rule (Social)

- `TimelineRound::settle` returns `SettlementModel`, never null. On a stale-session answer for a feed:
  - a stale answer to a ticket built on the account's renewed session is a rejection:
    `SocialCredentialsRejectedException`, the account is marked needs-reconnect, the feed settles as failed (today's
    semantics);
  - a stale answer to a ticket built before the renewal drops the session (`dropSession`) and marks the account renewed
    unless that already happened — at most one renewal, hence one session open, per account per pass;
  - then build the feed's ticket again (`ticketOf`): a held account yields its held outcome, a request that fails to
    build yields its failure, else `followUp(ticket)`.
- Behaviour change, named: several requests of one account in flight with the old token each come back stale; each is
  sent once more with the renewed session instead of waiting for a second run. Request count per stale feed is the same
  as today (one more). The session open (blocking) now happens while other requests are in flight — the same accepted
  risk #1608 named for the first open.
- Deleted: `TimelineRound::startRenewalWave`, `staleFeedIds`, `settledOrQueued`, `TimelineAccounts::$awaitingSession`
  and `renewSessions` (replaced by a "renewed once" check), the second `run()` in `ConcurrentPassFetcher`, the null
  contract of `settle` at every level.
- `ConcurrentPassFetcher::fetchAll` becomes one loop: for each engine outcome, drain the unasked outcomes, then yield it;
  drain once more at the end.

## 2. A feed's kind is decided once: per-kind lanes

- `Service/Refresh/PassLane/PassLaneInterface` with `FeedLane` (RSS/Atom) and `TimelineLane` (private, over the pass's
  `TimelineRound`). A lane builds a feed's tickets, admits a key, settles a raw outcome into
  `SettlementModel<PassOutcomeModelInterface>`, and drains outcomes settled without a request (`FeedLane`: none).
- `PassFeeds` decides each feed id's lane once (`laneOf($feedId)`); `isPrivate()` is deleted. `PassTickets` implements
  the engine's settler and delegates every call to the key's lane.
- `PassOutcomeModelInterface` (Refresh `Model/`): `storeIn(PassOutcomeStore $store, Feed $feed): FeedRefreshResultModel`.
  `FeedPassOutcomeModel` wraps a `FetchOutcomeModel` and calls `storeFetched`; `TimelinePassOutcomeModel` wraps a
  `TimelineOutcomeModelInterface` and calls `storeTimeline`. `PassOutcomeStore` loses the RSS-vs-private `match`; the
  match over timeline result kinds stays in `storeTimeline` (it is about the result, not the feed kind, and cannot move
  into Social without Social importing Refresh).
- `PassFetcherInterface::fetchAll` yields `PassOutcomeModelInterface`; `RefreshPassRecorder` calls `storeIn`.
  `StubPassFetcher` wraps its scripted outcomes the same way, deciding the kind from the feed's source format (test
  code).

## 3. Slot kinds are types

- `Service/Fetch/Model/RequestSlotModelInterface`: `keyFor(string $url): string`, `capacity(): int`.
  `HostSlotModel` (key = host of the attempt's current URL; follows redirects) replaces `RequestSlotModel::perHost`.
- `RequestSlots` namespaces each key by the slot's class (`$slot::class . "\0" . $slot->keyFor($url)` or equivalent), so
  two slot kinds never share a cap whatever their keys spell; the `host:`/`slot:`/`social-host:` prefixes go away.
- Social owns `SocialAccountSlotModel` (key: account id, capacity `social_account_concurrency`) and
  `SocialServerSlotModel` (key: host of the request URL, fixed at construction, capacity `social_host_concurrency`);
  `TimelineSlotsModel` builds the ticket with both.
- `RequestSlotModel` and its `named()` are deleted. Engine and slot tests use a test-only fixed-key slot in
  `tests/Support`. Capacity is still validated at least one (`CapacityGuard`) in every slot type.

## Done when (tests)

- One engine run per pass: a stale Bluesky session renews and its feed is sent again inside the run; two in-flight
  requests of one account with the old token renew the session once (one `dropSession`, one session open) and both feeds
  are re-sent once.
- A feed's second stale answer marks the account needs-reconnect and the gate then withholds its other feeds.
- A follow-up does not count as a start against the budget (`startedFeedIds` / `remaining` unchanged by it), and the
  gate is asked for it.
- Abort while a follow-up is in flight cancels it.
- An RSS host slot and a social-server slot on the same host do not share a cap; two slot types with an equal key do not
  either.
- Every existing refresh, timeline, engine and slot test keeps passing (tests may follow moved code).

## Constraints

CLAUDE.md in full: Clean Code, role folders (§10), no Service-module cycle (Fetch imports neither Social nor Refresh;
Social does not import Refresh), phptramp, PHPMD clean on touched `src`, PHPStan level max, no needless comments.
Gates: `composer check`, `composer md`, `composer test:parallel`, the MySQL leg, `composer infection:diff`, PhpStorm
lint. Commits `type(#1632): …`, no attribution. Merge develop into the branch between tasks. Never deploy.
