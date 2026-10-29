import { Injector } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { ArticleIslands } from '../app/reader/article/reader-view/article-islands';

export function injectIslands(): ArticleIslands {
  return new ArticleIslands(TestBed.inject(Injector));
}

/** jsdom has no scrollIntoView; stubs it on the prototype for the enclosing suite. */
export function stubScrollIntoView(): void {
  const original = Element.prototype.scrollIntoView;
  beforeAll(() => {
    Element.prototype.scrollIntoView = jest.fn();
  });
  afterAll(() => {
    Element.prototype.scrollIntoView = original;
  });
}
