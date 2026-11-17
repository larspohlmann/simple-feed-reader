import { TestBed } from '@angular/core/testing';
import { startTruncatedTextTitles } from './truncated-text-titles';

describe('startTruncatedTextTitles', () => {
  let root: HTMLElement;

  const ellipsised = (text: string): HTMLElement => {
    const element = document.createElement('span');
    element.style.textOverflow = 'ellipsis';
    element.innerHTML = text;
    root.append(element);
    return element;
  };

  const layOut = (element: HTMLElement, scrollWidth: number, clientWidth: number) => {
    Object.defineProperty(element, 'scrollWidth', { configurable: true, value: scrollWidth });
    Object.defineProperty(element, 'clientWidth', { configurable: true, value: clientWidth });
  };

  const hover = (element: Element) =>
    element.dispatchEvent(new MouseEvent('mouseover', { bubbles: true }));

  beforeEach(() => {
    root = document.createElement('div');
    document.body.append(root);
    TestBed.runInInjectionContext(() => startTruncatedTextTitles());
  });

  afterEach(() => root.remove());

  it('shows the full text of a cut-off text', () => {
    const name = ellipsised(' A rather long feed name ');
    layOut(name, 200, 120);
    hover(name);
    expect(name.title).toBe('A rather long feed name');
  });

  it('finds the ellipsised element from a child that was hovered', () => {
    const name = ellipsised('A rather <mark>long</mark> feed name');
    layOut(name, 200, 120);
    hover(name.querySelector('mark')!);
    expect(name.title).toBe('A rather long feed name');
  });

  it('leaves aria-hidden copies and icons out of the tooltip', () => {
    const name = ellipsised(
      '<span aria-hidden="true">rss_feed</span>A rather long feed name<span aria-hidden="true">A rather long feed name</span>',
    );
    layOut(name, 200, 120);
    hover(name);
    expect(name.title).toBe('A rather long feed name');
  });

  it('shows no tooltip on a text that fits', () => {
    const name = ellipsised('Short');
    layOut(name, 120, 120);
    hover(name);
    expect(name.hasAttribute('title')).toBe(false);
  });

  it('drops its tooltip once the text fits again', () => {
    const name = ellipsised('A rather long feed name');
    layOut(name, 200, 120);
    hover(name);
    layOut(name, 200, 240);
    hover(name);
    expect(name.hasAttribute('title')).toBe(false);
  });

  it('keeps a title the template set', () => {
    const value = ellipsised('3 hours ago');
    value.title = '17 Nov 2026, 09:12';
    layOut(value, 120, 120);
    hover(value);
    expect(value.title).toBe('17 Nov 2026, 09:12');
  });

  it('leaves text without an ellipsis alone', () => {
    const plain = document.createElement('span');
    plain.textContent = 'A rather long feed name';
    root.append(plain);
    layOut(plain, 200, 120);
    hover(plain);
    expect(plain.hasAttribute('title')).toBe(false);
  });

  it('stops listening once the app is destroyed', () => {
    TestBed.resetTestingModule();
    const name = ellipsised('A rather long feed name');
    layOut(name, 200, 120);
    hover(name);
    expect(name.hasAttribute('title')).toBe(false);
  });
});
