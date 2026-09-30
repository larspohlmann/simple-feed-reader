# No Variable Class or Method Name Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Forbid, in `src/`, any variable that acts as a class or a method name, and remove the one occurrence the tree holds today.

**Architecture:** `AccountLine::fromLine()` names `MagazineStyle` itself instead of handing an enum instance to a generic helper that calls `$default::tryFrom()`. A new PHPStan rule, `NoVariableClassOrMethodNameRule`, rejects every syntax form where a class, method, constant or property name is an expression, in the `App\` namespace outside `App\Tests\`. It is registered in `phpstan.dist.neon`, so `composer stan` (and so `composer check` and CI) enforce it.

**Tech Stack:** PHP 8.4, PHPStan (level max) with custom rules under `tests/PhpStan/`, nikic/php-parser 5 node classes, PHPUnit 12 with `PHPStan\Testing\RuleTestCase`.

**Spec:** GitHub issue #1294 (`gh issue view 1294`), agreed in the #1227 discussion on 2026-09-30.

## Global Constraints

- Scope is `src/` only, identified by namespace: `App\` and not `App\Tests\`. Tests keep their reflection-driven calls (`InstanceSettingsTest`, `AccountRestorerTest`).
- Not in scope, never reported: strings in attributes (`#[AsEventListener(method: …)]`), `services.yaml` factories, docblocks, `$object::class`, and calling a closure or callable held in a variable (`$pageQuery()`, a `FuncCall` — the rule does not look at `FuncCall` at all).
- `BackupReader` is not touched (decided in #1227).
- Behaviour of `AccountLine::fromLine()` stays identical: a missing or unknown `magazineStyle` imports as `MagazineStyle::Boxed`; a non-string value is refused with `InvalidBackupException('Field "magazineStyle" is missing or not a string.')`.
- CLAUDE.md house style applies: `final readonly class`, no abbreviations, no comment that restates code, comments three lines at most.
- Commit messages: `type(#1294): lower-case summary`, no attribution lines.
- Work from `backend/` for every command below.

---

### Task 0: Branch

The main checkout may be held by a concurrent session (it was on `feature/475-image-proxy` while this plan was written). Check first; never switch a branch under another session's uncommitted work.

- [ ] **Step 1: Check the checkout**

Run: `git -C .. status --short && git -C .. branch --show-current`

If the tree is dirty or on another session's branch, stop and ask Lars whether to wait or use a worktree. Otherwise continue.

- [ ] **Step 2: Create the branch off the latest develop**

```bash
git fetch origin develop
git switch -c feature/1294-no-variable-class-or-method-name origin/develop
```

- [ ] **Step 3: Commit this plan onto the branch**

```bash
git add ../docs/superpowers/plans/2026-09-30-1294-no-variable-class-or-method-name.md
git commit -m "docs(#1294): plan the variable class or method name ban"
```

---

### Task 1: AccountLine names its enum

**Files:**
- Modify: `src/Service/Backup/Support/LineFieldWithDefault.php` (replace `enum()` with `string()`)
- Modify: `src/Service/Backup/Dto/AccountLine.php:31`
- Test: `tests/Service/Backup/Dto/AccountLineTest.php`

**Interfaces:**
- Produces: `LineFieldWithDefault::string(array $line, string $key, string $default): string`. `LineFieldWithDefault::enum()` is deleted; its only caller was `AccountLine::fromLine()` (verify with `grep -rn "LineFieldWithDefault::enum" src tests` → no output after this task).

- [ ] **Step 1: Pin the one behaviour the tests don't cover yet**

The existing `AccountLineTest` covers a valid, a missing and an unknown style. A non-string value is refused today (by `LineField::string()` inside `enum()`) and must stay refused. Add to `tests/Service/Backup/Dto/AccountLineTest.php`, with `use App\Service\Backup\Exception\InvalidBackupException;` added to the imports:

```php
    public function testANonStringMagazineStyleIsRefused(): void
    {
        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessage('Field "magazineStyle" is missing or not a string.');

        AccountLine::fromLine(['locale' => 'de', 'scrapeFallbackEnabled' => true, 'magazineStyle' => 5]);
    }
```

- [ ] **Step 2: Run the test file — all green on the current code**

Run: `php bin/phpunit tests/Service/Backup/Dto/AccountLineTest.php`
Expected: `OK (5 tests, …)`. This is a characterisation pin, so it passes before the refactor.

- [ ] **Step 3: Replace `enum()` with `string()`**

In `src/Service/Backup/Support/LineFieldWithDefault.php`, replace the whole `enum()` method, its docblock included, with:

```php
    /**
     * @param array<string, mixed> $line
     */
    public static function string(array $line, string $key, string $default): string
    {
        if (!\array_key_exists($key, $line)) {
            return $default;
        }

        return LineField::string($line, $key);
    }
```

- [ ] **Step 4: Name the enum in `AccountLine::fromLine()`**

In `src/Service/Backup/Dto/AccountLine.php`, replace

```php
            magazineStyle: LineFieldWithDefault::enum($line, 'magazineStyle', MagazineStyle::Boxed),
```

with

```php
            // An unknown style imports as the default: a backup is worth more restored than rejected over one stale word.
            magazineStyle: MagazineStyle::tryFrom(
                LineFieldWithDefault::string($line, 'magazineStyle', MagazineStyle::Boxed->value),
            ) ?? MagazineStyle::Boxed,
```

The comment is the one `enum()`'s docblock carried; it moves with the decision it explains. Keep it within 120 columns (phpcs).

- [ ] **Step 5: Run the test file**

Run: `php bin/phpunit tests/Service/Backup/Dto/AccountLineTest.php`
Expected: `OK (5 tests, …)`.

- [ ] **Step 6: Run the backup suite and the gates for the touched files**

```bash
php bin/phpunit tests/Service/Backup
composer cs
composer stan
composer md
```

Expected: all green. `grep -rn "LineFieldWithDefault::enum" src tests` prints nothing.

- [ ] **Step 7: Commit**

```bash
git add src/Service/Backup/Support/LineFieldWithDefault.php src/Service/Backup/Dto/AccountLine.php tests/Service/Backup/Dto/AccountLineTest.php
git commit -m "refactor(#1294): account line names its magazine style enum"
```

---

### Task 2: NoVariableClassOrMethodNameRule

**Files:**
- Create: `tests/PhpStan/NoVariableClassOrMethodNameRule.php`
- Create: `tests/PhpStan/NoVariableClassOrMethodNameRuleTest.php`
- Create: `tests/PhpStan/data/no-variable-class-or-method-name-fixtures.php`
- Modify: `phpstan.dist.neon` (register the rule after `CommentBlockLengthRule`)
- Modify: `../CLAUDE.md` (the "Enforced mechanically" list)

**Interfaces:**
- Consumes: `ClassNameReferences::isInAnyOf(string $name, array $namespaces): bool` (existing, `tests/PhpStan/ClassNameReferences.php:78`; case-insensitive prefix match that appends `\` to the name).
- Consumes: Task 1 — without it, `composer stan` reports `LineFieldWithDefault.php:43` once the rule is registered.
- Produces: `NoVariableClassOrMethodNameRule::MESSAGE` (public string constant), identifier `simpleFeedReader.variableClassOrMethodName`.

What the rule reports (each is one php-parser node whose name or class is an `Expr`):

| Syntax | Node | Condition |
|---|---|---|
| `$$name`, `${'x'}` | `Expr\Variable` | `name` is `Expr` |
| `$object->$method()`, `->{…}()`, also `(...)` | `Expr\MethodCall`, `Expr\NullsafeMethodCall` | `name` is `Expr` |
| `$class::create()`, `Target::$method()` | `Expr\StaticCall` | `class` or `name` is `Expr` |
| `$class::$count`, `Target::$$name` | `Expr\StaticPropertyFetch` | `class` or `name` is `Expr` |
| `$class::NAME`, `Target::{$name}` | `Expr\ClassConstFetch` | `name` is `Expr`, or `class` is `Expr` and the name is not `class` |
| `new $class()`, `new ($expr)` | `Expr\New_` | `class` is `Expr` (an anonymous class is a `Stmt\Class_`, so it passes) |
| `$value instanceof $class` | `Expr\Instanceof_` | `class` is `Expr` |

- [ ] **Step 1: Write the fixture**

Create `tests/PhpStan/data/no-variable-class-or-method-name-fixtures.php` with exactly this content. The test asserts line numbers, so keep the lines as they are (the variable-name statements sit on lines 28–38):

```php
<?php

declare(strict_types=1);

// Fixtures for NoVariableClassOrMethodNameRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Fixtures\VariableClassOrMethodName {
    final class Target
    {
        public const string NAME = 'target';

        public static int $count = 0;

        public static function create(): self
        {
            return new self();
        }

        public function run(): void
        {
        }
    }

    /** @param class-string<Target> $class */
    function variableNames(Target $target, string $class, string $method, string $name): void
    {
        $$name = 1;
        $target->$method();
        $target?->{$method}();
        Target::$method();
        $class::create();
        new $class();
        $class::NAME;
        $class::$count;
        $target instanceof $class;
        Target::{$name};
        Target::$$name;
    }

    function literalNames(Target $target, \Closure $callback): void
    {
        $target->run();
        $target?->run();
        $target->run(...);
        Target::create();
        new Target();
        new class () {
        };
        Target::NAME;
        Target::$count;
        $target instanceof Target;
        $target::class;
        $callback();
    }
}

namespace App\Tests\Fixtures\VariableClassOrMethodName {
    function testsMayNameThroughAVariable(object $subject, string $method): void
    {
        $subject->$method();
    }
}
```

Check the numbering before moving on: `grep -n '\$\$name = 1;\|Target::\$\$name;' tests/PhpStan/data/no-variable-class-or-method-name-fixtures.php` must print lines `28` and `38`.

- [ ] **Step 2: Write the failing test**

Create `tests/PhpStan/NoVariableClassOrMethodNameRuleTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<NoVariableClassOrMethodNameRule> */
final class NoVariableClassOrMethodNameRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new NoVariableClassOrMethodNameRule();
    }

    public function testItReportsEveryVariableClassOrMethodNameInApplicationCodeOnly(): void
    {
        $this->analyse(
            [__DIR__ . '/data/no-variable-class-or-method-name-fixtures.php'],
            [
                [NoVariableClassOrMethodNameRule::MESSAGE, 28],
                [NoVariableClassOrMethodNameRule::MESSAGE, 29],
                [NoVariableClassOrMethodNameRule::MESSAGE, 30],
                [NoVariableClassOrMethodNameRule::MESSAGE, 31],
                [NoVariableClassOrMethodNameRule::MESSAGE, 32],
                [NoVariableClassOrMethodNameRule::MESSAGE, 33],
                [NoVariableClassOrMethodNameRule::MESSAGE, 34],
                [NoVariableClassOrMethodNameRule::MESSAGE, 35],
                [NoVariableClassOrMethodNameRule::MESSAGE, 36],
                [NoVariableClassOrMethodNameRule::MESSAGE, 37],
                [NoVariableClassOrMethodNameRule::MESSAGE, 38],
            ],
        );
    }
}
```

- [ ] **Step 3: Run it — it fails**

Run: `php bin/phpunit tests/PhpStan/NoVariableClassOrMethodNameRuleTest.php`
Expected: error, `Class "App\Tests\PhpStan\NoVariableClassOrMethodNameRule" not found`.

- [ ] **Step 4: Write the rule**

Create `tests/PhpStan/NoVariableClassOrMethodNameRule.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Reading `$object::class` names nothing and stays allowed; tests are exempt, their reflection-driven calls included.
 *
 * @implements Rule<Expr>
 */
