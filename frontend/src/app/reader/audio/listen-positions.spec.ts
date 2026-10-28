import { WritableSignal, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideAccountIdentity } from '../../../testing/account-identity-testing';
import { KEPT_POSITIONS, ListenPositions, SavedPosition } from './listen-positions';

const KEY = 'sfr.user.7.listen-positions';

function at(url: string, seconds = 600): SavedPosition {
  return { url, seconds, durationSeconds: 3600 };
}

describe('ListenPositions', () => {
  let userId: WritableSignal<number | null>;
  let positions: ListenPositions;

  beforeEach(() => {
    localStorage.clear();
    userId = signal<number | null>(7);
    TestBed.configureTestingModule({ providers: [provideAccountIdentity(userId)] });
    positions = TestBed.inject(ListenPositions);
  });

  it('remembers a position under the signed-in user', () => {
    positions.remember(at('https://x.test/a.mp3'));

    expect(positions.get('https://x.test/a.mp3')).toEqual(at('https://x.test/a.mp3'));
    expect(JSON.parse(localStorage.getItem(KEY) ?? '[]')).toEqual([at('https://x.test/a.mp3')]);
  });

  it('has nothing for a track never remembered', () => {
    expect(positions.get('https://x.test/a.mp3')).toBeNull();
  });

  it('replaces the earlier position of the same track', () => {
    positions.remember(at('https://x.test/a.mp3', 600));
    positions.remember(at('https://x.test/a.mp3', 900));

    expect(positions.get('https://x.test/a.mp3')?.seconds).toBe(900);
    expect(JSON.parse(localStorage.getItem(KEY) ?? '[]')).toHaveLength(1);
  });

  it('forgets a track', () => {
    positions.remember(at('https://x.test/a.mp3'));
    positions.remember(at('https://x.test/b.mp3'));

    positions.forget('https://x.test/a.mp3');

    expect(positions.get('https://x.test/a.mp3')).toBeNull();
    expect(positions.get('https://x.test/b.mp3')).not.toBeNull();
  });

  it('keeps only the most recently remembered positions', () => {
    for (let index = 0; index <= KEPT_POSITIONS; index++) {
      positions.remember(at(`https://x.test/${index}.mp3`));
    }

    expect(positions.get('https://x.test/0.mp3')).toBeNull();
    expect(positions.get(`https://x.test/${KEPT_POSITIONS}.mp3`)).not.toBeNull();
    expect(JSON.parse(localStorage.getItem(KEY) ?? '[]')).toHaveLength(KEPT_POSITIONS);
  });

  it('a re-remembered track counts as recent again', () => {
    for (let index = 0; index < KEPT_POSITIONS; index++) {
      positions.remember(at(`https://x.test/${index}.mp3`));
    }
    positions.remember(at('https://x.test/0.mp3'));
    positions.remember(at('https://x.test/new.mp3'));

    expect(positions.get('https://x.test/0.mp3')).not.toBeNull();
    expect(positions.get('https://x.test/1.mp3')).toBeNull();
  });

  it("shows one user nothing of another's", () => {
    positions.remember(at('https://x.test/a.mp3'));
    userId.set(8);

    expect(positions.get('https://x.test/a.mp3')).toBeNull();
  });

  it('reads a corrupt or foreign value as empty', () => {
    localStorage.setItem(KEY, '{not json');
    expect(positions.get('https://x.test/a.mp3')).toBeNull();

    localStorage.setItem(KEY, JSON.stringify([{ url: 'https://x.test/a.mp3' }, 'junk']));
    expect(positions.get('https://x.test/a.mp3')).toBeNull();
  });
});
