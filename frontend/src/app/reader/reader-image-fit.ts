const FILL_CLASS = 'reader-fill';

/** An image at least this fraction of the column counts as a content picture
 *  worth filling the column; anything smaller (an icon, a logo, an avatar)
 *  keeps its natural size so it is never blown up (#923). */
const FILL_FRACTION = 0.5;

/** Whether a content image should stretch to the column width. It qualifies
 *  when its own intrinsic width is at least half the column — a host-agnostic
 *  test that never inspects the URL, so any feed's small teaser photo fills the
 *  column while genuinely tiny images are left alone (keeps #771's spirit). */
export function shouldFillColumn(naturalWidth: number, columnWidth: number): boolean {
  return columnWidth > 0 && naturalWidth >= columnWidth * FILL_FRACTION;
}

/** Mark the substantial images in a rendered article to fill the column. An
 *  image whose intrinsic size is not known yet is decided once it loads. The
 *  natural-width reader is injectable so the rule can be tested without real
 *  image decoding. */
export function fitReaderImages(
  host: HTMLElement,
  naturalWidthOf: (image: HTMLImageElement) => number = (image) => image.naturalWidth,
): void {
  for (const image of Array.from(host.querySelectorAll('img'))) {
    fit(image, host, naturalWidthOf);
    if (naturalWidthOf(image) === 0) {
      image.addEventListener('load', () => fit(image, host, naturalWidthOf), { once: true });
    }
  }
}

function fit(
  image: HTMLImageElement,
  host: HTMLElement,
  naturalWidthOf: (image: HTMLImageElement) => number,
): void {
  const naturalWidth = naturalWidthOf(image);
  if (naturalWidth === 0) return;
  image.classList.toggle(FILL_CLASS, shouldFillColumn(naturalWidth, host.clientWidth));
}
