import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { extractHighlights, graphemeLength, LEAD_LIMIT } from '../../announce/thread.mjs';

const unreleasedHighlights = execFileSync(fileURLToPath(new URL('../../changelog-highlights.sh', import.meta.url)), {
  encoding: 'utf8',
});

test('the lead under [Unreleased] fits LEAD_LIMIT', () => {
  const { lead } = extractHighlights(unreleasedHighlights);
  assert.ok(
    lead === null || graphemeLength(lead) <= LEAD_LIMIT,
    `the Highlights lead is ${graphemeLength(lead ?? '')} characters; the root post has room for ${LEAD_LIMIT}`,
  );
});
