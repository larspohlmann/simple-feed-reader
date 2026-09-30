import { markNarrationPlayers } from './reader-narration';

const LABEL = 'Listen — machine-generated narration';

function host(html: string): HTMLElement {
  const element = document.createElement('div');
  element.innerHTML = html;
  markNarrationPlayers(element, LABEL);
  return element;
}

describe('markNarrationPlayers', () => {
  it('wraps a marked player in a labelled details box', () => {
    const element = host('<audio class="reader-narration" src="https://x.test/full.mp3"></audio>');
    const box = element.querySelector('details.reader-narration-box');

    expect(box).not.toBeNull();
    expect(box!.querySelector('summary')!.textContent).toBe(LABEL);
    expect(box!.querySelector('audio.reader-narration')).not.toBeNull();
  });

  it('leaves an ordinary player alone', () => {
    const element = host('<audio src="https://x.test/podcast.mp3"></audio>');

    expect(element.querySelector('.reader-narration-box')).toBeNull();
    expect(element.querySelector('audio')).not.toBeNull();
  });

  it('is idempotent on a second pass', () => {
    const element = host('<audio class="reader-narration" src="https://x.test/full.mp3"></audio>');
    markNarrationPlayers(element, LABEL);

    expect(element.querySelectorAll('.reader-narration-box')).toHaveLength(1);
  });

  it('sets the summary as text, never as markup', () => {
    const element = document.createElement('div');
    element.innerHTML = '<audio class="reader-narration" src="https://x.test/full.mp3"></audio>';
    markNarrationPlayers(element, '<b>x</b>');
    const summary = element.querySelector('summary')!;

    expect(summary.querySelector('b')).toBeNull();
    expect(summary.textContent).toBe('<b>x</b>');
  });
});
