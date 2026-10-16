import { signal } from '@angular/core';
import { persistedToggle } from './persisted-toggle';

describe('persistedToggle', () => {
  afterEach(() => {
    localStorage.clear();
    jest.restoreAllMocks();
  });

  it('starts expanded and remembers a collapse under its key', () => {
    const toggle = persistedToggle(() => 'sfr.test.collapsed');

    expect(toggle.expanded()).toBe(true);
    toggle.toggle();

    expect(toggle.expanded()).toBe(false);
    expect(localStorage.getItem('sfr.test.collapsed')).toBe('true');
    toggle.toggle();
    expect(localStorage.getItem('sfr.test.collapsed')).toBe('false');
  });

  it('starts collapsed when its key says so', () => {
    localStorage.setItem('sfr.test.collapsed', 'true');

    expect(persistedToggle(() => 'sfr.test.collapsed').expanded()).toBe(false);
  });

  it('starts collapsed when asked to, until toggled open', () => {
    const toggle = persistedToggle(() => 'sfr.test.start', 'collapsed');

    expect(toggle.expanded()).toBe(false);
    toggle.toggle();
    expect(toggle.expanded()).toBe(true);
    expect(localStorage.getItem('sfr.test.start')).toBe('false');
    expect(persistedToggle(() => 'sfr.test.start', 'collapsed').expanded()).toBe(true);
  });

  it('follows a key that changes', () => {
    localStorage.setItem('sfr.mastodon.collapsed', 'true');
    const network = signal('bluesky');
    const toggle = persistedToggle(() => `sfr.${network()}.collapsed`);

    expect(toggle.expanded()).toBe(true);
    network.set('mastodon');

    expect(toggle.expanded()).toBe(false);
  });

  it('still toggles when browser storage is blocked', () => {
    jest.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('blocked');
    });
    jest.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('blocked');
    });
    const toggle = persistedToggle(() => 'sfr.test.collapsed');

    expect(toggle.expanded()).toBe(true);
    toggle.toggle();
    expect(toggle.expanded()).toBe(false);
  });
});
