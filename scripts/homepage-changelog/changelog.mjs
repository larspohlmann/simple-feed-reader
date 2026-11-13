import { escapeHtml, renderBlocks } from './markdown.mjs';

const RELEASES_URL = 'https://github.com/larspohlmann/simple-feed-reader/releases/tag/';
const PLACEHOLDER = '{{releases}}';
const RELEASE_HEADER = /^## \[(v[^\]]+)\] - (\d{4}-\d{2}-\d{2})[^\n]*\n?/gm;
const HIGHLIGHTS = /^### Highlights[^\n]*\n([\s\S]*?)(?=^#{1,6} |(?![\s\S]))/m;
const DISPLAY_DATE = new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' });

function splitHighlights(body) {
  const match = body.match(HIGHLIGHTS);
  if (match === null) return { highlights: '', changes: body };
  return { highlights: match[1], changes: body.slice(0, match.index) + body.slice(match.index + match[0].length) };
}

export function parseReleases(markdown) {
  const headers = [...markdown.matchAll(RELEASE_HEADER)];
  return headers.map((header) => {
    const rest = markdown.slice(header.index + header[0].length);
    const nextSection = rest.search(/^## \[/m);
    const body = nextSection === -1 ? rest : rest.slice(0, nextSection);
    return { version: header[1], date: header[2], ...splitHighlights(body) };
  });
}

function renderRelease({ version, date, highlights, changes }) {
  const parts = [
    `<section class="release" id="${escapeHtml(version)}">`,
    `<h2><a href="${RELEASES_URL}${escapeHtml(version)}">${escapeHtml(version)}</a>`
      + ` <time datetime="${date}">${DISPLAY_DATE.format(new Date(`${date}T00:00:00Z`))}</time></h2>`,
  ];
  if (highlights.trim() !== '') parts.push(`<div class="highlights">\n${renderBlocks(highlights)}\n</div>`);
  if (changes.trim() !== '') {
    parts.push(`<details><summary>All changes</summary>\n${renderBlocks(changes)}\n</details>`);
  }
  parts.push('</section>');
  return parts.join('\n');
}

export function renderReleasesHtml(releases) {
  return releases.map(renderRelease).join('\n');
}

export function renderChangelogPage(markdown, template) {
  if (!template.includes(PLACEHOLDER)) throw new Error(`The page template has no ${PLACEHOLDER} placeholder.`);
  const releases = parseReleases(markdown);
  if (releases.length === 0) throw new Error('The changelog has no release section.');
  return template.replace(PLACEHOLDER, () => renderReleasesHtml(releases));
}
