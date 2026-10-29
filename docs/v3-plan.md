# phpagi v3 — plan

Status: all design decisions taken; ready for review. Nothing here is implemented yet.

## Goals

- A small, fully typed, **general-purpose** AGI client that any PHP developer working with Asterisk can install from Packagist and use without knowing anything about the applications it was first built for.
- **PHP 8.4 and 8.5**, 100% compatible with both: no errors, warnings or deprecations on either. **Asterisk ≥ 20.** No PHP 4/5 leftovers.
- One coherent breaking release. 2.x users get a complete migration guide, and v3 is designed so it doesn't need another breaking redesign soon after.
- Every behavior is covered by tests that need neither a running Asterisk nor a child process. Consumers get the same testing tools for their own code.

## Non-goals

- Backward compatibility with 2.x. The 2.x line moves to a `2.x` maintenance branch for hotfixes only.
- Asterisk < 20: no `|` option delimiter, no DeadAGI, no Zaptel, no legacy response quirks.
- AMI, FastAGI/xinetd, TTS (festival/cepstral), "fastpass" helpers, `mkdocs.php`.
- Being a framework. Menus, IVR builders, routing and call-flow logic belong in the application using the library, or in separate packages built on top of it.

## Decisions taken

| Topic | Decision |
|---|---|
| PHP | minimum **8.4** (`php: ^8.4`); **8.5 is the recommended version**, used for primary development, the default local and CI setup, and the docs examples. Both are fully supported (see [PHP 8.5 compatibility](#php-85-compatibility)). `declare(strict_types=1)` in every file |
| Asterisk | `>=20` (20 and 22 LTS). We document the AGI command set we target and test against it |
| Package | `pegamento/phpagi`, published on **Packagist** (today it's only installable as a VCS repository). LGPL-2.1-or-later, unchanged |
| Namespace / autoload | `Pegamento\PhpAgi\`, PSR-4, `src/` |
| Method names | camelCase (`getVariable`, `execGoto`, `streamFile`) |
| Return values | `readonly` value objects or plain scalars. No arrays, no flag-dependent return types |
| Constants | enums (`ChannelState`, `HangupCause`, `ResultCode`) |
| Config | explicit constructor arguments. No `/etc/asterisk/phpagi.conf`, no temp dir handling, no global state |
| Side effects | none on construction: no `set_error_handler`, no signal handlers, no error emails, no syslog, no `error_reporting()` changes. Optional PSR-3 logger (`psr/log`), defaulting to `NullLogger` |
| AMI | **removed.** v3 is AGI-only. AMI is a different protocol (a long-lived TCP connection with asynchronous events) and is better served by a dedicated client. The README and migration guide recommend [Pegamento/PAMI](https://github.com/Pegamento/PAMI) (`pegamento/pami`), the maintained fork of PAMI |
| Hangup | `ChannelHungUpException` on a command refused on a dead channel (511), plus `AGISIGHUP=no` in the dialplan (see [Hangup handling](#hangup-handling-decided-channelhungupexception)) |
| Extensibility | a public `command(string $name, string\|int ...$args): AgiResponse` for AGI commands without a dedicated method (new or rarely used ones), going through the same escaping and parsing |
| Versioning | SemVer. Raising the minimum PHP or Asterisk version only happens in a new major. A `CHANGELOG.md` in Keep a Changelog format |
| Migration guide | `docs/UPGRADING-3.0.md` ships with 3.0.0 (see [Migration guide](#migration-guide-docsupgrading-30md)) |
| Quality | PHPStan max level, PSR-12 code style, PHPUnit 12, CI on PHP 8.4 and 8.5, where any deprecation, notice or warning fails the build |

## Support policy

Published in the README:

- **PHP:** every PHP version from the floor (8.4) up to the newest release is supported and tested in CI. A new PHP release is added to the CI matrix as soon as it's out (RC builds in an allowed-to-fail job before that).
- **Asterisk:** the current LTS releases from 20 up. Command syntax follows the Asterisk 20 AGI documentation; differences in later versions are documented per method.
- **Library versions:** the latest 3.x minor gets fixes. 2.x gets only critical fixes, for a limited period announced with the 3.0.0 release.

## PHP 8.5 compatibility

PHP 8.5 is the **recommended** version and 8.4 the **floor**. In practice:

- Develop and run tooling (PHPStan, code style) on 8.5 by default. CI's primary job is 8.5, and 8.4 runs as a required compatibility job.
- README, the migration guide and `composer.json` say: "requires PHP 8.4+, recommended 8.5".
- Every bug report and release is verified on 8.5 first, then on 8.4.

v3 must run unchanged and silently on both PHP 8.4 and 8.5. "Works on 8.5" isn't enough; it must also trigger **zero deprecations** there. Concretely:

- **Syntax and API floor is 8.4.** Don't use 8.5-only features (the pipe operator `|>`, `clone` with properties, `array_first()`/`array_last()`, and so on), or 8.4 breaks. The one exception is attributes that 8.4 simply ignores: `#[\NoDiscard]` on pure getters like `getVariable()` and `databaseGet()` makes 8.5 warn when a result is thrown away, and costs nothing on 8.4.
- **Avoid everything 8.5 deprecates.** Per the PHP 8.5 deprecations RFC (confirm against the 8.5 `UPGRADING` notes when implementing):
  - the backtick operator (use `shell_exec()`, though v3 needs neither);
  - non-canonical casts like `(integer)`, `(boolean)`, `(double)`, `(binary)`;
  - a `;` after a `case` label;
  - `null` as an array offset or as the key in `array_key_exists()`;
  - `chr()`/`ord()` with out-of-range values (relevant to `AgiResponse::digit()`: guard `result` to 0–255 before calling `chr()`);
  - casting NaN, ±INF or out-of-range floats to `int` (relevant to response parsing: parse integers from strings with validation, never via floats);
  - `ReflectionProperty::setAccessible()`/`ReflectionMethod::setAccessible()` in tests;
  - `__sleep()`/`__wakeup()` (use `__serialize()`/`__unserialize()` if serialization is ever needed).
- **Tooling enforces it:**
  - PHPUnit runs with `failOnDeprecation`, `failOnNotice`, `failOnWarning` and `failOnPhpunitDeprecation` enabled, and the test bootstrap sets `error_reporting(E_ALL)`.
  - PHPStan's `phpVersion` is set to the range `80400`–`80599`, so it flags both 8.5-only APIs and anything removed or deprecated in that range.
  - CI runs the full suite on 8.4 and 8.5. A green run on both is the release gate.
- **Dependencies:** every runtime and dev dependency (`psr/log`, PHPUnit 12, PHPStan) must declare 8.5 support. CI installs on 8.5 without `--ignore-platform-reqs`. Remove the `config.platform.php = 8.4` pin from `composer.json`, or keep it only for lock-file generation, and run CI with `composer update` per PHP version, so 8.5 resolves its own dependency versions. As a library, phpagi doesn't commit a `composer.lock`.

## Hangup handling (decided: `ChannelHungUpException`)

Context: on Asterisk 20, when the caller hangs up, Asterisk by default sends **SIGHUP** to the AGI process. PHP has no SIGHUP handler by default, so the script is **killed on the spot**, mid-logic and without cleanup or logging. With `AGISIGHUP=no` set on the channel, Asterisk instead writes a `HANGUP` line on stdin and answers every further command (except a few, like `GET VARIABLE`) with `511 Command Not Permitted on a dead channel`. Many existing AGI applications run with the default without realizing it.

**Decision:** when a command is refused because the channel is gone, v3 throws `ChannelHungUpException`. The docs require `AGISIGHUP=no`, so the script gets the chance to see it.

How it works:

- **The `HANGUP` line only records state.** With `AGISIGHUP=no`, Asterisk writes a `HANGUP` line on stdin when the caller hangs up. The parser consumes it, sets `$agi->isHungUp() === true`, and continues reading the actual response to the pending command. It doesn't throw yet.
- **A 511 response throws.** "Command not permitted on a dead channel" throws `ChannelHungUpException`, carrying the command that was refused and the request's channel name and unique ID.
- **Dead-channel-safe commands keep working.** Commands Asterisk still allows on a dead channel (such as `GET VARIABLE`, `SET VARIABLE` and `VERBOSE`; exact list to verify against Asterisk 20) return normally. That keeps `h`-extension and hangup-handler scripts usable, and lets cleanup code read channel variables after a hangup.
- **`hangup()` itself never throws.** Hanging up our own channel is normal; only the *next* refused command throws.
- **It isn't an error type.** `ChannelHungUpException` extends `\RuntimeException` and implements a shared marker interface `AgiThrowable`, but deliberately does *not* extend `AgiException`. A `catch (AgiException $e)` meant for real errors therefore never swallows or error-logs a normal hangup. `catch (AgiThrowable $e)` catches both.
- **No signal handler is installed.** Installing a SIGHUP handler is a global side effect a library shouldn't impose. The README and migration guide make `AGISIGHUP=no` a documented requirement, show the dialplan line, and explain what happens without it: the process is killed.

Why an exception:

- \+ A call script is linear. After a hangup every following command is meaningless, so stopping is the correct flow. An exception guarantees that, without a check after every call.
- \+ It gives a *clean* exit point: the application catches it once at its entry point, logs it as a normal event, runs cleanup and exits 0.
- \+ Precedent: PAGI (marcelog) throws `ChannelDownException` for the same case.
- − It feels like error handling for a normal event. Mitigation: it's a distinct exception type (not an "error"), documented as control flow, and meant to be caught in exactly one place.
- − Code that must keep working after hangup (the `h` extension, hangup handlers) must avoid commands that answer 511. `GET VARIABLE` and `SET VARIABLE` still work on a dead channel, so in practice that's rare.

Rejected alternative, returning the response and leaving callers to check `isHungUp()`: every call site that matters would need a check, and a missed one keeps the script running against a dead channel. `isHungUp()` still exists, for cleanup code that wants to ask.

Separately, a dropped connection (EOF/write failure) always throws `ConnectionException`, and protocol errors (510 invalid command, 520 bad syntax) always throw `InvalidCommandException`. Those are real errors.

## Architecture

```
src/
  Agi.php                    final; implements AgiInterface
  AgiInterface.php           complete public contract (consumers type-hint and mock this)
  AgiRequest.php             readonly; parsed AGI environment
  AgiResponse.php            readonly; code, result, data, extra
  Transport/
    Transport.php            interface: writeLine(), readLine()
    StreamTransport.php      stdin/stdout (default)
  Protocol/
    CommandBuilder.php       single place for quoting/escaping arguments
    ResponseParser.php       200 / 510 / 511 / 520 multi-line / HANGUP line
  Enum/
    ChannelState.php
    HangupCause.php          Q.850 cause codes
    ResultCode.php           200, 510, 511, 520
  Exception/
    AgiThrowable.php         marker interface for everything phpagi throws
    AgiException.php         base for real errors
    ConnectionException.php  EOF / write failure
    InvalidCommandException.php  510 / 520
    ChannelHungUpException.php   511 on a dead channel; extends \RuntimeException, not AgiException
  Testing/
    FakeTransport.php        scripted responses + recorded commands, for consumers' own tests
    AgiRequestFactory.php    builds an AgiRequest from an array, with sensible defaults
examples/
  hello-world.php, read-digits.php, variables.php, hangup-handling.php, testing-with-fake-transport.php
```

Key points:

- **Testability via `Transport`.** The library's own tests use an in-memory transport with scripted responses, which replaces the child-process fixtures. The constructor takes `?Transport $transport = null, ?LoggerInterface $logger = null` and reads the request header immediately.
- **Testing tools for consumers.** `Testing\FakeTransport` and `Testing\AgiRequestFactory` ship in the package, so users can test their AGI scripts against a real `Agi` instance with scripted Asterisk responses, without mocks and without Asterisk.
- **Complete interface.** `Agi` is `final`, and `AgiInterface` contains *every* public method. Any mocking library (PHPUnit, Mockery, Prophecy) can mock the interface; none can mock a final class.
- **`AgiRequest`** has typed properties for the Asterisk 20 header keys (`channel`, `extension`, `context`, `priority`, `uniqueId`, `callerId`, `callerIdName`, `dnid`, `rdnis`, `language`, `type`, `accountCode`, `threadId`, `enhanced`, `network`, `networkScript`, …), `arguments: list<string>` from `agi_arg_N`, and `raw: array<string,string>` for anything else. Header parsing stops on EOF (fixes the constructor hang).
- **`AgiResponse`** has `code: ResultCode`, `result: ?int`, `data: string`, `extra: array<string,string>` (e.g. `endpos=…`), plus `isSuccess()` and `digit(): ?string`.
- **One command builder.** Every argument goes through one quote/escape routine (quotes, backslashes, newlines), instead of each method building strings by hand. That fixes a whole class of escaping bugs at once, and the public `command()` method uses the same path.
- **The `EXEC` option delimiter is always `,`** (Asterisk ≥ 20). No `option_delim` setting.

## Public API (v3)

Values return as scalars where that's what callers want. Commands that can return DTMF or position data return `AgiResponse`.

| 2.x | v3 | Notes |
|---|---|---|
| `answer()` | `answer(): AgiResponse` | |
| `channel_status($ch)` | `channelStatus(?string $channel = null): ChannelState` | |
| `database_get/put/del/deltree` | `databaseGet(): ?string`, `databasePut(): void`, `databaseDel(): void`, `databaseDelTree(): void` | |
| `exec($app, $opts)` | `exec(string $app, string\|int ...$args): AgiResponse` | variadic, joined with `,`; `exec('Playback', 'file')` reads the same as in 2.x |
| `exec_goto($c,$e,$p)` | `execGoto(string $context, string $extension, string\|int $priority = 1): AgiResponse` | priority may be a label |
| `exec_dial(...)` | `execDial(string $dialString, ?int $timeout = null, string $options = ''): AgiResponse` | |
| `exec_setlanguage($l)` | `setLanguage(string $language): void` | via `CHANNEL(language)` |
| `get_data($f,$t,$max)` | `getData(string $file, ?int $timeoutMs = null, ?int $maxDigits = null): ?string` | returns the digits; `null` on timeout without input. Arguments are always positional, so a `null` timeout can't shift `maxDigits` |
| `get_variable($v, true)` | `getVariable(string $name): ?string` | `null` when unset |
| `get_variable($v)` | — | the full response is rarely needed; `getVariable()` or `command('GET VARIABLE', …)` covers it |
| `get_fullvariable($v,$ch)` | `evaluateExpression(string $expr, ?string $channel = null): ?string` | |
| `set_variable($v,$val)` | `setVariable(string $name, string\|int\|float $value): void` | `null` is a `TypeError` by design |
| `set_global_var` / `set_var` | `setGlobalVariable(string $name, string\|int\|float $value): void` | via `EXEC Set(name=value,g)`. Fixes the 2.x bug that sent `Set(...)` as a raw AGI command |
| `hangup($ch)` | `hangup(?string $channel = null): void` | |
| `caused_hangup($cause)` | `hangupWithCause(HangupCause $cause): void` | |
| `record_file(...)` | `recordFile(string $file, string $format, string $escapeDigits = '', int $timeoutMs = -1, bool $beep = false, ?int $silenceSeconds = null, ?int $offset = null): AgiResponse` | |
| `stream_file($f,$esc,$off)` | `streamFile(string $file, string $escapeDigits = '', int $offset = 0): AgiResponse` | `digit()`, `extra['endpos']` |
| — | `controlStreamFile(...)`, `getOption(...)` | Asterisk 20 commands, cheap to add |
| — | `command(string $name, string\|int ...$args): AgiResponse` | any other AGI command |
| `say_digits($d)` | `sayDigits(string $digits, string $escapeDigits = ''): AgiResponse` | **string**, validated `[0-9*#]+`; keeps leading zeros |
| `say_number`, `say_alpha`, `say_phonetic`, `say_time` | `sayNumber(int)`, `sayAlpha(string)`, `sayPhonetic(string)`, `sayTime(int)`, plus `sayDate`, `sayDateTime` | |
| `set_callerid`, `set_context`, `set_extension`, `set_priority`, `set_autohangup`, `set_music` | camelCase equivalents | `setPriority(int\|string)` accepts labels |
| `wait_for_digit`, `receive_char`, `send_text`, `send_image`, `tdd_mode`, `noop` | camelCase equivalents | |
| `verbose($msg, $lvl)` | `verbose(string $message, int $level = 1): void` | multi-line split kept, syslog side effect removed |
| `conlog()` | — | use the injected PSR-3 logger |
| `request['agi_channel']` | `request()->channel` | |
| `AGIRES_OK` | `$response->isSuccess()` | |
| `AST_STATE_*` | `ChannelState` enum | |

Removed with no replacement: `menu`, `text_input`, `parse_callerid`, `text2wav`, `swift`, `say_punctuation`, all `fastpass_*`, `exec_absolutetimeout` (use `TIMEOUT(absolute)` via `setVariable`), `exec_enumlookup`, `exec_agi`, `which`, `make_folder`, `$agi->config`, `$agi->audio` (EAGI audio could come back later as an explicit `EagiAudio` class if there's demand), `new_AsteriskManager` and `AGI_AsteriskManager` (use an AMI client such as Pegamento/PAMI), FastAGI, `phpagi_error_handler`, all global `define()`s.

**To verify during implementation:** check every command's syntax and response format against the Asterisk 20 AGI command docs (docs.asterisk.org → Asterisk 20 → AGI Commands). In particular: `SAY ALPHA` case-sensitivity, `RECORD FILE` argument order, `GET OPTION`, and which commands are allowed on a dead channel.

## Prior art

v3 takes concepts, not code, from other PHP AGI libraries:

| Library | Concepts adopted |
|---|---|
| uribes78/phpagi (`tico/phpagi`) | readonly `AgiRequest`/`AgiResponse` DTOs, `ChannelState` enum, injectable I/O, an interface for the client, exceptions on EOF, `getVariable(): ?string`, header parsing that stops on EOF |
| radiusone/phpagi | precise PHPStan shapes and documented result semantics per command, building commands in one formatter instead of string concatenation, the full Asterisk request-key list, `setPriority` accepting labels |
| marcelog/PAGI | a dedicated exception for a dead channel |
| deliberately not adopted | TTS and fastpass, a global error handler, syslog in `verbose`, the `\|` delimiter, `final` without a complete interface, typing digit strings as `int` (loses leading zeros) |

## Documentation

- **`README.md`:**
  - what the library is (AGI-only, with a pointer to Pegamento/PAMI for AMI);
  - requirements ("PHP 8.4+, 8.5 recommended", Asterisk ≥ 20) and the support policy;
  - install, and a 10-line quick start with the matching dialplan, including `Set(AGISIGHUP=no)`;
  - the hangup model, logging and testing.
- **`docs/`:** one page per topic (request and responses, commands, hangup handling, testing, logging), plus `UPGRADING-3.0.md`.
- **API reference:** docblocks on every public method, with links to the matching Asterisk 20 docs page. They're good enough to read in an IDE, and can be rendered with phpDocumentor later.
- **`examples/`:** small, runnable scripts. The integration job (see Testing) runs them, so they can't rot.
- **Project hygiene:** `CHANGELOG.md`, `CONTRIBUTING.md` (how to run the tests and tooling), `SECURITY.md` (how to report issues), issue and PR templates.

## Testing

- **Unit tests** for `ResponseParser` covering every response shape: `200 result=1 (data) endpos=123`, `200 result=-1`, `510 …`, `511 …`, the multi-line `520-…`/`520 End of proper usage.`, a stray blank line, the `HANGUP` notification, and EOF.
- **Unit tests** for `CommandBuilder` escaping: quotes, backslashes, newlines, empty strings, unicode.
- **Command tests** for every public method: exact bytes written and the parsed result, using `FakeTransport`. Because the library tests itself with the same tool consumers get, `FakeTransport` stays complete and correct.
- **Migrate the 2.x characterization tests:** each still-relevant case is ported to v3 or deliberately dropped, recorded in the PR. The `known-bug` cases become normal tests asserting the fixed behavior.
- **Integration job:** run Asterisk 20 (and 22) in Docker in CI, with a small dialplan that runs the `examples/` and a test AGI script against a Local channel, including a hangup mid-script. Nice to have for the first beta, required before 3.0.0.
- PHPStan max and code style checks in CI.
- The full suite runs on PHP 8.4 and 8.5, with deprecations, notices and warnings failing the run (see [PHP 8.5 compatibility](#php-85-compatibility)).

## Implementation steps

Each step is one or more small commits on a `v3` branch (from `master`, once the PHPUnit work is merged), with tests passing at every commit.

1. **Skeleton:** `composer.json` (PSR-4, `psr/log`, `php: ^8.4`, so 8.5 is allowed), strict types, PHPStan config (`phpVersion` 8.4–8.5), code style config, PHPUnit strict-deprecation settings, CI matrix 8.4 + 8.5. Move the 2.x sources to the `2.x` branch.
2. **Protocol core:** `Transport`, `StreamTransport`, `FakeTransport`, `ResponseParser`, `CommandBuilder`, enums, exceptions, plus tests.
3. **`AgiRequest` + `Agi` constructor:** header parsing, `agi_arg_N`, EOF handling, `AgiRequestFactory`.
4. **Commands:** grouped as channel/flow (answer, hangup, exec, goto, dial), variables/database, audio/DTMF (stream, record, say*, getData, waitForDigit), misc, and the generic `command()`.
5. **Hangup handling:** `HANGUP`-line state tracking, 511 → `ChannelHungUpException`, `isHungUp()`, and tests for a hangup mid-command, dead-channel-safe commands after a hangup, and `hangup()` followed by a command.
6. **Docs and examples:** README, `docs/` pages, `docs/UPGRADING-3.0.md`, `examples/`, CHANGELOG, CONTRIBUTING, SECURITY. Remove the old `docs/README.*`, `docs/README.phpagi-asmanager`, `docs/README.phpagi-fastagi` and `docs/fastagi.xinetd`.
7. **Docker integration job** against Asterisk 20 and 22.
8. **Publish on Packagist,** release `3.0.0-beta.1` and announce it. Validate it in real-world applications (Pegamento's own applications are the first adopters; their migrations are tracked in their own repositories), collect feedback, fix findings, then release `3.0.0`.

## Migration guide (`docs/UPGRADING-3.0.md`)

A standalone guide for any 2.x user. It's written alongside the implementation, and every API change in a v3 PR updates it. Contents:

1. **Requirements:** PHP 8.4+ (8.5 recommended), Asterisk ≥ 20, and the `composer require` line. 2.x stays available on the `2.x` branch for anyone who can't upgrade yet.
2. **AMI removed (prominent notice at the top of the guide):**
   > The Asterisk Manager Interface client (`AGI_AsteriskManager`, `$agi->new_AsteriskManager()`, `phpagi-asmanager.php` and the `[asmanager]` section of `phpagi.conf`) has been removed in 3.0. phpagi is now an AGI-only library. For AMI, use [Pegamento/PAMI](https://github.com/Pegamento/PAMI) (`composer require pegamento/pami`).

   Followed by a short table mapping the AMI actions 2.x users used most often (`Originate`, `Redirect`, `Hangup`, `GetVar`/`SetVar`, `QueueAdd`/`QueueRemove`, `Command`, event handlers) to their PAMI equivalents, with one before/after example. **To verify against the PAMI fork's actual class names when writing.**
3. **Other removals:** FastAGI/xinetd, TTS (`text2wav`, `swift`), fastpass helpers, `menu`, `text_input`, `parse_callerid`, `say_punctuation`, the global error handler and error emails, `phpagi.conf`, global constants. Each gets a one-line "do this instead".
4. **Construction and bootstrapping:** `include 'phpagi.php'; new AGI()` → autoloading plus `new Agi(logger: …)`; injecting a logger.
5. **Method mapping:** the full 2.x → 3.x table from [Public API (v3)](#public-api-v3), with the signature and return-type changes.
6. **Behavior changes to watch for:** `getVariable()` returns `?string`, not an array; `sayDigits()` takes a string; `exec()` is variadic and always uses `,`; `setVariable()` rejects `null`; `setGlobalVariable()` now actually works; and hangup handling (`ChannelHungUpException`, `isHungUp()`, and the `AGISIGHUP=no` requirement, with a dialplan example and a recommended top-level `try/catch`).
7. **Request and responses:** `$agi->request['agi_*']` → `$agi->request()->…`; `$res['result']`/`AGIRES_OK` → `AgiResponse`; `AST_STATE_*` → `ChannelState`; hangup cause codes → `HangupCause`.
8. **Testing your code:** use `FakeTransport` for realistic tests, or mock `AgiInterface` (not the final `Agi` class), and make sure mocks return values the real methods can return.
9. **Automated upgrade:** a Rector rule set shipped in the repository (`rector/phpagi-3.php`) that handles the mechanical renames (method names, `get_variable($x, true)` → `getVariable($x)`, constants → enums). It's tested against fixture files, and the guide lists what it can't do and needs a manual review.

## Risks

| Risk | Mitigation |
|---|---|
| Subtle protocol differences on Asterisk 20/22 versus what the 2.x tests assumed | parser tests from real Asterisk output; the Docker integration job |
| A design that only fits the first adopters | review the API against the Asterisk 20 AGI command list rather than against any one application; the beta period with external feedback before 3.0.0 |
| 2.x users stuck on the old API | the `2.x` branch with a clear end-of-support date, the migration guide, the Rector rule set |
| Users who relied on the AMI client | a prominent notice in the README and migration guide pointing to Pegamento/PAMI |
| Pegamento/PAMI isn't on Packagist, so `composer require pegamento/pami` from the guide fails | publish Pegamento/PAMI on Packagist before 3.0.0, or document the VCS repository setup in the guide |
| A hangup exception escaping from hangup handlers in user code | document the dead-channel-safe commands and the recommended entry-point `try/catch`; cover it in `examples/hangup-handling.php` |
