import { removeEnclosurePlayers } from './enclosure-players';

const EPISODE = 'https://media.example.test/show/episode.mp3';

function host(html: string, enclosureUrl: string | null = EPISODE): HTMLElement {
  const element = document.createElement('div');
  element.innerHTML = html;
  removeEnclosurePlayers(element, enclosureUrl);
  return element;
}

describe('removeEnclosurePlayers', () => {
  it('removes a player whose source is the enclosure', () => {
    const element = host(`<audio controls><source src="${EPISODE}" type="audio/mpeg"></audio>`);
    expect(element.querySelector('audio')).toBeNull();
  });

  it('removes it when the page appends a cache-buster', () => {
    const element = host(`<audio><source src="${EPISODE}?_=1"></audio>`);
    expect(element.querySelector('audio')).toBeNull();
  });

  it('matches the src attribute, ignoring the fragment and the scheme case', () => {
    const element = host('<audio src="HTTPS://media.example.test/show/episode.mp3#t=10"></audio>');
    expect(element.querySelector('audio')).toBeNull();
  });

  it('keeps the player of a different file', () => {
    const element = host(
      '<audio><source src="https://media.example.test/show/trailer.mp3"></audio>',
    );
    expect(element.querySelector('audio')).not.toBeNull();
  });

  it('keeps every player when the entry has no enclosure', () => {
    const element = host(`<audio><source src="${EPISODE}"></audio>`, null);
    expect(element.querySelector('audio')).not.toBeNull();
  });

  it('keeps a player whose source is not a url', () => {
    const element = host('<audio><source src="episode.mp3"></audio>');
    expect(element.querySelector('audio')).not.toBeNull();
  });

  it('leaves the surrounding article in place', () => {
    const element = host(`<div><audio src="${EPISODE}"></audio><p>Show notes</p></div>`);
    expect(element.innerHTML).toBe('<div><p>Show notes</p></div>');
  });
});
