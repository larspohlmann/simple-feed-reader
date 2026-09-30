import { expandFaqDisclosures } from './reader-faq';

function host(html: string): HTMLElement {
  const element = document.createElement('div');
  element.innerHTML = html;
  expandFaqDisclosures(element);
  return element;
}

describe('expandFaqDisclosures', () => {
  it('marks a publisher disclosure and opens it', () => {
    const element = host('<details><summary>Question?</summary><p>Answer.</p></details>');
    const faq = element.querySelector('details.reader-faq');

    expect(faq).not.toBeNull();
    expect((faq as HTMLDetailsElement).open).toBe(true);
  });

  it('marks and opens every disclosure, not only the first', () => {
    const element = host(
      '<details open><summary>One</summary><p>a</p></details>' +
        '<details><summary>Two</summary><p>b</p></details>',
    );
    const boxes = element.querySelectorAll<HTMLDetailsElement>('details.reader-faq');

    expect(boxes).toHaveLength(2);
    expect([...boxes].every((box) => box.open)).toBe(true);
  });

  it('leaves the machine-narration box collapsed and unstyled', () => {
    const element = host(
      '<details class="reader-narration-box"><summary>Listen</summary>' +
        '<audio src="https://x.test/full.mp3"></audio></details>',
    );
    const box = element.querySelector<HTMLDetailsElement>('details.reader-narration-box')!;

    expect(box.classList.contains('reader-faq')).toBe(false);
    expect(box.open).toBe(false);
  });

  it('is idempotent on a second pass', () => {
    const element = host('<details><summary>Question?</summary><p>Answer.</p></details>');
    expandFaqDisclosures(element);

    expect(element.querySelectorAll('details.reader-faq')).toHaveLength(1);
  });
});
