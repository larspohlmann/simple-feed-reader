import { ElementRef } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { ListScrollMemory } from '../../scroll/list-scroll-memory';
import { ArticleScrollRestore } from './article-scroll-restore.service';

describe('ArticleScrollRestore', () => {
  let host: HTMLElement;
  let saveEntry: jest.Mock;
  let restore: ArticleScrollRestore;

  beforeEach(() => {
    host = document.createElement('div');
    saveEntry = jest.fn();
    TestBed.configureTestingModule({
      providers: [
        ArticleScrollRestore,
        { provide: ElementRef, useValue: new ElementRef(host) },
        { provide: ListScrollMemory, useValue: { readEntry: () => 400, saveEntry } },
      ],
    });
    restore = TestBed.inject(ArticleScrollRestore);
    restore.arm(7);
  });

  it('keeps the remembered position while a restore is pending', () => {
    restore.remember(7, 0);
    expect(saveEntry).not.toHaveBeenCalled();
  });

  it.each(['wheel', 'touchstart'])('hands scrolling back to the user on %s', (type) => {
    host.dispatchEvent(new Event(type));
    restore.remember(7, 120);
    expect(saveEntry).toHaveBeenCalledWith(7, 120);
  });
});
