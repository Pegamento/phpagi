# phpagi v3 implementation progress

Director board for steps 1–8 of `docs/v3-plan.md`. Removed before the beta (step 9, human).

Local `v3` is `3958e18`, the same commit as `origin/v3-plan`. There is no `origin/v3`. The working tree was clean apart from the untracked director prompt, which stays untracked.

Local PHP is 8.4.27. PHP 8.5 is not installed here; 8.5 verification is the CI job once `ci.yml` exists.

## Work-package board

Status values: `ready` | `in progress` | `in review` | `accepted` | `blocked`.

| WP | Step | Title | Depends on | Branch | Status |
|---|---|---|---|---|---|
| WP-01 | 1 | Skeleton: Composer, PHPStan, code style, PHPUnit, remove 2.x sources | — | `v3-wp-01-skeleton` | in progress |
| WP-02 | 1 | `ci.yml`, Dependabot, required-check names | WP-01 | `v3-wp-02-ci` | ready |
| WP-03 | 2 | Contracts: `Transport`, `AgiThrowable`, exception skeletons, empty namespaces | step 1 | | ready |
| WP-04 | 2 | `StreamTransport` | WP-03 | | ready |
| WP-05 | 2 | `ResponseParser` | WP-03 | | ready |
| WP-06 | 2 | `CommandBuilder` | WP-03 | | ready |
| WP-07 | 2 | Enums | WP-03 | | ready |
| WP-08 | 2 | Result objects | WP-03 | | ready |
| WP-09 | 2 | `FakeTransport` | WP-03 | | ready |
| WP-10 | 3 | `AgiRequest`, factory, `Agi` constructor | step 2 | | ready |
| WP-11 | 4 | API skeleton on `Agi`, `AgiInterface`, `AbstractAgiDecorator` | WP-10 | | ready |
| WP-12 | 4 | Channel and dialplan flow | WP-11 | | ready |
| WP-13 | 4 | Variables, caller ID, database | WP-11 | | ready |
| WP-14 | 4 | Audio and DTMF | WP-11 | | ready |
| WP-15 | 4 | Text, TDD, logging, `command()`, `noop`, `verbose` | WP-11 | | ready |
| WP-16 | 5 | `HangupDetector` and per-method hangup rules | WP-12–15 | | ready |
| WP-17 | 6 | Docs | WP-16 | | ready |
| WP-18 | 6 | Examples | WP-16 | | ready |
| WP-19 | 7 | Rector rule set and fixtures | WP-16 | | ready |
| WP-20 | 8 | Asterisk test image | WP-16 | | ready |
| WP-21 | 8 | Integration workflow and scenarios | WP-18, WP-20 | | ready |
| WP-22 | 8 | Mutation, coverage thresholds, `release.yml` | WP-16 | | ready |

Later steps stay at this granularity until their step starts. Parallel groups are WP-04–09 (after WP-03), WP-12–15 (after WP-11), and WP-17–20 plus WP-22 (after WP-16). WP-21 waits for WP-18 and WP-20.

### Step 1 breakdown

