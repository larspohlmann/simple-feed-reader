export interface CinemaLabels {
  enter: string;
  exit: string;
}

const LANDSCAPE_PLAYER = '.reader-embed:not(.reader-embed--tall, .reader-embed--portrait), video';
const BOX = 'reader-cinema';
const WIDENED = 'reader-cinema--on';

/** Puts a Cinema toggle beneath every landscape player, which widens it past the reading measure (#1479). Idempotent. */
export function addCinemaToggles(host: HTMLElement, labels: CinemaLabels): void {
  for (const player of Array.from(host.querySelectorAll<HTMLElement>(LANDSCAPE_PLAYER))) {
    if (player.parentElement?.classList.contains(BOX)) continue;
    const box = document.createElement('div');
    box.className = BOX;
    player.replaceWith(box);
    box.append(player, toggleBar(box, labels));
  }
}

function toggleBar(box: HTMLElement, labels: CinemaLabels): HTMLElement {
  const icon = document.createElement('span');
  icon.className = 'material-symbols-outlined';
  icon.setAttribute('aria-hidden', 'true');
  const label = document.createElement('span');
  const key = document.createElement('kbd');
  key.setAttribute('aria-hidden', 'true');
  key.textContent = 't';

  const toggle = document.createElement('button');
  toggle.type = 'button';
  toggle.className = 'list-action reader-cinema__toggle';
  toggle.setAttribute('aria-keyshortcuts', 't');
  toggle.append(icon, label, key);

  const render = (): void => {
    const widened = box.classList.contains(WIDENED);
    icon.textContent = widened ? 'width_normal' : 'width_wide';
    label.textContent = widened ? labels.exit : labels.enter;
  };
  toggle.addEventListener('click', () => {
    box.classList.toggle(WIDENED);
    render();
    box.scrollIntoView({ block: 'nearest' });
  });
  render();

  const bar = document.createElement('div');
  bar.className = 'reader-cinema__bar';
  bar.append(toggle);
  return bar;
}
