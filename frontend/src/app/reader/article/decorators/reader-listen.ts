import { embedMediaOfFrame, type EmbedMedia } from '../../audio/embed-media';
import { PLAYER_BAR, playerBar } from './reader-cinema';

export interface ListenLabels {
  listen: string;
  listenEmbed: string;
  addToPlaylist: string;
  inPlaylist: string;
}

export interface ListenActions {
  listen(media: EmbedMedia, frame: HTMLIFrameElement): void;
  toggleQueued(media: EmbedMedia): void;
}

/** One embed's queue toggle, for whoever keeps its state current. */
export interface EmbedListenControl {
  readonly media: EmbedMedia;
  showQueued(queued: boolean): void;
}

const EMBED_FRAME = '.reader-embed > iframe';
const MARKER = 'reader-listen';
const BUTTON = 'reader-listen__button';
const QUEUED = 'reader-listen__button--queued';

/** Listen and queue controls at the start of the player bar of every supported embed in `host` (#1815). Idempotent. */
export function addEmbedListenControls(
  host: HTMLElement,
  labels: ListenLabels,
  actions: ListenActions,
): EmbedListenControl[] {
  const controls: EmbedListenControl[] = [];
  for (const frame of Array.from(host.querySelectorAll<HTMLIFrameElement>(EMBED_FRAME))) {
    const media = embedMediaOfFrame(frame);
    if (media === null) continue;
    const bar = playerBarAfter(frame.parentElement as HTMLElement);
    if (bar.classList.contains(MARKER)) continue;
    bar.classList.add(MARKER);
    const queueToggle = new QueueToggle(media, labels, () => actions.toggleQueued(media));
    bar.prepend(
      listenButton(labels, () => actions.listen(media, frame)),
      queueToggle.button,
    );
    controls.push(queueToggle);
  }
  return controls;
}

/** The bar the cinema decorator left under a wrapped player, else a new one after the embed box. */
function playerBarAfter(box: HTMLElement): HTMLElement {
  const next = box.nextElementSibling;
  if (next instanceof HTMLElement && next.classList.contains(PLAYER_BAR)) return next;
  const bar = playerBar();
  box.after(bar);
  return bar;
}

function listenButton(labels: ListenLabels, onClick: () => void): HTMLButtonElement {
  const button = controlButton(iconSpan('play_circle'), labelSpan(labels.listen));
  button.title = labels.listenEmbed;
  button.addEventListener('click', onClick);
  return button;
}

class QueueToggle implements EmbedListenControl {
  readonly button: HTMLButtonElement;
  private readonly icon = iconSpan('playlist_add');
  private readonly label: HTMLSpanElement;

  constructor(
    readonly media: EmbedMedia,
    private readonly labels: ListenLabels,
    onToggle: () => void,
  ) {
    this.label = labelSpan(labels.addToPlaylist);
    this.button = controlButton(this.icon, this.label);
    this.button.setAttribute('aria-pressed', 'false');
    this.button.addEventListener('click', onToggle);
  }

  showQueued(queued: boolean): void {
    this.icon.textContent = queued ? 'playlist_add_check' : 'playlist_add';
    this.label.textContent = queued ? this.labels.inPlaylist : this.labels.addToPlaylist;
    this.button.setAttribute('aria-pressed', String(queued));
    this.button.classList.toggle(QUEUED, queued);
  }
}

function controlButton(icon: HTMLSpanElement, label: HTMLSpanElement): HTMLButtonElement {
  const button = document.createElement('button');
  button.type = 'button';
  button.className = BUTTON;
  button.append(icon, label);
  return button;
}

function iconSpan(name: string): HTMLSpanElement {
  const icon = document.createElement('span');
  icon.className = 'material-symbols-outlined';
  icon.setAttribute('aria-hidden', 'true');
  icon.textContent = name;
  return icon;
}

function labelSpan(text: string): HTMLSpanElement {
  const label = document.createElement('span');
  label.textContent = text;
  return label;
}
