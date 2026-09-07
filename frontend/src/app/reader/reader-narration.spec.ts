import { markNarrationPlayers } from './reader-narration';

const LABEL = 'Listen — machine-generated narration';

function host(html: string): HTMLElement {
  const el = document.createElement('div');
  el.innerHTML = html;
  markNarrationPlayers(el, LABEL);
  return el;
}

describe('markNarrationPlayers', () => {
  it('wraps a marked player in a labelled details box', () => {
    const el = host('<audio class="reader-narration" src="https://x.test/full.mp3"></audio>');
    const box = el.querySelector('details.reader-narration-box');

    expect(box).not.toBeNull();
    expect(box!.querySelector('summary')!.textContent).toBe(LABEL);
    expect(box!.querySelector('audio.reader-narration')).not.toBeNull();
  });

  it('leaves an ordinary player alone', () => {
    const el = host('<audio src="https://x.test/podcast.mp3"></audio>');

    expect(el.querySelector('.reader-narration-box')).toBeNull();
    expect(el.querySelector('audio')).not.toBeNull();
  });

  it('is idempotent on a second pass', () => {
    const el = host('<audio class="reader-narration" src="https://x.test/full.mp3"></audio>');
    markNarrationPlayers(el, LABEL);

    expect(el.querySelectorAll('.reader-narration-box')).toHaveLength(1);
  });

  it('sets the summary as text, never as markup', () => {
    const el = document.createElement('div');
    el.innerHTML = '<audio class="reader-narration" src="https://x.test/full.mp3"></audio>';
    markNarrationPlayers(el, '<b>x</b>');
    const summary = el.querySelector('summary')!;

    expect(summary.querySelector('b')).toBeNull();
    expect(summary.textContent).toBe('<b>x</b>');
  });
});
