import { test } from 'node:test';
import assert from 'node:assert/strict';
import { renderBlocks, renderInline } from '../../homepage-changelog/markdown.mjs';

test('escapes HTML in titles and paragraphs', () => {
  assert.equal(renderInline('use <details> & "x"'), 'use &lt;details&gt; &amp; &quot;x&quot;');
  assert.equal(renderBlocks('a <script> tag'), '<p>a &lt;script&gt; tag</p>');
});

test('renders code, bold and links', () => {
  assert.equal(
    renderInline('**A.** See [docs](https://e.org/a) and `npm ci`.'),
    '<strong>A.</strong> See <a href="https://e.org/a">docs</a> and <code>npm ci</code>.',
  );
});

test('leaves underscores and lone asterisks alone', () => {
  assert.equal(renderInline('split user_preferences, a*b and _c_'), 'split user_preferences, a*b and _c_');
});

test('keeps markup inside code literal', () => {
  assert.equal(renderInline('`**not bold** <b>`'), '<code>**not bold** &lt;b&gt;</code>');
});

test('autolinks a bare URL once', () => {
  assert.equal(renderInline('see https://e.org/x.'), 'see <a href="https://e.org/x">https://e.org/x</a>.');
  assert.equal(renderInline('[t](https://e.org)'), '<a href="https://e.org">t</a>');
});

test('renders a PR bullet as title and number', () => {
  assert.equal(
    renderBlocks('* fix(#1): a <b> by @larspohlmann in https://github.com/o/r/pull/12'),
    '<ul><li>fix(#1): a &lt;b&gt; <a href="https://github.com/o/r/pull/12">#12</a></li></ul>',
  );
});

test('joins continuation lines into one list item', () => {
  const html = renderBlocks('- first line\n  continues here\n- second');
  assert.equal(html, '<ul><li>first line continues here</li><li>second</li></ul>');
});

test('wraps paragraph lines into one paragraph', () => {
  assert.equal(renderBlocks('one\ntwo'), '<p>one two</p>');
  assert.equal(renderBlocks('one\n\ntwo'), '<p>one</p>\n<p>two</p>');
});

test('renders headings as h4', () => {
  assert.equal(renderBlocks('### Fixed'), '<h4>Fixed</h4>');
  assert.equal(renderBlocks("## What's Changed"), '<h4>What&#39;s Changed</h4>');
});

test('a heading directly above a list starts its own block', () => {
  assert.equal(renderBlocks("## What's Changed\n* a"), '<h4>What&#39;s Changed</h4>\n<ul><li>a</li></ul>');
});
