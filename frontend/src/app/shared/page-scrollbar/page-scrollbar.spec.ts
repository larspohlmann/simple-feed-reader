import { TestBed } from '@angular/core/testing';
import { PAGE_SCROLLBAR_HIDDEN_CLASS, PageScrollbar } from './page-scrollbar';

describe('PageScrollbar', () => {
  const root = document.documentElement;
  let pageScrollbar: PageScrollbar;

  beforeEach(() => {
    pageScrollbar = TestBed.inject(PageScrollbar);
  });

  afterEach(() => root.classList.remove(PAGE_SCROLLBAR_HIDDEN_CLASS));

  it("stays hidden while any rail stands in, so a story's list closing leaves the list's hidden (#2453)", () => {
    const releaseList = pageScrollbar.hide();
    const releaseStory = pageScrollbar.hide();

    releaseStory();
    expect(root.classList).toContain(PAGE_SCROLLBAR_HIDDEN_CLASS);

    releaseList();
    expect(root.classList).not.toContain(PAGE_SCROLLBAR_HIDDEN_CLASS);
  });

  it('counts a release once, however often it is called', () => {
    pageScrollbar.hide();
    const releaseStory = pageScrollbar.hide();

    releaseStory();
    releaseStory();

    expect(root.classList).toContain(PAGE_SCROLLBAR_HIDDEN_CLASS);
  });
});
