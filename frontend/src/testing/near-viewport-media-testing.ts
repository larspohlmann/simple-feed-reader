import { NearViewportClient, NearViewportMedia } from '../app/reader/media/near-viewport-media';

export function fakeNearViewportMedia() {
  const watch = jest.fn();
  const unwatch = jest.fn();
  return {
    provider: { provide: NearViewportMedia, useValue: { watch, unwatch } },
    watch,
    unwatch,
    clientOf: (element: Element): Required<NearViewportClient> =>
      watch.mock.calls.find(([watched]) => watched === element)![1],
    reset: (): void => {
      watch.mockReset();
      unwatch.mockReset();
    },
  };
}
