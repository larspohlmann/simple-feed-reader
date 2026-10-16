import { SPARE_VIDEO_ELEMENTS, UnlockedVideoElements } from './unlocked-video-elements';

const settle = () => new Promise((resolve) => setTimeout(resolve));

function recordCreatedVideos() {
  const created: HTMLVideoElement[] = [];
  const volumeChanges = jest.fn();
  const create = document.createElement.bind(document);
  jest.spyOn(document, 'createElement').mockImplementation(((tagName: string) => {
    const element = create(tagName) as HTMLVideoElement;
    element.addEventListener('volumechange', volumeChanges);
    created.push(element);
    return element;
  }) as typeof document.createElement);
  return { created, volumeChanges };
}

afterEach(() => jest.restoreAllMocks());

describe('UnlockedVideoElements', () => {
  it('hands out a fresh video element while nothing is unlocked', () => {
    const { created } = recordCreatedVideos();
    const elements = new UnlockedVideoElements();

    const video = elements.take();

    expect(video).toBeInstanceOf(HTMLVideoElement);
    expect(created).toEqual([video]);
  });

  it('unlocks a stock of spares by toggling their sound, and hands those out first', async () => {
    const { created, volumeChanges } = recordCreatedVideos();
    const elements = new UnlockedVideoElements();

    elements.refill();
    await settle();

    expect(created).toHaveLength(SPARE_VIDEO_ELEMENTS);
    expect(volumeChanges).toHaveBeenCalledTimes(2 * SPARE_VIDEO_ELEMENTS);
    const handedOut = Array.from({ length: SPARE_VIDEO_ELEMENTS }, () => elements.take());
    expect(new Set(handedOut)).toEqual(new Set(created));
    expect(handedOut.every((video) => !video.muted)).toBe(true);

    elements.take();
    expect(created).toHaveLength(SPARE_VIDEO_ELEMENTS + 1);
  });

  it('only tops the stock up on a later refill', () => {
    const { created } = recordCreatedVideos();
    const elements = new UnlockedVideoElements();
    elements.refill();
    elements.take();
    elements.take();

    elements.refill();

    expect(created).toHaveLength(SPARE_VIDEO_ELEMENTS + 2);
  });
});
