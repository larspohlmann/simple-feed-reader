import { ElementRef } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { StubArticlePageSource } from '../../../../testing/article-page-source-testing';
import { ListScrollMemory } from '../../scroll/list-scroll-memory';
import { ARTICLE_PAGE_SOURCE } from '../article-page/article-page-source';
import { ArticleScrollRestore } from './article-scroll-restore.service';

describe('ArticleScrollRestore', () => {
  let host: HTMLElement;
  let saveArticle: jest.Mock;
  let restore: ArticleScrollRestore;

  beforeEach(() => {
    host = document.createElement('div');
    saveArticle = jest.fn();
    const page = new StubArticlePageSource();
    page.key.set('entry:7');
    TestBed.configureTestingModule({
      providers: [
        ArticleScrollRestore,
        { provide: ElementRef, useValue: new ElementRef(host) },
        { provide: ListScrollMemory, useValue: { readArticle: () => 400, saveArticle } },
        { provide: ARTICLE_PAGE_SOURCE, useValue: page },
      ],
    });
    restore = TestBed.inject(ArticleScrollRestore);
    TestBed.tick();
  });

  it('keeps the remembered position while a restore is pending', () => {
    restore.remember(0);
    expect(saveArticle).not.toHaveBeenCalled();
  });

  it.each(['wheel', 'touchstart'])('hands scrolling back to the user on %s', (type) => {
    host.dispatchEvent(new Event(type));
    restore.remember(120);
    expect(saveArticle).toHaveBeenCalledWith('entry:7', 120);
  });
});
