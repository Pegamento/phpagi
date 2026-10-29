# phpagi v3 — plan

Status: all design decisions taken; ready for review. Nothing here is implemented yet.

## Goals

- A small, fully typed AGI client for **PHP 8.4 and 8.5** (100% compatible with both: no errors, warnings or deprecations on either) and **Asterisk ≥ 20**, with no PHP 4/5 leftovers.
- One coherent breaking release. PhoneSystem (PPS) migrates in a single PR, and nothing gets revisited afterwards.
- Every behavior is covered by tests that need neither a running Asterisk nor a child process.

## Non-goals

- Backward compatibility with 2.x. The 2.x line moves to a `2.x` maintenance branch for hotfixes only.
- Asterisk < 20: no `|` option delimiter, no DeadAGI, no Zaptel, no legacy response quirks.
- TTS (festival/cepstral), "fastpass" helpers, FastAGI/xinetd, `mkdocs.php`.
- Being a framework. Menus, IVR helpers and call-flow logic belong in PPS.

## Decisions taken

| Topic | Decision |
|---|---|
| PHP | minimum **8.4** (`php: ^8.4`); **8.5 is the recommended version**, used for primary development, the default local and CI setup, and the docs examples. Both are fully supported (see [PHP 8.5 compatibility](#php-85-compatibility)). `declare(strict_types=1)` in every file |
| Asterisk | `>=20` (20 and 22 LTS). We document the AGI command set we target and test against it |
| Namespace / autoload | `Pegamento\PhpAgi\`, PSR-4, `src/` |
| Method names | camelCase (`getVariable`, `execGoto`, `streamFile`) |
| Return values | `readonly` value objects or plain scalars. No arrays, no flag-dependent return types |
| Constants | enums (`ChannelState`, `HangupCause`, `ResultCode`) |
| Config | explicit constructor arguments. No `/etc/asterisk/phpagi.conf`, no temp dir handling |
| Error handling | no global `set_error_handler`, no error emails, no syslog side effects. Optional PSR-3 logger (`psr/log`), defaulting to `NullLogger` |
| AMI | **removed.** v3 is AGI-only. `AGI_AsteriskManager`, `$agi->new_AsteriskManager()` and the `[asmanager]` config are gone, with no replacement in this package. AMI goes through [Pegamento/PAMI](https://github.com/Pegamento/PAMI) (`pegamento/pami`), which PPS already uses. Rationale: about a third less code to maintain, zero current users of the phpagi AMI class in PPS, and one AMI client in our stack instead of two. Any future AMI modernization happens in the PAMI fork, not here |
| Hangup | `ChannelHungUpException` on a command refused on a dead channel (511), plus `AGISIGHUP=no` in the dialplan (see [Hangup handling](#hangup-handling-decided-channelhungupexception)) |
| Migration guide | `docs/UPGRADING-3.0.md` ships with 3.0.0 (see [Migration guide](#migration-guide-docsupgrading-30md)) |
| Quality | PHPStan max level, PSR-12 code style, PHPUnit 12, CI on PHP 8.4 and 8.5, where any deprecation, notice or warning fails the build |

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
- **Dependencies:** every runtime and dev dependency (`psr/log`, PHPUnit 12, PHPStan) must declare 8.5 support. CI installs on 8.5 without `--ignore-platform-reqs`. Remove the `config.platform.php = 8.4` pin from `composer.json`, or keep it only for lock-file generation, and run CI with `composer update` per PHP version, so 8.5 resolves its own dependency versions.
- **PPS:** the target is to run PPS on 8.5 as well, in line with the recommendation. Confirm which PHP version production and test PBXs run, and that PPS's own dependencies (adodb, aws-sdk, guzzle, monolog, PAMI fork) are 8.5-clean before PPS moves to 8.5. That's outside phpagi, but it decides when 8.5 actually runs in production.

## Hangup handling (decided: `ChannelHungUpException`)

Context: on Asterisk 20, when the caller hangs up, Asterisk by default sends **SIGHUP** to the AGI process. PHP has no SIGHUP handler by default, so the script is **killed on the spot**, mid-logic and without cleanup or logging. With `AGISIGHUP=no` set on the channel, Asterisk instead writes a `HANGUP` line on stdin and answers every further command (except a few, like `GET VARIABLE`) with `511 Command Not Permitted on a dead channel`. PPS currently sets `AGISIGHUP` nowhere, and its AGI scripts install no SIGHUP handler, so today they run with the default.

**Decision:** when a command is refused because the channel is gone, v3 throws `ChannelHungUpException`. PPS sets `AGISIGHUP=no` so the script gets the chance to see it.

How it works:

- **The `HANGUP` line only records state.** With `AGISIGHUP=no`, Asterisk writes a `HANGUP` line on stdin when the caller hangs up. The parser consumes it, sets `$agi->isHungUp() === true`, and continues reading the actual response to the pending command. It doesn't throw yet.
- **A 511 response throws.** "Command not permitted on a dead channel" throws `ChannelHungUpException`, carrying the command that was refused and the request's channel name and unique ID.
- **Dead-channel-safe commands keep working.** Commands Asterisk still allows on a dead channel (such as `GET VARIABLE`, `SET VARIABLE` and `VERBOSE`; exact list to verify against Asterisk 20) return normally. That keeps `h`-extension and hangup-handler scripts usable, and lets cleanup code read channel variables after a hangup.
- **`hangup()` itself never throws.** Hanging up our own channel is normal; only the *next* refused command throws.
- **It isn't an error type.** `ChannelHungUpException` extends `\RuntimeException` and implements a shared marker interface `AgiThrowable`, but deliberately does *not* extend `AgiException`. A `catch (AgiException $e)` meant for real errors therefore never swallows or error-logs a normal hangup. `catch (AgiThrowable $e)` catches both.
- **No signal handler is installed.** Like the error handler, installing a SIGHUP handler is a global side effect a library shouldn't impose. The README and migration guide make `AGISIGHUP=no` a documented requirement and explain what happens without it: the process is killed.

Why an exception:

- \+ A call script is linear. After a hangup every following command is meaningless, so stopping is the correct flow. An exception guarantees that, without a check after every one of ~2,200 calls.
- \+ It gives a *clean* exit point: PPS's runner catches it once, logs at info level, runs cleanup and exits 0. That beats today's situation, where SIGHUP probably kills the process.
- \+ Precedent: PAGI (marcelog) throws `ChannelDownException` for the same case.
- − It feels like error handling for a normal event. Mitigation: it's a distinct exception type (not an "error"), documented as control flow, and caught in exactly one place.
- − Code that must keep working after hangup (the `h` extension, hangup handlers) must avoid commands that answer 511. `GET VARIABLE` and `SET VARIABLE` still work on a dead channel, so in practice that's rare.

Rejected alternative, returning the response and leaving callers to check `isHungUp()`: every call site that matters would need a check, and a missed one keeps the script running against a dead channel. `isHungUp()` still exists, for cleanup code that wants to ask.

Separately, a dropped connection (EOF/write failure) always throws `ConnectionException`, and protocol errors (510 invalid command, 520 bad syntax) always throw `InvalidCommandException`. Those are real errors.


## Architecture

```
src/
  Agi.php                    final; implements AgiInterface
  AgiInterface.php           complete public contract (PPS type-hints and mocks this)
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
    HangupCause.php          Q.850 causes; replaces AgiContext::HANGUP_*
    ResultCode.php           200, 510, 511, 520
  Exception/
    AgiException.php         base
    ConnectionException.php  EOF / write failure
    InvalidCommandException.php  510 / 520
    AgiThrowable.php         marker interface for everything phpagi throws
    ChannelHungUpException.php   511 on a dead channel; extends \RuntimeException, not AgiException
```

Key points:

- **Testability via `Transport`.** Tests use an in-memory transport with scripted responses, which replaces the child-process fixtures. The constructor takes `?Transport $transport = null, ?LoggerInterface $logger = null` and reads the request header immediately.
- **Complete interface.** `Agi` is `final`, and `AgiInterface` contains *every* public method, so Mockery mocks the interface. (uribes78's interface misses methods, which would block PPS.)
- **`AgiRequest`** has typed properties for the Asterisk 20 header keys (`channel`, `extension`, `context`, `priority`, `uniqueId`, `callerId`, `callerIdName`, `dnid`, `rdnis`, `language`, `type`, `accountCode`, `threadId`, `enhanced`, `network`, `networkScript`, …), `arguments: list<string>` from `agi_arg_N`, and `raw: array<string,string>` for anything else. Header parsing stops on EOF (fixes the constructor hang).
- **`AgiResponse`** has `code: ResultCode`, `result: ?int`, `data: string`, `extra: array<string,string>` (e.g. `endpos=…`), plus `isSuccess()` and `digit(): ?string`.
- **One command builder.** Every argument goes through one quote/escape routine (quotes, backslashes, newlines), instead of each method building strings by hand. That fixes a whole class of escaping bugs at once.
- **The `EXEC` option delimiter is always `,`** (Asterisk ≥ 20). No `option_delim` setting.

## Public API (v3)

Values return as scalars where that's what callers want. Commands that can return DTMF or position data return `AgiResponse`.

| 2.x | v3 | Notes |
|---|---|---|
| `answer()` | `answer(): AgiResponse` | |
| `channel_status($ch)` | `channelStatus(?string $channel = null): ChannelState` | |
| `database_get/put/del/deltree` | `databaseGet(): ?string`, `databasePut(): void`, `databaseDel(): void`, `databaseDelTree(): void` | |
| `exec($app, $opts)` | `exec(string $app, string\|int ...$args): AgiResponse` | variadic, joined with `,`; `exec('Playback', 'file')` reads the same as today |
| `exec_goto($c,$e,$p)` | `execGoto(string $context, string $extension, string\|int $priority = 1): AgiResponse` | priority may be a label |
| `exec_dial(...)` | `execDial(string $dialString, ?int $timeout = null, string $options = ''): AgiResponse` | |
| `exec_setlanguage($l)` | `setLanguage(string $language): void` | via `CHANNEL(language)` |
| `get_data($f,$t,$max)` | `getData(string $file, ?int $timeoutMs = null, ?int $maxDigits = null): ?string` | returns the digits; `null` on timeout without input. Arguments are always positional, so a `null` timeout can't shift `maxDigits` |
| `get_variable($v, true)` | `getVariable(string $name): ?string` | `null` when unset |
| `get_variable($v)` | — | the full response is rarely needed; `getVariable()` covers it |
| `get_fullvariable($v,$ch)` | `evaluateExpression(string $expr, ?string $channel = null): ?string` | |
| `set_variable($v,$val)` | `setVariable(string $name, string\|int\|float $value): void` | `null` is a `TypeError` by design |
| `set_global_var` / `set_var` | `setGlobalVariable(string $name, string\|int\|float $value): void` | via `EXEC Set(name=value,g)`. Fixes the 2.x bug that sent `Set(...)` as a raw AGI command |
| `hangup($ch)` | `hangup(?string $channel = null): void` | |
| `caused_hangup($cause)` | `hangupWithCause(HangupCause $cause): void` | |
| `record_file(...)` | `recordFile(string $file, string $format, string $escapeDigits = '', int $timeoutMs = -1, bool $beep = false, ?int $silenceSeconds = null, ?int $offset = null): AgiResponse` | |
| `stream_file($f,$esc,$off)` | `streamFile(string $file, string $escapeDigits = '', int $offset = 0): AgiResponse` | `digit()`, `extra['endpos']` |
| — | `controlStreamFile(...)`, `getOption(...)` | Asterisk 20 commands, cheap to add |
| `say_digits($d)` | `sayDigits(string $digits, string $escapeDigits = ''): AgiResponse` | **string**, validated `[0-9*#]+`; keeps leading zeros (radiusone and uribes78 both lose them) |
| `say_number`, `say_alpha`, `say_phonetic`, `say_time` | `sayNumber(int)`, `sayAlpha(string)`, `sayPhonetic(string)`, `sayTime(int)`, plus `sayDate`, `sayDateTime` | |
| `set_callerid`, `set_context`, `set_extension`, `set_priority`, `set_autohangup`, `set_music` | camelCase equivalents | `setPriority(int\|string)` accepts labels (idea from radiusone) |
| `wait_for_digit`, `receive_char`, `send_text`, `send_image`, `tdd_mode`, `noop` | camelCase equivalents | |
| `verbose($msg, $lvl)` | `verbose(string $message, int $level = 1): void` | multi-line split kept, syslog side effect removed |
| `conlog()` | — | use the injected PSR-3 logger |
| `request['agi_channel']` | `request()->channel` | |
| `AGIRES_OK` | `$response->isSuccess()` | |
| `AST_STATE_*` | `ChannelState` enum | |

Removed with no replacement: `menu`, `text_input`, `parse_callerid`, `text2wav`, `swift`, `say_punctuation`, all `fastpass_*`, `exec_absolutetimeout` (use `TIMEOUT(absolute)` via `setVariable`), `exec_enumlookup`, `exec_agi`, `which`, `make_folder`, `$agi->config`, `$agi->audio` (EAGI audio could come back later as an explicit `EagiAudio` class if needed), `new_AsteriskManager` and `AGI_AsteriskManager` (use Pegamento/PAMI), FastAGI, `phpagi_error_handler`, all global `define()`s.

**To verify during implementation:** check every command's syntax and response format against the Asterisk 20 AGI command docs (docs.asterisk.org → Asterisk 20 → AGI Commands). In particular: `SAY ALPHA` case-sensitivity, `RECORD FILE` argument order, `GET OPTION`, and which commands are allowed on a dead channel.

## Concepts adopted from the other forks

Concepts only; no code is copied.

| From | Concept |
|---|---|
| uribes78 | readonly `AgiRequest`/`AgiResponse` DTOs, `ChannelState` enum, injectable I/O, an interface for the client, exceptions on EOF, `getVariable(): ?string`, header parsing that stops on EOF |
| radiusone | precise PHPStan shapes and documented result semantics per command, building commands in one formatter instead of string concatenation, the full Asterisk request-key list, `setPriority` accepting labels |
| neither (deliberately not adopted) | TTS and fastpass, global error handler, syslog in `verbose`, the `\|` delimiter, `final` without a complete interface, typing digit strings as `int` |

## Testing

- **Unit tests** for `ResponseParser` covering every response shape: `200 result=1 (data) endpos=123`, `200 result=-1`, `510 …`, `511 …`, the multi-line `520-…`/`520 End of proper usage.`, a stray blank line, the `HANGUP` notification, and EOF.
- **Unit tests** for `CommandBuilder` escaping: quotes, backslashes, newlines, empty strings, unicode.
- **Command tests** for every public method: exact bytes written and the parsed result, using the in-memory transport.
- **Migrate the 2.x characterization tests:** each still-relevant case is ported to v3 or deliberately dropped, recorded in the PR. The `known-bug` cases become normal tests asserting the fixed behavior.
- **Optional integration job:** run Asterisk 20 in Docker in CI, with a tiny dialplan that executes a test AGI script against a Local channel. Nice to have, not a release blocker.
- PHPStan max and code style checks in CI.
- The full suite runs on PHP 8.4 and 8.5, with deprecations, notices and warnings failing the run (see [PHP 8.5 compatibility](#php-85-compatibility)).

## Implementation steps

Each step is one or more small commits on a `v3` branch (from `master`, once the PHPUnit work is merged), with tests passing at every commit.

1. **Skeleton:** `composer.json` (PSR-4, `psr/log`, `php: ^8.4`, so 8.5 is allowed), strict types, PHPStan config (`phpVersion` 8.4–8.5), code style config, PHPUnit strict-deprecation settings, CI matrix 8.4 + 8.5. Move the 2.x sources to the `2.x` branch.
2. **Protocol core:** `Transport`, `StreamTransport`, `ResponseParser`, `CommandBuilder`, enums, exceptions, plus tests.
3. **`AgiRequest` + `Agi` constructor:** header parsing, `agi_arg_N`, EOF handling.
4. **Commands:** grouped as channel/flow (answer, hangup, exec, goto, dial), variables/database, audio/DTMF (stream, record, say*, getData, waitForDigit), misc.
5. **Hangup handling:** `HANGUP`-line state tracking, 511 → `ChannelHungUpException`, `isHungUp()`, and tests for a hangup mid-command, dead-channel-safe commands after a hangup, and `hangup()` followed by a command.
6. **Docs:** a new `README.md` (which states the PHP requirement as "8.4+, 8.5 recommended", and up front that v3 is AGI-only and points to Pegamento/PAMI for AMI), plus `docs/UPGRADING-3.0.md` as described below. Remove the old `docs/README.*`, `docs/README.phpagi-asmanager` and `docs/README.phpagi-fastagi`.
7. **Release `3.0.0-beta.1`,** migrate PPS against it on a test PBX, fix findings, then release `3.0.0`.

## Migration guide (`docs/UPGRADING-3.0.md`)

A standalone guide for any 2.x user, not only PPS. It's written alongside the implementation, and every API change in a v3 PR updates it. Contents:

1. **Requirements:** PHP 8.4+ (8.5 recommended), Asterisk ≥ 20, and the `composer require` line. 2.x stays available on the `2.x` branch for anyone who can't upgrade yet.
2. **AMI removed (prominent notice at the top of the guide):**
   > The Asterisk Manager Interface client (`AGI_AsteriskManager`, `$agi->new_AsteriskManager()`, `phpagi-asmanager.php` and the `[asmanager]` section of `phpagi.conf`) has been removed in 3.0. phpagi is now an AGI-only library. For AMI, use [Pegamento/PAMI](https://github.com/Pegamento/PAMI) (`composer require pegamento/pami`).

   Followed by a short table mapping the AMI actions 2.x users used most often (`Originate`, `Redirect`, `Hangup`, `GetVar`/`SetVar`, `QueueAdd`/`QueueRemove`, `Command`, event handlers) to their PAMI equivalents, with one before/after example. **To verify against the PAMI fork's actual class names when writing.**
3. **Other removals:** FastAGI/xinetd, TTS (`text2wav`, `swift`), fastpass helpers, `menu`, `text_input`, `parse_callerid`, `say_punctuation`, the global error handler and error emails, `phpagi.conf`, global constants. Each gets a one-line "do this instead".
4. **Construction and bootstrapping:** `include 'phpagi.php'; new AGI()` → autoloading plus `new Agi(logger: …)`; injecting a logger; testing with a custom `Transport`.
5. **Method mapping:** the full 2.x → 3.x table from [Public API (v3)](#public-api-v3), with the signature and return-type changes.
6. **Behavior changes to watch for:** `getVariable()` returns `?string`, not an array; `sayDigits()` takes a string; `exec()` is variadic and always uses `,`; `setVariable()` rejects `null`; `setGlobalVariable()` now actually works; and hangup handling (`ChannelHungUpException`, `isHungUp()`, and the `AGISIGHUP=no` requirement, with a dialplan example and a recommended top-level `try/catch`).
7. **Request and responses:** `$agi->request['agi_*']` → `$agi->request()->…`; `$res['result']`/`AGIRES_OK` → `AgiResponse`; `AST_STATE_*` → `ChannelState`; hangup cause codes → `HangupCause`.
8. **Testing your code:** mock `AgiInterface` (not the final `Agi` class), and make mocks return values the real methods can return.
9. **Search-and-replace cheat sheet:** the regexes or Rector rules used for the PPS migration, so other users can reuse them.

## PPS migration (one PR in PhoneSystem)

1. `composer require pegamento/phpagi:^3.0`. `bootstrap.php` creates `new \Pegamento\PhpAgi\Agi(logger: $log)`, and the ~75 standalone scripts switch from `include "phpagi.php"; new AGI()` to the bootstrap.
2. `Models\AgiContext`: `public AgiInterface $agi`; the `HANGUP_*` constants are replaced by `HangupCause`.
3. Mechanical renames using a scripted mapping (Rector or a reviewed sed script): `verbose` stays, `exec` stays, `get_variable(X, true)` → `getVariable(X)`, `set_variable` → `setVariable`, `exec_goto` → `execGoto`, `caused_hangup` → `hangupWithCause`, and so on.
4. Manual review: the 163 `get_variable(X)` calls that use the full array, the 24 `AGIRES_OK` checks, `request['agi_*']` → `request()->…`, the 66 `$argv[...]` uses → `request()->arguments` (where they are AGI arguments), `AST_*_DIR` constants → PPS constants, and `set_variable` calls that can receive `null`.
5. Tests: `Mockery::mock(AgiInterface::class)`, and fix mocks that return impossible values (`null`/`true` where a string or response is required).
6. Hangup: the handler runner (or a top-level `try/catch` in each standalone script) catches `ChannelHungUpException`, logs it at info level, runs cleanup and exits 0. The dialplan sets `AGISIGHUP=no` on every AGI call, for example via `Set(AGISIGHUP=no)` before `AGI(...)` or globally in the relevant contexts. Audit the `h`-extension and hangup scripts (`hangup_post.php`, `hangup_outbound.php`, `queue_post.php`, …) to confirm they only use dead-channel-safe commands.
7. Smoke-test the main call flows on a test PBX (inbound, outbound, queue, voicemail, IVR, transfer, recording) before merging.

## Risks

| Risk | Mitigation |
|---|---|
| Subtle protocol differences on Asterisk 20 versus what the 2.x tests assumed | parser tests from real Asterisk 20 output; optional Docker integration job |
| A missed call site in PPS | type hints plus PHPStan in PPS catch removed methods and type mismatches; the beta on a test PBX |
| Hangup exception escaping from hangup handlers | catch in one runner; audit `h`-extension scripts during migration |
| Other consumers of 2.x, or of its AMI client | the `2.x` branch stays available; the migration guide points AMI users to Pegamento/PAMI; ask around internally before the release |
