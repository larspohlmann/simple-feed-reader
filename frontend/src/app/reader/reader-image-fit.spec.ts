import { fitReaderImages, shouldFillColumn } from './reader-image-fit';

describe('shouldFillColumn', () => {
  it('fills an image at least half the column wide', () => {
    expect(shouldFillColumn(300, 600)).toBe(true);
    expect(shouldFillColumn(384, 669)).toBe(true);
  });

  it('leaves a small image (icon, logo, avatar) at its natural width', () => {
    expect(shouldFillColumn(120, 600)).toBe(false);
    expect(shouldFillColumn(32, 669)).toBe(false);
  });

  it('fills an image wider than the column', () => {
    expect(shouldFillColumn(2000, 600)).toBe(true);
  });

  it('never fills while the column is unmeasured', () => {
    expect(shouldFillColumn(384, 0)).toBe(false);
  });
});

describe('fitReaderImages', () => {
  function host(width: number): HTMLElement {
    const el = document.createElement('div');
    Object.defineProperty(el, 'clientWidth', { value: width, configurable: true });
    return el;
  }

  it('marks a substantial image to fill the column and leaves a small one alone', () => {
    const el = host(600);
    const big = document.createElement('img');
    const small = document.createElement('img');
    el.append(big, small);
    const widths = new Map<HTMLImageElement, number>([
      [big, 400],
      [small, 100],
    ]);

    fitReaderImages(el, (img) => widths.get(img) ?? 0);

    expect(big.classList.contains('reader-fill')).toBe(true);
    expect(small.classList.contains('reader-fill')).toBe(false);
  });

  it('defers the decision until an unloaded image reports its natural size', () => {
    const el = host(600);
    const img = document.createElement('img');
    el.append(img);
    let natural = 0;

    fitReaderImages(el, () => natural);
    expect(img.classList.contains('reader-fill')).toBe(false);

    natural = 400;
    img.dispatchEvent(new Event('load'));

    expect(img.classList.contains('reader-fill')).toBe(true);
  });
});