final readonly class NoVariableClassOrMethodNameRule implements Rule
{
    public const string MESSAGE = 'A variable names a class or a method here, so neither Find usages nor PHPStan '
        . 'can follow it; call it by its name, through a match or an interface method (#1294).';

    public function getNodeType(): string
    {
        return Expr::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$this->isApplicationCode($scope) || !$this->namesThroughAVariable($node)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::MESSAGE)
                ->identifier('simpleFeedReader.variableClassOrMethodName')
                ->build(),
        ];
    }

    private function isApplicationCode(Scope $scope): bool
    {
        $namespaceName = $scope->getNamespace() ?? '';

        return ClassNameReferences::isInAnyOf($namespaceName, ['App\\'])
            && !ClassNameReferences::isInAnyOf($namespaceName, ['App\\Tests\\']);
    }

    private function namesThroughAVariable(Node $node): bool
    {
        return match (true) {
            $node instanceof Variable => $node->name instanceof Expr,
            $node instanceof MethodCall, $node instanceof NullsafeMethodCall => $node->name instanceof Expr,
            $node instanceof StaticCall, $node instanceof StaticPropertyFetch => $node->class instanceof Expr
                || $node->name instanceof Expr,
            $node instanceof ClassConstFetch => $this->fetchesThroughAVariable($node),
            $node instanceof New_, $node instanceof Instanceof_ => $node->class instanceof Expr,
            default => false,
        };
    }

    private function fetchesThroughAVariable(ClassConstFetch $fetch): bool
    {
        $readsTheClassName = $fetch->name instanceof Identifier && 'class' === $fetch->name->toLowerString();

        return $fetch->name instanceof Expr || ($fetch->class instanceof Expr && !$readsTheClassName);
    }
}
```

- [ ] **Step 5: Run the test — it passes**

Run: `php bin/phpunit tests/PhpStan/NoVariableClassOrMethodNameRuleTest.php`
Expected: `OK (1 test, …)`. If a line is missing or extra, the failure diff names it: fix the rule, never the expected list, unless the fixture line itself is wrong.

- [ ] **Step 6: Register the rule**

In `phpstan.dist.neon`, append after the `CommentBlockLengthRule` service entry (same indentation as its siblings):

```neon
    -
        class: App\Tests\PhpStan\NoVariableClassOrMethodNameRule
        tags:
            - phpstan.rules.rule
