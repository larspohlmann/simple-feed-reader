import { TestBed } from '@angular/core/testing';
import { provideAccountIdentity } from '../../../testing/account-identity-testing';
import {
  LONG_TRACK_SECONDS,
  ListenPositionRecorder,
  POSITION_MARGIN_SECONDS,
} from './listen-position-recorder';
import { ListenPositions, SavedPosition } from './listen-positions';

const URL = 'https://x.test/ep.mp3';
const HOUR = 3600;

function setUp() {
  TestBed.configureTestingModule({ providers: [provideAccountIdentity()] });
  const positions = TestBed.inject(ListenPositions);
  let snapshot: SavedPosition | null = null;
  const recorder = new ListenPositionRecorder(positions, () => snapshot);
  const at = (seconds: number, durationSeconds = HOUR): void => {
    snapshot = { url: URL, seconds, durationSeconds };
  };
  return { positions, recorder, at, clear: () => (snapshot = null) };
}

describe('ListenPositionRecorder', () => {
  beforeEach(() => localStorage.clear());

  it('remembers where a long track was left', () => {
    const { positions, recorder, at } = setUp();
    at(600);

    recorder.record();

    expect(positions.get(URL)).toEqual({ url: URL, seconds: 600, durationSeconds: HOUR });
  });

  it('remembers nothing of a track of 15 minutes or less', () => {
    const { positions, recorder, at } = setUp();
    at(600, LONG_TRACK_SECONDS);

    recorder.record();

    expect(positions.get(URL)).toBeNull();
  });

  it('remembers nothing of a track whose length is unknown', () => {
    const { positions, recorder, at } = setUp();
    at(600, 0);

    recorder.record();

    expect(positions.get(URL)).toBeNull();
  });

  it('remembers nothing before the first 15 seconds, and keeps what was saved before', () => {
    const { positions, recorder, at } = setUp();
    at(600);
    recorder.record();
    at(POSITION_MARGIN_SECONDS - 1);

    recorder.record();

    expect(positions.get(URL)?.seconds).toBe(600);
  });

  it('remembers from the 15th second on', () => {
    const { positions, recorder, at } = setUp();
    at(POSITION_MARGIN_SECONDS);

    recorder.record();

    expect(positions.get(URL)?.seconds).toBe(POSITION_MARGIN_SECONDS);
  });

  it('forgets a track stopped within its last 15 seconds', () => {
    const { positions, recorder, at } = setUp();
    at(600);
    recorder.record();
    at(HOUR - POSITION_MARGIN_SECONDS + 1);

    recorder.record();

    expect(positions.get(URL)).toBeNull();
  });

  it('forgets a track that played to its end', () => {
    const { positions, recorder, at } = setUp();
    at(600);
    recorder.record();
    at(HOUR);

    recorder.record();

    expect(positions.get(URL)).toBeNull();
  });

  it('forgets a place outright, wherever the track stood', () => {
    const { positions, recorder, at } = setUp();
    at(600);
    recorder.record();

    recorder.forget();

    expect(positions.get(URL)).toBeNull();
  });

  it('forgets nothing without a track', () => {
    const { recorder, clear } = setUp();
    clear();

    recorder.forget();

    expect(localStorage.length).toBe(0);
  });

  it('does nothing without a track', () => {
    const { recorder, clear } = setUp();
    clear();

    recorder.record();

    expect(localStorage.length).toBe(0);
  });
});
