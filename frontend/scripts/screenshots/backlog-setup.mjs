// The demo account is opened only for screenshots, so its unread counts grow into the tens of
// thousands between retakes. This marks everything older than two days read, which leaves the
// counts of an account in use. There is no teardown: read state is not given back.
//
//   node --env-file=.env.screenshots backlog-setup.mjs
import { chromium } from '@playwright/test';
import { openApiSession } from './lib.mjs';

const KEPT_UNREAD_DAYS = 2;

const browser = await chromium.launch();
const api = await openApiSession(browser);
const until = new Date(Date.now() - KEPT_UNREAD_DAYS * 24 * 60 * 60 * 1000).toISOString();
const marked = await api('POST', '/api/entries/mark-read', { scope: 'all', until });
const { json: counts } = await api('GET', '/api/subscriptions/counts');
console.log(`mark-read ${marked.status}; All items now ${counts.unreadTotals?.allItems} unread`);
await browser.close();