**WP-01 — skeleton.** Replace the 2.x Composer classmap with `Pegamento\PhpAgi\` PSR-4, add `psr/log` and the dev tools, configure PHPStan (max, `phpVersion` 80400–80599), PSR-12 check mode, and PHPUnit 12 with deprecation, notice, warning, and PHPUnit-deprecation failures. Bootstrap sets `error_reporting(E_ALL)`. Delete `src/phpagi.php`, `src/phpagi-asmanager.php`, `src/phpagi-fastagi.php`, and the 2.x tests. Port nothing. One bootstrap test keeps the suite non-empty. No library classes, no CI workflow edits, no docs rewrites.

**WP-02 — CI.** `.github/workflows/ci.yml` and `.github/dependabot.yml` exactly as the Continuous integration section: 8.4.latest and 8.5.latest, warn-only php.net version guard, weekly schedule that opens an issue on failure, jobs `tests`, `tests-lowest`, `static-analysis`, `code-style`, `composer`, and allowed-to-fail `next-php`. Coverage may exist without thresholds. Concurrency, actions pinned to a commit SHA, `permissions: contents: read`. The old `tests.yml` is removed in this package because `ci.yml` replaces it.

## Decisions log

| # | Decision | Plan reference | Why this reading |
|---|---|---|---|
| D1 | Code style is PHP-CS-Fixer (`friendsofphp/php-cs-fixer`) at PSR-12, checked with `fix --dry-run --diff`. | Continuous integration: "PHP-CS-Fixer or PHP_CodeSniffer (PSR-12) in check mode" | The plan allows either. PHP-CS-Fixer is the first named tool and has a dry-run check mode. No rules beyond PSR-12. |
| D2 | Test namespace is `Pegamento\PhpAgi\Tests\`, PSR-4, `tests/`. | Namespace / autoload: `Pegamento\PhpAgi\`, PSR-4, `src/` | The plan names the library namespace and not the test namespace. The 2.x `PhpAgi\Tests\` namespace goes away with the 2.x tests. |
| D3 | `ergebnis/composer-normalize` is a WP-01 dev dependency. | `ci.yml` job **composer**: `composer normalize --dry-run` | The plan requires that command in CI. `composer.json` belongs to WP-01, so the tool lands there and WP-02 only adds the workflow. |
| D4 | WP-01 adds `tests/BootstrapTest.php`, asserting the bootstrap left `error_reporting` at `E_ALL`. It is not a port of a 2.x test. | Step 1: remove the 2.x tests and port nothing; tests pass at every commit | PHPUnit 12 fails an empty suite. The assertion locks the required bootstrap behavior. |
| D5 | The `config.platform.php` 8.4 pin is removed. No `composer.lock`. | PHP 8.5 compatibility: remove the pin, or keep it only for lock-file generation; the library does not commit a lock file | There is no lock file to generate, so the pin has nothing to pin. |
| D6 | Work-package branches are `v3-wp-<nn>-<slug>`, not `v3/wp-<nn>-<slug>`. Push only `v3` and `v3-wp-*`. | Director operating model: branch `v3/wp-<nn>-<slug>` | Git stores branch `v3` as `refs/heads/v3`, so it cannot also create `refs/heads/v3/wp-…`. The hyphen keeps the same prefix and the same push limit. |

## Open questions

None.

## Human-only actions

Not this board's work. Listed so they are not lost.

**Step 0** (still outstanding on the repository; `master` is already at the PHPUnit PR merge, `4a02f1d`):

- Release and tag 2.30.0 from `master`.
- Claim `pegamento/phpagi` on Packagist (and check `pegamento/pami`).
- Create the `2.x` branch from the 2.30.0 tag.

**Step 9:** tag or release `3.0.0-beta.1` or `3.0.0`, publish, announce.

**Branch protection** (after WP-02, human): require every `ci.yml` job except `next-php` and any optional Codecov upload. Exact GitHub check names are filled in here when WP-02 lands. Planned job names from the plan:

- `tests` (PHP 8.4 and 8.5)
- `tests-lowest`
- `static-analysis` (PHP 8.4 and 8.5)
- `code-style`
- `composer`
- `coverage` (thresholds arrive in WP-22)
- `rector-fixtures` (job arrives in WP-19)
- `integration.yml` / `asterisk` (WP-21)
- `mutation.yml` / `infection` (WP-22)

`next-php` stays allowed to fail and is not required.

## 2.x test port table

Each case is ported by the work package that owns the behavior, or dropped because v3 removes that behavior. "Pending" means the case is still owed. Reference for the deleted tests is `git show 3958e18:<path>`.

| Case | Disposition | Owner | Status |
|---|---|---|---|
| `AgiEvaluateTest::testWritesCommandTrimmedWithNewline` | Port: a command is written as one trimmed line ending in a newline | WP-04 / command path | pending |
| `AgiEvaluateTest::testParsesSimpleSuccess` | Port | WP-05 | pending |
| `AgiEvaluateTest::testParsesParenthesisedData` | Port | WP-05 | pending |
| `AgiEvaluateTest::testParsesParenthesisedDataContainingSpaces` | Port | WP-05 | pending |
| `AgiEvaluateTest::testParsesExtraKeyValueTokens` | Port | WP-05 | pending |
| `AgiEvaluateTest::testCombinesKeyValueAndParenthesisedData` | Port | WP-05 | pending |
| `AgiEvaluateTest::testErrorCodeKeepsRawTextAsData` | Port | WP-05 | pending |
| `AgiEvaluateTest::testParsesMultiLineErrorResponse` | Port | WP-05 | pending |
| `AgiEvaluateTest::testSkipsStrayBlankLinesBeforeReply` | Port | WP-05 | pending |
| `AgiEvaluateTest::testReturnsBrokenWhenAsteriskStopsAnswering` | Port as `ConnectionException` on EOF | WP-04 | pending |
| `AgiEvaluateTest::testReturnsBrokenWhenOutputCannotBeWritten` | Port as `ConnectionException` on write failure | WP-04 | pending |
| `AgiEvaluateTest::testResultIsReturnedAsString` | Drop. v3 parses `result` as `?int`, never via float | Command failures; `AgiResponse` | dropped |
| `AgiCommandsTest` (every case) | Port onto the v3 method that replaces that command, including the fixed `set_global_var` behavior | WP-12–15 | pending |
| `AgiConstructorTest::testReadsRequestHeaderUntilBlankLine` | Port | WP-10 | pending |
| `AgiConstructorTest::testFillsConfigDefaults` | Drop. No `phpagi.conf` and no config defaults | Decisions: config | dropped |
| `AgiConstructorTest::testLoadsIniConfigFileAndOptconfigOverridesIt` | Drop. No ini config | Decisions: config | dropped |
| `AgiHelpersTest::testParseCallerId` | Drop. `parse_callerid` removed | Public API: removed | dropped |
| `AgiHelpersTest::testParseCallerIdDefaultsToRequestCallerId` | Drop. Same | Public API: removed | dropped |
| `AgiHelpersTest` fastpass cases (4) | Drop. fastpass removed | Non-goals | dropped |
| `AgiHelpersTest` menu cases (3) | Drop. `menu` removed | Public API: removed | dropped |
| `AgiHelpersTest::testMakeFolderCreatesNestedDirectories` | Drop. `make_folder` removed | Public API: removed | dropped |
| `AgiHelpersTest::testWhichFindsExecutableInGivenPath` | Drop. `which` removed | Public API: removed | dropped |
| `AgiHelpersTest::testConlogSendsVerboseOnlyInDebugMode` | Drop. `conlog` replaced by the injected PSR-3 logger; `verbose()` is not that logger | Logging | dropped |
| `AgiHelpersTest::testNewAsteriskManagerSharesConfigAndLogsThroughAgi` | Drop. AMI removed | AMI removed | dropped |
| `AsteriskManagerTest` (all cases) | Drop. AMI removed | AMI removed | dropped |
| `KnownBugsTest::testConstructorStopsWhenStdinClosesBeforeEndOfHeader` | Port as a normal passing test: header parsing stops on EOF | WP-10 | pending |
| `KnownBugsTest::testGetVarReturnsValue` | Drop. AMI removed | AMI removed | dropped |
| `KnownBugsTest::testCommandReturnsOutputWhenEndMarkerIsOnItsOwnLine` | Drop. AMI removed | AMI removed | dropped |
| `KnownBugsTest::testChangeMonitorSendsCorrectActionName` | Drop. AMI removed | AMI removed | dropped |
| `KnownBugsTest::testArrayCallbackEventHandlerIsCalled` | Drop. AMI removed | AMI removed | dropped |
| `KnownBugsTest::testSayPunctuationSpeaksSpaceOnce` | Drop. `say_punctuation` removed, no replacement | Public API: removed | dropped |
