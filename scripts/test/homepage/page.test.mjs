import { test } from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const ROOT = new URL('../../../', import.meta.url);
const pathTo = (relative) => fileURLToPath(new URL(relative, ROOT));
const read = (relative) => readFileSync(pathTo(relative), 'utf8');

const page = read('docs/homepage/index.html');
const gallery = read('docs/screenshots.md');
const tour = read('frontend/scripts/screenshots/tour.mjs');
const screenshotFiles = readdirSync(pathTo('docs/screenshots/')).filter((name) =>
  name.endsWith('.webp'),
);

const attributeValues = (html, attribute) =>
  [...html.matchAll(new RegExp(`\\s${attribute}="([^"]*)"`, 'g'))].map((match) => match[1]);
const metaContent = (key) => {
  const tag = page.match(new RegExp(`<meta (?:name|property)="${key}" content="([^"]*)"`));
  assert.ok(tag, `the page has no ${key} meta tag`);
  return tag[1];
};
const structuredData = () =>
  JSON.parse(page.match(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/)[1]);
// The deploy generates changelog.html beside the page; the checkout has none.
const isLocalFile = (reference) =>
  !/^(https?:|#|mailto:)/.test(reference) && reference !== '' && reference !== 'changelog.html';

test('every local file the page names exists', () => {
  const references = ['src', 'href', 'poster']
    .flatMap((attribute) => attributeValues(page, attribute))
    .filter(isLocalFile);
  assert.ok(references.length > 20, 'the page names its screenshots, videos and scripts');
  assert.deepEqual(
    references.filter((reference) => !existsSync(pathTo(`docs/homepage/${reference}`))),
    [],
  );
});

test('the four descriptions agree', () => {
  const description = metaContent('description');
  assert.equal(metaContent('og:description'), description);
  assert.equal(metaContent('twitter:description'), description);
  assert.equal(structuredData().description, description);
});

test('every JSON-LD screenshot exists', () => {
  const names = structuredData().screenshot.map((url) => url.split('/screenshots/')[1]);
  assert.ok(names.length > 0);
  assert.deepEqual(
    names.filter((name) => !screenshotFiles.includes(name)),
    [],
  );
});

test('the gallery shows every screenshot and names none that is missing', () => {
  const shown = [...gallery.matchAll(/screenshots\/([a-z0-9-]+\.webp)/g)].map((match) => match[1]);
  assert.deepEqual([...new Set(shown)].sort(), [...screenshotFiles].sort());
});

test('the scene list matches the tour', () => {
  const recorded = [...tour.match(/const SCENES = \[([\s\S]*?)\];/)[1].matchAll(/\['(\w+)'/g)].map(
    (match) => match[1],
  );
  const buttons = [...page.matchAll(/<button type="button" data-scene="[^>]*>/g)].map(
    (match) => match[0],
  );
  assert.deepEqual(
    buttons.map((button) => button.match(/data-scene="(\w+)"/)[1]),
    recorded,
  );
  for (const button of buttons) {
    for (const theme of ['light', 'dark']) {
      const start = button.match(new RegExp(`data-${theme}="([^"]*)"`));
      const isTime = start && start[1] !== '' && Number.isFinite(Number(start[1]));
      assert.ok(isTime, `${button} has no data-${theme} time`);
    }
  }
});

const DESCRIPTION =
  'A self-hosted RSS/Atom reader for one or several users. Magazine, list and two-pane layouts, ' +
  'Trending stories, podcasts, YouTube, Bluesky, Mastodon and Lemmy, optional AI recommendations. ' +
  'Free and open source.';

test('the description names Trending and the three networks', () => {
  assert.equal(metaContent('description'), DESCRIPTION);
});

test('the sections follow the order of the page plan', () => {
  const sections = [...page.matchAll(/<section id="([a-z]+)"/g)].map((match) => match[1]);
  assert.deepEqual(sections, [
    'tour',
    'layouts',
    'trending',
    'features',
    'social',
    'mobile',
    'organise',
    'yours',
    'digest',
    'install',
  ]);
});

test('the header and the footer link the changelog', () => {
  const navigations = [...page.matchAll(/<nav>[\s\S]*?<\/nav>/g)].map((match) => match[0]);
  assert.equal(navigations.length, 2);
  for (const navigation of navigations) assert.ok(navigation.includes('href="changelog.html"'));
});

test('the feature list names what v1.8 to v1.10 added', () => {
  const features = structuredData().featureList;
  for (const feature of ['Trending', 'Lemmy', 'Notifications', 'Feed directory']) {
    assert.ok(
      features.some((entry) => entry.includes(feature)),
      `no feature names ${feature}`,
    );
  }
});
