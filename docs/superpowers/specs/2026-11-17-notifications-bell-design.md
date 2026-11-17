# Notifications bell in the network head, magazine-width list — design

Issue #2294, follow-up to #2240 (PR #2288). Small change, frontend only.

## Intent

Reaching a network's notifications should not need its card expanded, and the unseen count should sit
with the network's name. The notification list should read like the magazine list, not stretch across
the pane.

## Decided (Lars, visual round 2026-11-17)

- **Bell in the section head.** Each social network's card head shows a bell right of the network name;
  the network's unread count stays right-aligned where it is. The "Notifications" row inside the card
  goes away.
- **Look: badge on the bell (mockup A), 18px.** Bell at `--icon-dense` (18px, the network logo's size),
  `--text-secondary` when there are unseen, `--text-muted` at 0. Unseen count in a solid `--accent` round
  badge (14px tall, ~9px bold digits) on the bell's upper right shoulder. At 0: bell only, no badge.
- **The bell is clickable** and opens that network's notification list (same route as today's row,
  `/?view=notifications&network=<n>`).
- **List width:** the notification list's rows sit in the magazine column: `--magazine-measure` (680px)
  centred, with the airy sheet behind it (`--surface-read` dark, `--surface-2` light), as mocked. The list
  header stays full width. Below the measure the column is simply full width with the list's gutter.

## Approaches

1. **Bell as its own link in the head** (picked): a sibling anchor next to the name, so the name keeps
   opening the network and the bell opens notifications — no nested links.
2. Keep the row and add the bell — rejected: two ways to the same list; Lars wants the row replaced.
3. Bell inside the network link with a click handler that reroutes — rejected: nested interactive
   element, fails a11y and the native-link behaviour (middle-click, long-press).

## My calls (correct me)

- The bell shows wherever today's row shows: networks with a connected account, not in organise mode.
  Because it lives in the head it is now also visible when the card is collapsed.
- When the notifications list for that network is open, the bell is `--accent` (replaces the row's
  active look).
- Badge caps at `99+`, like the header avatar badge.
- On a coarse pointer the bell's hit area is at least 44px without changing its drawn size.
- Accessible name: "<Network> notifications" plus ", N unseen" when N > 0, in en/de/fr/es.
- Header avatar badge and account dropdown stay as they are.
- `SidebarNotificationsRowComponent` and its styles are deleted.

## Left to the builder

How the head's flex row is split between the network link, the bell and the count; the exact badge offset
so it doesn't clip at the card edge; a Jest spec for the bell (count, 0, 99+, active) and the list column;
updating the existing Playwright notifications smoke if it targets the removed row.
