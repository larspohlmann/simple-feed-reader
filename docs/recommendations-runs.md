# How a "For you" run works

This page explains what happens when the reader generates your "For you"
recommendations, from the moment you press the button to the finished list.

## Pressing "Get recommendations"

Pressing **Get recommendations** starts a *run* on the server. A run reads
your recent articles, sends them to the AI model you configured in
**Settings → AI**, and turns the model's answers into your "For you" list.
The run belongs to the server, not to your browser tab: the tab only watches
progress.

## You can close the browser

Once a run has started, the calculation keeps going server-side and
finishes on its own. You do not have to keep the tab open, keep the screen
on, or stay on the page.

When you come back, the reader shows whatever is current: the run's live
progress if it is still working, or the finished "For you" list if it
completed while you were away. One small caveat: a run that finishes while
the tab is closed shows no notification toast when you return — the result
is simply there.

## How fast it runs

Speed depends on how the server drives the run:

- **Fast:** while a background worker or an on-demand drainer process is
  active, the run advances continuously at full speed and typically
  finishes in one go.
- **Slower fallback:** on a host that cannot spawn a background process,
  the run advances one step per scheduled maintenance ping, so it moves at
  whatever interval the server is set to. It still finishes on its own — it
  just takes longer.

The reader picks the fast path automatically whenever the host allows it;
there is nothing to configure in the UI.

## Stopping a run

**Stop** ends the active run. Stopping is not instant: a request to the AI
provider that is already in flight finishes first, which is why the status
shows *stopping* before it becomes *stopped*. A stopped run keeps the
recommendations it had already banked from completed batches.

## When a run fails

A run fails when the AI provider stays unreachable, rejects your
credentials, or the account's AI configuration is removed mid-run. A failed
run shows the reason in the "For you" view, and you can either:

- **Resume** — continue the failed run at the exact batch where it failed,
  keeping the work that already succeeded; or
- **Start a new run** — begin fresh with the newest articles.

Resume reuses the article snapshot from when the run first started, so if a
lot of time has passed, starting fresh gives more current recommendations.

## Install-dependent behavior

How the fast path is provided depends on the deployment:

- **Docker installs** run a persistent worker container that drives every
  run; nothing else is needed.
- **Worker-less installs** (for example, shared hosting such as Strato)
  rely on an on-demand drainer process that the server starts when your run
  begins, backed by a scheduled maintenance ping as the safety net. If the
  host cannot start processes at all, the ping alone carries the run at the
  slower pace described above.
