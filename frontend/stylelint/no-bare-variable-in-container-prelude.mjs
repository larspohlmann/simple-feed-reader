import stylelint from 'stylelint';

const {
  createPlugin,
  utils: { report, ruleMessages },
} = stylelint;

const ruleName = 'local/no-bare-variable-in-container-prelude';

const messages = ruleMessages(ruleName, {
  rejected: (variable) =>
    `Interpolate "${variable}" as "#{${variable}}": Sass leaves a bare variable in an @container prelude unresolved`,
});

const INTERPOLATION = /#\{[^}]*\}/g;
const VARIABLE = /(?:[\w-]+\.)?\$[\w-]+/g;

const rule = (enabled) => (root, result) => {
  if (!enabled) return;
  root.walkAtRules('container', (atRule) => {
    const bareParams = atRule.params.replace(INTERPOLATION, (match) => ' '.repeat(match.length));
    for (const [variable] of bareParams.matchAll(VARIABLE)) {
      report({
        result,
        ruleName,
        node: atRule,
        word: variable,
        message: messages.rejected(variable),
      });
    }
  });
};

rule.ruleName = ruleName;
rule.messages = messages;

export default createPlugin(ruleName, rule);
