export class FakeMediaMetadata {
  constructor(readonly init: MediaMetadataInit) {}
}

export type MediaActionHandler = ((details: { seekTime?: number }) => void) | null;

export interface FakeMediaSession {
  metadata: FakeMediaMetadata | null;
  playbackState: string;
  setPositionState: jest.Mock;
}

/** A Media Session on `navigator` and a `MediaMetadata` on `window`, which jsdom lacks. */
export function installFakeMediaSession(): {
  session: FakeMediaSession;
  handlers: Record<string, MediaActionHandler>;
  restore(): void;
} {
  const handlers: Record<string, MediaActionHandler> = {};
  const session: FakeMediaSession = {
    metadata: null,
    playbackState: 'none',
    setPositionState: jest.fn(),
  };
  Object.defineProperty(navigator, 'mediaSession', {
    configurable: true,
    value: Object.assign(session, {
      setActionHandler: (action: string, handler: MediaActionHandler) => {
        handlers[action] = handler;
      },
    }),
  });
  Object.defineProperty(window, 'MediaMetadata', { configurable: true, value: FakeMediaMetadata });
  return {
    session,
    handlers,
    restore: () => {
      Reflect.deleteProperty(navigator, 'mediaSession');
      Reflect.deleteProperty(window, 'MediaMetadata');
    },
  };
}
