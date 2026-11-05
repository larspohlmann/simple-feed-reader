import { PAGE_PINNED_CLASS, PAGE_PIN_TOP, PageLayer } from './page-layer';

describe('PageLayer', () => {
  const element = document.createElement('section');

  afterEach(() => {
    jest.restoreAllMocks();
    element.className = '';
    element.removeAttribute('style');
  });

  /** The layer's box starts `documentTop` down the document while the page sits at `pageTop`. */
  function showsAt(documentTop: number, pageTop: number): void {
    jest
      .spyOn(element, 'getBoundingClientRect')
      .mockReturnValue({ top: documentTop - pageTop } as DOMRect);
  }

  it('starts in the flow', () => {
    const layer = new PageLayer(element);
    expect(layer.pinned).toBe(false);
    expect(layer.pinnedTop).toBe(0);
    expect(element.classList).not.toContain(PAGE_PINNED_CLASS);
  });

  it('pins where it shows, shifted by how far the page had scrolled into it', () => {
    showsAt(100, 1200);
    const layer = new PageLayer(element);

    layer.pinAt(1200);

    expect(layer.pinned).toBe(true);
    expect(layer.pinnedTop).toBe(1200);
    expect(element.classList).toContain(PAGE_PINNED_CLASS);
    expect(element.style.getPropertyValue(PAGE_PIN_TOP)).toBe('1100px');
  });

  it('moves its pin on a write, at once', () => {
    showsAt(100, 1200);
    const layer = new PageLayer(element);
    layer.pinAt(1200);

    layer.moveTo(900);

    expect(layer.pinnedTop).toBe(900);
    expect(element.style.getPropertyValue(PAGE_PIN_TOP)).toBe('800px');
  });

  it('ignores a write while in the flow', () => {
    const layer = new PageLayer(element);
    layer.moveTo(900);
    expect(layer.pinned).toBe(false);
    expect(element.style.getPropertyValue(PAGE_PIN_TOP)).toBe('');
  });

  it('returns its offset as it goes back into the flow', () => {
    showsAt(0, 1200);
    const layer = new PageLayer(element);
    layer.pinAt(1200);

    expect(layer.unpin()).toBe(1200);

    expect(layer.pinned).toBe(false);
    expect(element.classList).not.toContain(PAGE_PINNED_CLASS);
    expect(element.style.getPropertyValue(PAGE_PIN_TOP)).toBe('');
  });
});
