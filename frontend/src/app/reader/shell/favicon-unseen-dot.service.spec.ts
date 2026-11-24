import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { SubscriptionsStore } from '../state/subscriptions.store';
import { FaviconUnseenDot } from './favicon-unseen-dot.service';

const PLAIN_HREFS = ['favicon.ico?v=7', 'favicon-32.png?v=7', 'favicon.svg?v=7'];

describe('FaviconUnseenDot', () => {
  const total = signal(0);

  function iconHrefs(): (string | null)[] {
    return Array.from(document.head.querySelectorAll('link[rel="icon"]')).map((link) =>
      link.getAttribute('href'),
    );
  }

  beforeEach(() => {
    document.head.insertAdjacentHTML(
      'beforeend',
      '<link rel="icon" type="image/x-icon" href="favicon.ico?v=7" />' +
        '<link rel="icon" type="image/png" sizes="32x32" href="favicon-32.png?v=7" />' +
        '<link rel="icon" type="image/svg+xml" href="favicon.svg?v=7" />',
    );
    total.set(0);
    TestBed.configureTestingModule({
      providers: [
        FaviconUnseenDot,
        { provide: SubscriptionsStore, useValue: { unseenNotificationsTotal: total } },
      ],
    });
    TestBed.inject(FaviconUnseenDot);
  });

  afterEach(() => {
    document.head.querySelectorAll('link[rel="icon"]').forEach((link) => link.remove());
  });

  it('leaves the icons alone while nothing is unseen', () => {
    TestBed.tick();

    expect(iconHrefs()).toEqual(PLAIN_HREFS);
  });

  it('shows the badged icons while a notification is unseen', () => {
    total.set(2);
    TestBed.tick();

    expect(iconHrefs()).toEqual([
      'favicon-unseen-32.png?v=1',
      'favicon-unseen-32.png?v=1',
      'favicon-unseen.svg?v=1',
    ]);
  });

  it('restores the plain icons once everything is seen', () => {
    total.set(2);
    TestBed.tick();
    total.set(0);
    TestBed.tick();

    expect(iconHrefs()).toEqual(PLAIN_HREFS);
  });

  it('restores the plain icons when the reader goes away', () => {
    total.set(2);
    TestBed.tick();
    TestBed.resetTestingModule();

    expect(iconHrefs()).toEqual(PLAIN_HREFS);
  });
});