```

- [ ] **Step 7: Run the whole tree through it**

Run: `composer stan` (needs a warm dev cache: `bin/console cache:warmup` first if the container XML is stale)
Expected: `[OK] No errors`. The tests' `$fresh->$name()` (`InstanceSettingsTest.php:53`) and `$entity->$method()` (`AccountRestorerTest.php:575`) are in `App\Tests\` and must not be reported. If anything in `src/` is reported, stop and report it to the planner with the file and line — do not rewrite production code outside this plan.

- [ ] **Step 8: Prove the registered rule bites**

Temporarily add these two lines as the first statements of `AccountLine::fromLine()` in `src/Service/Backup/Dto/AccountLine.php`:

```php
        $enum = MagazineStyle::class;
        \assert(null !== $enum::tryFrom('boxed'));
```

Run: `composer stan`
Expected: an error on the `$enum::tryFrom` line quoting `A variable names a class or a method here` (identifier `simpleFeedReader.variableClassOrMethodName`). PHPStan may add its own findings on the probe lines; only the rule's error matters. Quote the output in the task report.

Remove both lines again with an edit (never `git checkout --` on a file that holds committed work), then run `git diff src/Service/Backup/Dto/AccountLine.php` — expected: no output.

- [ ] **Step 9: Document the rule in CLAUDE.md**

In `../CLAUDE.md`, in the "Enforced mechanically by `composer check` and `composer md`" list, add after the `EntityIdCoercionRule` bullet:

```markdown
- **`NoVariableClassOrMethodNameRule`** (`tests/PhpStan/NoVariableClassOrMethodNameRule.php`) — in `src`, no
  variable names a class or a method: `$class::create()`, `new $class`, `$object->$method()`, `$class::NAME`,
  `$value instanceof $class`, `$$name`. Call it by name, through a `match` or an interface method. Reading
  `$object::class` and calling a closure held in a variable are fine; tests are exempt (#1294).
```

- [ ] **Step 10: Gates**

```bash
composer cs
composer stan
php bin/phpunit tests/PhpStan
```

Expected: all green.

- [ ] **Step 11: Commit**

```bash
git add tests/PhpStan/NoVariableClassOrMethodNameRule.php tests/PhpStan/NoVariableClassOrMethodNameRuleTest.php tests/PhpStan/data/no-variable-class-or-method-name-fixtures.php phpstan.dist.neon ../CLAUDE.md
git commit -m "test(#1294): phpstan rule forbids variables naming a class or method in src"
```

---

### Task 3: Branch gates and PR

- [ ] **Step 1: Full gates**

```bash
composer check
composer md
composer test:parallel
composer infection:diff
```

Expected: all green. `infection:diff` mutates only the lines Task 1 touched in `LineFieldWithDefault` and `AccountLine`; the five `AccountLineTest` cases kill them (missing → default, valid → parsed, unknown → default, non-string → refused). An escaped mutant means a missing test, not a lowered `minMsi`.

- [ ] **Step 2: MySQL leg**

Run: `docker compose exec php composer test -- tests/Service/Backup` (only from the checkout that owns the running stack; skip with a note if a concurrent session owns it — this change has no SQL).

- [ ] **Step 3: Push and open the PR against develop**

```bash
git push -u origin feature/1294-no-variable-class-or-method-name
gh pr create --base develop --title "Forbid variables acting as class or method names in src" --body "Closes #1294

- AccountLine names MagazineStyle itself; LineFieldWithDefault::enum() and its \$default::tryFrom() are gone.
- NoVariableClassOrMethodNameRule rejects, in src, a variable acting as a class, method, constant or property name.
  \$object::class, closure calls and everything in tests stay allowed.
- BackupReader is unchanged (decided in #1227)."
```
