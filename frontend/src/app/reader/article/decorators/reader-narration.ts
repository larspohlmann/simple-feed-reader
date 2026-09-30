/**
 * Wraps a machine-generated narration player (backend marks it `reader-narration`,
 * #903) in a collapsed `<details>` so it reads as one small, muted line instead
 * of a full editorial player. The label is translated by the caller and set as
 * text, never HTML. Idempotent: a player already inside a box is left alone.
 */
export function markNarrationPlayers(host: HTMLElement, label: string): void {
  for (const player of Array.from(host.querySelectorAll('audio.reader-narration'))) {
    if (player.closest('.reader-narration-box')) continue;

    const box = document.createElement('details');
    box.className = 'reader-narration-box';
    const summary = document.createElement('summary');
    summary.textContent = label;
    player.replaceWith(box);
    box.append(summary, player);
  }
}
