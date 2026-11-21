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
  for (const theme of ['light', 'dark']) {
    const starts = buttons.map((button) => {
      const start = button.match(new RegExp(`data-${theme}="([^"]*)"`));
      const isTime = start && start[1] !== '' && Number.isFinite(Number(start[1]));
      assert.ok(isTime, `${button} has no data-${theme} time`);
      return Number(start[1]);
    });
    const inOrder = starts.every((start, index) => index === 0 || start > starts[index - 1]);
    assert.ok(inOrder, `the ${theme} scene times do not rise: ${starts.join(', ')}`);
  }
});

test('every screenshot the README shows exists', () => {
  const shown = [...read('README.md').matchAll(/docs\/screenshots\/([a-z0-9-]+\.webp)/g)].map(
    (match) => match[1],
  );
  assert.ok(shown.length > 5, 'the README shows its screenshots');
  assert.deepEqual(
    shown.filter((name) => !screenshotFiles.includes(name)),
    [],
  );
});

// cwebp writes a desktop shot as plain lossy WebP (VP8, the size in its frame header) and a
// phone shot, whose frame is transparent, as extended WebP (VP8X, the canvas size minus one).
function webpSize(name) {
  const file = readFileSync(pathTo(`docs/screenshots/${name}`));
  const format = file.toString('latin1', 12, 16);
  if (format === 'VP8X')
    return {
      width: file.readUIntLE(24, 3) + 1,
      height: file.readUIntLE(27, 3) + 1,
    };
  assert.equal(format, 'VP8 ', `${name} is neither a lossy nor an extended WebP`);
  return {
    width: file.readUInt16LE(26) & 0x3fff,
    height: file.readUInt16LE(28) & 0x3fff,
  };
}

test('every screenshot on the page declares the size of its file', () => {
  const declared = [
    ...page.matchAll(/<img [^>]*src="screenshots\/([^"]+)" width="(\d+)" height="(\d+)"/g),
  ].map(([, name, width, height]) => ({
    name,
    width: Number(width),
    height: Number(height),
  }));
  assert.ok(declared.length > 10, "the page declares its screenshots' sizes");
  for (const { name, width, height } of declared) {
    assert.deepEqual({ width, height }, webpSize(name), name);
  }
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
