import { signal } from '@angular/core';
import { folderToggle } from './folder-toggle';

describe('folderToggle', () => {
  const key = 'sfr.test.folder.collapsed';

  afterEach(() => localStorage.clear());

  it('starts collapsed and stores what the chevron opens', () => {
    const folder = folderToggle(
      () => key,
      () => null,
    );

    expect(folder.expanded()).toBe(false);
    folder.toggle();

    expect(folder.expanded()).toBe(true);
    expect(localStorage.getItem(key)).toBe('false');
  });

  it('opens on a current child without storing it', () => {
    const folder = folderToggle(
      () => key,
      () => true,
    );

    expect(folder.expanded()).toBe(true);
    expect(localStorage.getItem(key)).toBeNull();
  });

  it('closes a folder its child opened on the chevron, without storing it', () => {
    const folder = folderToggle(
      () => key,
      () => 'child',
    );

    folder.toggle();

    expect(folder.expanded()).toBe(false);
    expect(localStorage.getItem(key)).toBeNull();
  });

  it('closes and stores a stored-open folder on the chevron while a child is current', () => {
    localStorage.setItem(key, 'false');
    const folder = folderToggle(
      () => key,
      () => 'child',
    );

    folder.toggle();

    expect(folder.expanded()).toBe(false);
    expect(localStorage.getItem(key)).toBe('true');
  });

  it('reopens a folder closed on a child on the chevron, storing it open', () => {
    const folder = folderToggle(
      () => key,
      () => 'child',
    );
    folder.toggle();

    folder.toggle();

    expect(folder.expanded()).toBe(true);
    expect(localStorage.getItem(key)).toBe('false');
  });

  it('opens again on the next child after the chevron closed it on one', () => {
    const child = signal<number | null>(1);
    const folder = folderToggle(() => key, child);
    folder.toggle();

    child.set(2);

    expect(folder.expanded()).toBe(true);
  });

  it('keeps the chevron close while the child source keeps its value', () => {
    const selection = signal('liked');
    const folder = folderToggle(
      () => key,
      () => ['liked', 'shared'].includes(selection()),
    );
    folder.toggle();

    selection.set('shared');

    expect(folder.expanded()).toBe(false);
  });

  it('falls back to the stored state on leaving the child', () => {
    const child = signal<boolean>(true);
    const folder = folderToggle(() => key, child);
    expect(folder.expanded()).toBe(true);

    child.set(false);

    expect(folder.expanded()).toBe(false);
  });
});
