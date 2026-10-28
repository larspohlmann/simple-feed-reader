import stylelint from 'stylelint';

const {
  createPlugin,
  utils: { report, ruleMessages },
} = stylelint;

const ruleName = 'local/no-animation-in-at-rule';

const messages = ruleMessages(ruleName, {
  rejected: (atRule) =>
    `Move this animation out of @${atRule}: in a minified build Angular leaves its keyframe name unscoped, so it never runs (#1862)`,
});

const ANIMATION = /^(?:-webkit-)?animation$/i;
const UNNAMED = /^(?:none|initial|inherit|unset|revert|revert-layer)$/i;
const SASS_ONLY = new Set(['mixin', 'include', 'function', 'if', 'else', 'each', 'for', 'while']);

function enclosingAtRule(node) {
  for (let parent = node.parent; parent; parent = parent.parent) {
    if (parent.type === 'atrule' && !SASS_ONLY.has(parent.name)) return parent;
  }
  return undefined;
}

const rule = (enabled) => (root, result) => {
  if (!enabled) return;
  root.walkDecls(ANIMATION, (declaration) => {
    if (UNNAMED.test(declaration.value.trim())) return;
    const atRule = enclosingAtRule(declaration);
    if (!atRule) return;
    report({ result, ruleName, node: declaration, message: messages.rejected(atRule.name) });
  });
};

rule.ruleName = ruleName;
rule.messages = messages;

export default createPlugin(ruleName, rule);
