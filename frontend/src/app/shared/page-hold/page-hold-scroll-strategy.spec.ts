import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Dialog } from '@angular/cdk/dialog';
import { PAGE_HELD_CLASS, PageHold } from './page-hold';
import { provideHeldPageDialogs } from './page-hold-scroll-strategy';

@Component({ template: 'held' })
class HeldDialogComponent {}

describe('provideHeldPageDialogs', () => {
  const root = document.documentElement;
  let dialog: Dialog;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHeldPageDialogs()] });
    dialog = TestBed.inject(Dialog);
  });

  afterEach(() => {
    dialog.closeAll();
    root.classList.remove(PAGE_HELD_CLASS);
  });

  it('holds the page while a dialog is open, never pinning <html> (#2100)', () => {
    const ref = dialog.open(HeldDialogComponent);
    expect(root.classList).toContain(PAGE_HELD_CLASS);
    expect(root.classList).not.toContain('cdk-global-scrollblock');

    ref.close();
    expect(root.classList).not.toContain(PAGE_HELD_CLASS);
  });

  it('keeps a held drawer held when a dialog over it closes', () => {
    const releaseDrawer = TestBed.inject(PageHold).hold();
    dialog.open(HeldDialogComponent).close();

    expect(root.classList).toContain(PAGE_HELD_CLASS);
    releaseDrawer();
  });

  it('holds until the last of two stacked dialogs closes', () => {
    const below = dialog.open(HeldDialogComponent);
    const above = dialog.open(HeldDialogComponent);

    above.close();
    expect(root.classList).toContain(PAGE_HELD_CLASS);

    below.close();
    expect(root.classList).not.toContain(PAGE_HELD_CLASS);
  });
});
