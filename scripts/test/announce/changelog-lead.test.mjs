import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { extractHighlights, graphemeLength, LEAD_LIMIT } from '../../announce/thread.mjs';

const changelog = readFileSync(new URL('../../../CHANGELOG.md', import.meta.url), 'utf8');

function unreleasedSection(markdown) {
  const start = markdown.indexOf('## [Unreleased]');
  const end = markdown.indexOf('\n## [', start + 1);
  return markdown.slice(start, end === -1 ? undefined : end);
}

test('the lead under [Unreleased] fits LEAD_LIMIT', () => {
  const { lead } = extractHighlights(unreleasedSection(changelog));
  if (lead === null) {
    return;
  }
  assert.ok(
    graphemeLength(lead) <= LEAD_LIMIT,
    `the Highlights lead is ${graphemeLength(lead)} characters; the root post has room for ${LEAD_LIMIT}`,
  );
});
