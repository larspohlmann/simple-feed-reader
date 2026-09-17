import { expandFaqDisclosures } from './reader-faq';

function host(html: string): HTMLElement {
  const el = document.createElement('div');
  el.innerHTML = html;
  expandFaqDisclosures(el);
  return el;
}

describe('expandFaqDisclosures', () => {
  it('marks a publisher disclosure and opens it', () => {
    const el = host('<details><summary>Question?</summary><p>Answer.</p></details>');
    const faq = el.querySelector('details.reader-faq');

    expect(faq).not.toBeNull();
    expect((faq as HTMLDetailsElement).open).toBe(true);
  });

  it('marks and opens every disclosure, not only the first', () => {
    const el = host(
      '<details open><summary>One</summary><p>a</p></details>' +
        '<details><summary>Two</summary><p>b</p></details>',
    );
    const boxes = el.querySelectorAll<HTMLDetailsElement>('details.reader-faq');

    expect(boxes).toHaveLength(2);
    expect([...boxes].every((box) => box.open)).toBe(true);
  });

  it('leaves the machine-narration box collapsed and unstyled', () => {
    const el = host(
      '<details class="reader-narration-box"><summary>Listen</summary>' +
        '<audio src="https://x.test/full.mp3"></audio></details>',
    );
    const box = el.querySelector<HTMLDetailsElement>('details.reader-narration-box')!;

    expect(box.classList.contains('reader-faq')).toBe(false);
    expect(box.open).toBe(false);
  });

  it('is idempotent on a second pass', () => {
    const el = host('<details><summary>Question?</summary><p>Answer.</p></details>');
    expandFaqDisclosures(el);

    expect(el.querySelectorAll('details.reader-faq')).toHaveLength(1);
  });
});
