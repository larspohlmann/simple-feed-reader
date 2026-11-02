import { signal } from '@angular/core';
import { HandedAutoplayRegion } from './autoplay-region';

describe('HandedAutoplayRegion', () => {
  it('confines autoplay to nothing until the view hands its region over', () => {
    expect(new HandedAutoplayRegion().region()).toBeUndefined();
  });

  it('follows the element the view handed over', () => {
    const holder = new HandedAutoplayRegion();
    const element = signal<Element | undefined>(undefined);
    holder.handOver(element);

    const comments = document.createElement('section');
    element.set(comments);

    expect(holder.region()).toBe(comments);
  });
});
