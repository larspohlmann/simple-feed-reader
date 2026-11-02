import { ElementRef } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { ListScrollMemory } from '../../scroll/list-scroll-memory';
import { ArticleScrollRestore } from './article-scroll-restore.service';

describe('ArticleScrollRestore', () => {
  let host: HTMLElement;
  let saveArticle: jest.Mock;
  let restore: ArticleScrollRestore;

  beforeEach(() => {
    host = document.createElement('div');
    saveArticle = jest.fn();
    TestBed.configureTestingModule({
      providers: [
        ArticleScrollRestore,
        { provide: ElementRef, useValue: new ElementRef(host) },
        { provide: ListScrollMemory, useValue: { readArticle: () => 400, saveArticle } },
      ],
    });
    restore = TestBed.inject(ArticleScrollRestore);
    restore.arm('entry:7');
  });

  it('keeps the remembered position while a restore is pending', () => {
    restore.remember('entry:7', 0);
    expect(saveArticle).not.toHaveBeenCalled();
  });

  it.each(['wheel', 'touchstart'])('hands scrolling back to the user on %s', (type) => {
    host.dispatchEvent(new Event(type));
    restore.remember('entry:7', 120);
    expect(saveArticle).toHaveBeenCalledWith('entry:7', 120);
  });
});
