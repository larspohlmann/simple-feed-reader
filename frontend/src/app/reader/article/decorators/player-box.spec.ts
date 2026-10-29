import { playerBoxOf } from './player-box';

function frameIn(html: string): HTMLIFrameElement {
  const host = document.createElement('div');
  host.innerHTML = html;
  return host.querySelector('iframe')!;
}

describe('playerBoxOf', () => {
  it('is the cinema box around a widened player', () => {
    const frame = frameIn(
      '<div class="reader-cinema"><div class="reader-embed"><iframe></iframe></div></div>',
    );
    expect(playerBoxOf(frame).className).toBe('reader-cinema');
  });

  it('is the embed box of a player without a cinema box', () => {
    const frame = frameIn('<div class="reader-embed reader-embed--strip"><iframe></iframe></div>');
    expect(playerBoxOf(frame).classList).toContain('reader-embed');
  });

  it('is the frame itself without either box', () => {
    const frame = frameIn('<iframe></iframe>');
    expect(playerBoxOf(frame)).toBe(frame);
  });
});
