import { MediaSessionControls } from './media-session-controls';

describe('MediaSessionControls', () => {
  const setPositionState = jest.fn();

  beforeEach(() => {
    setPositionState.mockClear();
    Object.defineProperty(navigator, 'mediaSession', {
      configurable: true,
      value: { setPositionState, setActionHandler: jest.fn() },
    });
  });

  afterEach(() => {
    Reflect.deleteProperty(navigator, 'mediaSession');
  });

  it('tells the OS the playback speed, so its scrubber keeps pace', () => {
    new MediaSessionControls().showPosition(30, 120, 1.5);

    expect(setPositionState).toHaveBeenCalledWith({
      duration: 120,
      position: 30,
      playbackRate: 1.5,
    });
  });
});
