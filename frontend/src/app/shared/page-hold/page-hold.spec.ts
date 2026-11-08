import { TestBed } from '@angular/core/testing';
import { PAGE_HELD_CLASS, PageHold } from './page-hold';

describe('PageHold', () => {
  const root = document.documentElement;
  let pageHold: PageHold;

  beforeEach(() => {
    pageHold = TestBed.inject(PageHold);
  });

  afterEach(() => root.classList.remove(PAGE_HELD_CLASS));

  it('holds the page until released', () => {
    const release = pageHold.hold();
    expect(root.classList).toContain(PAGE_HELD_CLASS);

    release();
    expect(root.classList).not.toContain(PAGE_HELD_CLASS);
  });

  it('holds while any holder is left, so a dialog closing over the drawer keeps it held', () => {
    const releaseDrawer = pageHold.hold();
    const releaseDialog = pageHold.hold();

    releaseDialog();
    expect(root.classList).toContain(PAGE_HELD_CLASS);

    releaseDrawer();
    expect(root.classList).not.toContain(PAGE_HELD_CLASS);
  });

  it('counts a release once, however often it is called', () => {
    const releaseDrawer = pageHold.hold();
    const releaseDialog = pageHold.hold();

    releaseDialog();
    releaseDialog();
    expect(root.classList).toContain(PAGE_HELD_CLASS);

    releaseDrawer();
  });
});
