# phpagi v3 — plan

Status: all design decisions taken and reviewed, including against the Asterisk 20 and 22 source; ready for implementation. Nothing here is implemented yet.

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
| Hangup | `ChannelHungUpException` when the channel is gone: detected from a 511, a self-reported `(hangup)`, or a `CHANNEL STATUS` probe after a failure value. Plus `AGISIGHUP=no` in the dialplan (see [Hangup handling](#hangup-handling-decided-channelhungupexception)) |
| Failed commands | one rule: a command that runs but fails throws `CommandFailedException`. "Not found / not set" is not a failure and returns `null` or `false`. Only `command()` and `exec()` return a raw `AgiResponse` (see [Command failures](#command-failures)) |
| Extensibility | a public `command(string $name, string\|int ...$args): AgiResponse` for AGI commands without a dedicated method (new or rarely used ones), going through the same escaping and parsing |
| Versioning | SemVer. Raising the minimum PHP or Asterisk version only happens in a new major. A `CHANGELOG.md` in Keep a Changelog format. What counts as a breaking change, in particular for `AgiInterface` and the enums, is defined in [Backward-compatibility promise](#backward-compatibility-promise) |
| Migration guide | `docs/UPGRADING-3.0.md` ships with 3.0.0 (see [Migration guide](#migration-guide-docsupgrading-30md)) |
| Quality | PHPStan max level, PSR-12 code style, PHPUnit 12, and GitHub Actions CI on the **latest patch releases of PHP 8.4 and 8.5**, where any deprecation, notice or warning fails the build (see [Continuous integration](#continuous-integration-github-actions)) |

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
  - CI runs the full suite on the latest 8.4 and 8.5 patch releases. A green run on both is the release gate (see [Continuous integration](#continuous-integration-github-actions)).
- **Dependencies:** every runtime and dev dependency (`psr/log`, PHPUnit 12, PHPStan) must declare 8.5 support. CI installs on 8.5 without `--ignore-platform-reqs`. Remove the `config.platform.php = 8.4` pin from `composer.json`, or keep it only for lock-file generation, and run CI with `composer update` per PHP version, so 8.5 resolves its own dependency versions. As a library, phpagi doesn't commit a `composer.lock`.

## Hangup handling (decided: `ChannelHungUpException`)

Verified against the Asterisk 20 source (`res/res_agi.c`, branch `20`). Function names are given rather than line numbers, since those drift between releases.

### How Asterisk behaves

- **By default Asterisk sends SIGHUP** to the AGI process when the caller hangs up (`run_agi()`, controlled by the `AGISIGHUP` channel variable). PHP has no SIGHUP handler by default, so the script is **killed on the spot**, mid-logic and without cleanup or logging. Many existing AGI applications run with this default without realizing it.
- **With `AGISIGHUP=no`, a normal AGI script gets no signal and no notification at all.** `run_agi()` writes a `HANGUP` line only when `AGISIGHUP` is *on* and the connection is FastAGI (`agi->fast`). For a regular AGI process the hangup is silent: the script only notices through the responses to its next commands.
- **A command running during the hangup** (playback, `GET DATA`, `WAIT FOR DIGIT`, …) ends with its normal failure value, usually `200 result=-1`. That's indistinguishable from a non-hangup failure, such as `STREAM FILE` on a missing file, which also answers `200 result=-1 endpos=…`.
- **Two commands report the hangup themselves:** `RECORD FILE` answers `200 result=-1 (hangup) endpos=…`, and `RECEIVE CHAR` answers `200 result=-1 (hangup)`.
- **Asterisk always processes a pending hangup before it reads the next command.** In `run_agi()`'s loop, when `ast_check_hangup(chan)` is true, Asterisk drains the channel until `ast_read()` returns NULL, switches to dead mode, and only then reads from the AGI pipe again. So after a hangup, the next command is always handled in dead mode.
- **In dead mode, a command runs only if its table entry is marked dead-safe** (the last field in the `commands[]` table); every other command gets `511 Command Not Permitted on a dead channel or intercept routine` (`agi_handle_command()`). The **dead-safe commands in Asterisk 20** are: `EXEC`, `DATABASE DEL`, `DATABASE DELTREE`, `DATABASE GET`, `DATABASE PUT`, `GET VARIABLE`, `GET FULL VARIABLE`, `SET VARIABLE`, `NOOP`, `VERBOSE`, `ASYNCAGI BREAK`, `SPEECH DESTROY` and `SPEECH UNLOAD GRAMMAR`. Everything else, including `ANSWER`, `HANGUP`, `CHANNEL STATUS`, `STREAM FILE`, `GET DATA`, all `SAY *`, all `SET` commands except `SET VARIABLE`, and `GOSUB` (registered by `apps/app_stack.c`, not `res_agi.c`), answers 511. `res_agi.c` is identical in Asterisk 20 and 22, and the list is the same on both.
- **The session continues after a failed command.** `RESULT_FAILURE` from a handler doesn't end the AGI loop.

### Decision

When the channel turns out to be gone, v3 throws `ChannelHungUpException`, which callers catch once at their entry point. The docs require `AGISIGHUP=no`, because otherwise the process is killed before the library can do anything.

### How the library detects a hangup

1. **A 511 response** to any command throws `ChannelHungUpException` and sets `isHungUp()`.
2. **A self-reported hangup** (`(hangup)` in the response to `RECORD FILE` or `RECEIVE CHAR`) sets `isHungUp()` immediately, without a probe. `receiveChar()` then throws `ChannelHungUpException`. `recordFile()` *returns*, see below.
3. **The probe.** When a command that isn't dead-safe returns its failure value, the library can't tell "failed" from "caller hung up" by the response alone. It then sends one probe, `CHANNEL STATUS`, which isn't dead-safe:
   - `511` → the channel is gone: set `isHungUp()`, throw `ChannelHungUpException` for the *original* command.
   - any other answer → a real failure: throw `CommandFailedException` for the original command. The probe's own answer is discarded.

   This is reliable because Asterisk processes a pending hangup before reading the next command (see above), so a probe sent after a hangup always gets 511. It costs one extra exchange, and only on failures.
4. **`exec()` with result `-1` is probed too.** `EXEC` is dead-safe, so it never gets a 511. A dialplan application returning `-1` usually means "the channel hung up / stop". If the probe answers 511, `exec()` throws `ChannelHungUpException`; otherwise it returns the raw response as usual. This matters, because scripts that drive the call mostly through `exec('Playback', …)` and similar would otherwise never see the hangup.
5. **Dead-safe commands are never probed.** A failing `databasePut()` on a dead channel didn't fail *because* of the hangup, so it throws `CommandFailedException` as usual.
6. **The `HANGUP` line is still parsed,** but only matters for custom transports: FastAGI-style servers built on our `Transport` interface, where Asterisk does send it with `AGISIGHUP` on. When it appears, it sets `isHungUp()`, and the response to the pending command is read as usual.

### Behavior per method

- **`hangup()` (own channel) never throws, and always sets `isHungUp()`.** On a live channel, Asterisk answers `HANGUP` with `200 result=1` and the channel is gone afterwards. On a channel that's already gone, it answers 511, which `hangup()` treats as success too.
- **`hangupWithCause()` never throws either, and sets `isHungUp()`.** It runs the `Hangup` dialplan application through `EXEC`, and that application always returns `-1` (`pbx_builtin_hangup()`). So it must *not* go through `exec()`'s rule 4, which would probe, get 511 and throw. It sends the `EXEC` itself and skips the probe. A plain `exec('Hangup')` call, on the other hand, follows rule 4 and **does** throw `ChannelHungUpException`; the docs point to `hangup()`/`hangupWithCause()` instead.
- **`recordFile()` returns on a hangup.** The recording is still on disk and usually still wanted (voicemail, call notes), so it returns a `RecordingResult` with `endReason: RecordingEndReason::Hangup`. The *next* refused command throws as usual.
- **`execDial()` returns on a hangup.** When the caller ends an answered call, `Dial` returns `-1` on a now-dead channel with `DIALSTATUS=ANSWER`. That's how most successful calls end. `execDial()` reads `DIALSTATUS` (`GET VARIABLE` works on a dead channel), probes once to set `isHungUp()` correctly, and **returns the `DialStatus`**, without throwing. The next refused command throws.
- **Dead-safe commands keep working after a hangup, silently.** `getVariable()`, `setVariable()`, `evaluateExpression()`, the `database*()` methods, `verbose()` and `noop()` behave normally on a dead channel. That keeps `h`-extension and hangup-handler scripts usable. The same is true for **everything built on `EXEC`**: `exec()` (apart from rule 4), `execGoto()`, `setGlobalVariable()` and `hangupWithCause()` all run on a dead channel without a 511. The docs say so, and call flows that must not continue after a hangup should check `isHungUp()` or call `checkHangup()`.
- **`checkHangup(): bool`** sends the probe on request and updates `isHungUp()`. It's for scripts, or long stretches of a script, that only use dead-safe commands and therefore would never see a 511 (for example a loop of `exec()` calls whose applications don't return `-1`). `isHungUp()` itself only reports what the library already knows, and never sends anything.
- **`AGIEXITONHANGUP=yes`** (an Asterisk channel variable) makes Asterisk end the AGI session on hangup, which the script sees as EOF → `ConnectionException`. The docs recommend leaving it off with v3, so the hangup surfaces as `ChannelHungUpException` instead.

### Design notes

- **It isn't an error type.** `ChannelHungUpException` extends `\RuntimeException` and implements a shared marker interface `AgiThrowable`, but deliberately does *not* extend `AgiException`. A `catch (AgiException $e)` meant for real errors therefore never swallows or error-logs a normal hangup. `catch (AgiThrowable $e)` catches both. The exception carries the command that triggered it, and the request's channel name and unique ID.
- **No signal handler is installed.** Installing a SIGHUP handler is a global side effect a library shouldn't impose. The README and migration guide make `AGISIGHUP=no` a documented requirement, show the dialplan line, and explain what happens without it: the process is killed.
- **The probe is visible.** It's logged at debug like any command, and `FakeTransport` requires it to be scripted (see [`FakeTransport` behavior](#faketransport-behavior)), so tests document exactly what happens on the wire.
- **Known limitation: interception routines.** Asterisk also runs AGI in its restricted dead mode when the script is started from a connected-line or redirecting interception subroutine (`ast_channel_get_intercept_mode()` in `run_agi()`). There, every command that isn't dead-safe answers 511 (`… on a dead channel or intercept routine`) even though the channel is alive, so the library would report a hangup. AGI from interception subroutines is rare and limited to dead-safe commands anyway; the docs mention it, and v3 doesn't try to tell the two apart.

Why an exception:

- \+ A call script is linear. After a hangup every following command is meaningless, so stopping is the correct flow. An exception guarantees that, without a check after every call.
- \+ It gives a *clean* exit point: the application catches it once at its entry point, logs it as a normal event, runs cleanup and exits 0.
- \+ Precedent: PAGI (marcelog) throws `ChannelDownException` for the same case.
- − It feels like error handling for a normal event. Mitigation: it's a distinct exception type (not an "error"), documented as control flow, and meant to be caught in exactly one place.
- − Code that must keep working after a hangup (the `h` extension, hangup handlers) must stick to dead-safe commands. The list above covers what such code normally needs: variables, the database, logging and `EXEC`.

Rejected alternative, returning the response and leaving callers to check `isHungUp()`: every call site that matters would need a check, and a missed one keeps the script running against a dead channel. `isHungUp()` and `checkHangup()` still exist, for code that wants to ask.

Separately, a dropped connection (EOF/write failure) always throws `ConnectionException`, and protocol errors (510 invalid command, 520 bad syntax) always throw `InvalidCommandException`. Those are real errors.

## Command failures

Settled before the command work (implementation step 4) starts. Asterisk reports most failures as a successful protocol exchange with a failure value, typically `200 result=-1`, and the meaning of `result` differs per command. 2.x passed that through and left every caller to interpret it. v3 interprets it once, inside the library, with one rule:

1. **Protocol errors** (510 unknown command, 520 bad syntax) throw `InvalidCommandException`. These are bugs in the calling code or the library.
2. **A dead channel** throws `ChannelHungUpException`. It's detected from a 511, from a response that reports `(hangup)` itself, or from the `CHANNEL STATUS` probe the library sends when a non-dead-safe command returns its failure value (see [Hangup handling](#hangup-handling-decided-channelhungupexception)). So rule 3 only applies once the probe has shown the channel is still up.
3. **A command that ran and failed** (the command's documented failure value, usually `result=-1`, on a live channel) throws `CommandFailedException`. It carries the command, the raw `AgiResponse` and the channel. `void` methods therefore never fail silently: a `databasePut()` or `setVariable()` either worked or threw.
4. **"Not found / not set / nothing pressed" is an answer, not a failure.** It's returned as `null` (`getVariable()`, `databaseGet()`, `channelStatus()` for an unknown channel, `waitForDigit()` on timeout) or `false` (`databaseDel()` when the key didn't exist).
5. **Raw responses only from the escape hatches.** `command()` returns the `AgiResponse` and applies only rules 1 and 2, because the library can't know an arbitrary command's failure value. `exec()` does the same, since `result` is whatever the dialplan application returns. Two exceptions: `result=-2` from `EXEC` means the application doesn't exist, which throws `ApplicationNotFoundException` (a subclass of `CommandFailedException`); and `result=-1` triggers the hangup probe, because `EXEC` never gets a 511.

All command methods except `command()` and `exec()` return a scalar, an enum, a small result object, or `void`. The per-command failure values come from the Asterisk 20 AGI documentation and `res_agi.c`; each one gets a test in the command tests.

Exception hierarchy:

```
AgiThrowable (interface)
├── AgiException (abstract, real errors)
│   ├── ConnectionException          EOF / write failure
│   ├── InvalidCommandException      510 / 520
│   └── CommandFailedException       command ran and reported failure
│       └── ApplicationNotFoundException   EXEC result=-2
└── ChannelHungUpException           extends \RuntimeException, not AgiException
```

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
    ResponseParser.php       200 / 510 / 511 / 520 multi-line / (hangup) markers / HANGUP line (custom transports)
    HangupDetector.php       dead-safe command list, probe decision, isHungUp() state
  AbstractAgiDecorator.php   forwards every AgiInterface method to an inner instance (supported way to wrap Agi)
  Result/
    PlaybackResult.php       readonly; ?digit, endPosition
    DtmfInput.php            readonly; digits, timedOut
    RecordingResult.php      readonly; endReason, ?digit, endPosition
  Enum/
    ChannelState.php
    DialStatus.php           DIALSTATUS values (Answer, Busy, NoAnswer, Cancel, Congestion, ChanUnavail, …)
    HangupCause.php          Q.850 cause codes
    RecordingEndReason.php   Dtmf, Timeout (time limit *or* silence; Asterisk reports both as "(timeout)"), Hangup
    ResultCode.php           200, 510, 511, 520
    TddMode.php              On, Off, Mate
  Exception/
    AgiThrowable.php         marker interface for everything phpagi throws
    AgiException.php         abstract base for real errors
    ConnectionException.php  EOF / write failure
    InvalidCommandException.php  510 / 520
    CommandFailedException.php   command ran and reported failure
    ApplicationNotFoundException.php  EXEC result=-2
    ChannelHungUpException.php   dead channel; extends \RuntimeException, not AgiException
  Testing/
    FakeTransport.php        scripted responses + recorded commands, for consumers' own tests
    UnexpectedCommandException.php, ScriptExhaustedException.php   extend \LogicException, not AgiThrowable
    PHPUnit/VerifiesFakeTransport.php   optional trait calling assertComplete() after each test
    AgiRequestFactory.php    builds an AgiRequest from an array, with sensible defaults
examples/
  hello-world.php, read-digits.php, variables.php, hangup-handling.php, testing-with-fake-transport.php
```

Key points:

- **Testability via `Transport`.** The library's own tests use an in-memory transport with scripted responses, which replaces the child-process fixtures. The constructor takes `?Transport $transport = null, ?LoggerInterface $logger = null` and reads the request header immediately.
- **Testing tools for consumers.** `Testing\FakeTransport` and `Testing\AgiRequestFactory` ship in the package, so users can test their AGI scripts against a real `Agi` instance with scripted Asterisk responses, without mocks and without Asterisk.
- **Complete interface.** `Agi` is `final`, and `AgiInterface` contains *every* public method. Any mocking library (PHPUnit, Mockery, Prophecy) can mock the interface; none can mock a final class. The interface is for type-hinting and test doubles, not for implementing; see [Backward-compatibility promise](#backward-compatibility-promise).
- **`AgiRequest`** has typed properties for the Asterisk 20 header keys (`channel`, `extension`, `context`, `priority`, `uniqueId`, `callerId`, `callerIdName`, `dnid`, `rdnis`, `language`, `type`, `accountCode`, `threadId`, `enhanced`, `network`, `networkScript`, …), `arguments: list<string>` from `agi_arg_N`, and `raw: array<string,string>` for anything else. Header parsing stops on EOF (fixes the constructor hang).
- **`AgiResponse`** has `code: ResultCode`, `result: ?int`, `data: string`, `extra: array<string,string>` (e.g. `endpos=…`), plus `isSuccess()` and `digit(): ?string`.
- **One command builder.** Every argument goes through one quote/escape routine (quotes, backslashes, newlines), instead of each method building strings by hand. That fixes a whole class of escaping bugs at once, and the public `command()` method uses the same path.
- **The `EXEC` option delimiter is always `,`** (Asterisk ≥ 20). No `option_delim` setting.

### Read timeouts (decided: none by default, opt-in available)

`StreamTransport` blocks on reads **without a timeout by default, on purpose**:
- AGI commands legitimately block for a long time: `EXEC Dial` lasts as long as the call, and `WAIT FOR DIGIT -1`, `RECORD FILE` and playback have no upper bound. Any default timeout would break valid calls.
- If Asterisk exits or the channel's AGI pipe closes, the read returns EOF, which throws `ConnectionException`. A script doesn't hang on a dead Asterisk, only on a live one that never answers, which is an Asterisk bug. There's at least one such path in Asterisk 20: `handle_speechcreate()` returns failure without sending a response when an allocation fails. It's only reachable through `command('SPEECH CREATE', …)`, but it shows the opt-in timeout below is worth having.

For that last case, `StreamTransport` takes an optional `?float $readTimeoutSeconds = null`, implemented with `stream_select()`. When it's set and expires, the read throws `ConnectionException` ("no response to <command> within N s"). The docs advise that callers who set it pick a value above their longest `Dial`/recording, or wrap only specific commands. `FakeTransport` has no timeouts; tests never wait.

### `FakeTransport` behavior

Consumers will build their test suites on it, so it must be strict and its failures must be easy to read.

- **A script of expectations.** `$fake->expect('GET VARIABLE "FOO"')->respond('200 result=1 (bar)')`, in order. Helpers cover the common cases: `respondSuccess()`, `respondFailure()`, `respondNotFound()`, `respondDeadChannel()` (511) and `disconnect()` (EOF).
- **`hangUp()` scripts what Asterisk really does.** `$fake->expect('STREAM FILE …')->hangUp()` answers the command with its failure value, then expects the `CHANNEL STATUS` probe and answers it with 511. After that, `FakeTransport` behaves like a dead channel: dead-safe commands are scripted as usual, and any other command must be scripted with `respondDeadChannel()`. For `RECORD FILE` and `RECEIVE CHAR`, `hangUp()` answers with their own `(hangup)` response instead, without a probe, exactly as Asterisk does. A separate `sendHangupLine()` covers FastAGI-style custom transports only.
- **The probe is never implicit.** A test that triggers a failure value without scripting the probe fails with `UnexpectedCommandException` naming `CHANNEL STATUS`, so the extra exchange is always visible in tests. Commands are matched exactly by default, and `expectMatching(callable|regex)` allows looser matching where needed.
- **An unexpected command fails loudly.** It throws `Testing\UnexpectedCommandException` with the step number, the **expected** and the **actual** command line on separate lines, and the remaining expectations.
- **A finished script fails loudly too.** A command after the last expectation throws `Testing\ScriptExhaustedException`, showing the actual command and the list of commands already consumed.
- **Leftover expectations are detectable.** `$fake->assertComplete()` throws when expectations weren't used. An optional PHPUnit trait (`Testing\PHPUnit\VerifiesFakeTransport`) calls it automatically after each test, while the core class stays independent of any test framework.
- **These exceptions don't implement `AgiThrowable`.** They extend `\LogicException`, so an application's `catch (AgiThrowable $e)` around its call flow can never swallow a test failure.
- **Inspection:** `$fake->sentCommands()` returns everything sent, for assertions that don't fit the script style.

### Logging

The library logs through the injected PSR-3 logger (`NullLogger` by default). It never logs at `error` or above: anything that bad is thrown, and the application decides how to log it, which avoids double logging.

| Level | What | Context keys |
|---|---|---|
| `debug` | every command sent, and every response received with its round-trip time | `command`, `code`, `result`, `data`, `durationMs`, `channel`, `uniqueId` |
| `debug` | the parsed request header, once, at construction | `request` (the `raw` header array) |
| `info` | the hangup, the first time it's detected (511, a `(hangup)` response, the probe, or a `HANGUP` line on a custom transport) | `channel`, `uniqueId`, `command`, `detectedBy` (`511`, `self-reported`, `probe`, `hangup-line`) |
| `debug` | the probe itself, like any command, plus a line saying why it was sent | `command` (the original), `result` |
| `warning` | protocol oddities the parser tolerates: stray blank lines, unknown `key=value` tokens, an unparseable `result` | `line`, `command` |

- **Messages are constant**, with variable data only in the context array, e.g. `'AGI command sent'` + `['command' => …]`. Log aggregation can group them, and PSR-3 placeholders (`{command}`) are used where a human-readable message helps.
- **Privacy:** debug logs include full command lines, so they can contain caller numbers or values stored with `databasePut()`/`setVariable()`. The docs say so. An optional `redact: callable(string $command): string` constructor argument lets applications mask arguments before they're logged.
- **`verbose()` is not logging.** It sends `VERBOSE` to the Asterisk console, and it's logged at debug like any other command.

## Public API (v3)

Every method's return type and failure behavior, following [Command failures](#command-failures). "Throws" below means `CommandFailedException`, unless the channel is gone, in which case it's `ChannelHungUpException`. For methods that aren't dead-safe, the library decides which of the two applies with the `CHANNEL STATUS` probe (see [Hangup handling](#hangup-handling-decided-channelhungupexception)). Methods marked **dead-safe** keep working after a hangup and never get a 511. Rules 1 and 2 (protocol errors, dead channel) apply to every method and aren't repeated per row. Timeouts are in milliseconds (`…Ms`) for AGI commands, and in seconds where the dialplan application itself uses seconds (`…Seconds`).

### Channel and dialplan flow

| AGI command | 2.x | v3 signature | On failure / "nothing" |
|---|---|---|---|
| `ANSWER` | `answer()` | `answer(): void` | throws on `-1` |
| `HANGUP` | `hangup($ch)` | `hangup(?string $channel = null): void` | own channel: never throws; a 511 (channel already gone) counts as success and sets `isHungUp()`. Named channel that doesn't exist (`-1`): throws |
| `EXEC Hangup(cause)` | `caused_hangup($c)` | `hangupWithCause(HangupCause $cause): void` | never throws (hanging up is the goal), sets `isHungUp()`; skips `exec()`'s `-1` probe, since `Hangup` always returns `-1`. **Dead-safe** (via `EXEC`) |
| `CHANNEL STATUS` | `channel_status($ch)` | `channelStatus(?string $channel = null): ?ChannelState` | `null` = no such channel (`-1`) |
| `EXEC` | `exec($app, $opts)` | `exec(string $app, string\|int ...$args): AgiResponse` | `-2` → `ApplicationNotFoundException`; `-1` → probe, and `ChannelHungUpException` on 511; any other result is the application's own and returned raw. **Dead-safe**: runs after a hangup. Arguments joined with `,` |
| `EXEC Goto` | `exec_goto($c,$e,$p)` | `execGoto(string $context, string $extension, string\|int $priority = 1): void` | throws on failure. Priority may be a label. **Dead-safe** (via `EXEC`): runs silently after a hangup |
| `GOSUB` | — | `gosub(string $context, string $extension, string\|int $priority, string ...$args): void` | throws on `-1` |
| `EXEC Dial` + `DIALSTATUS` | `exec_dial(...)` | `execDial(string $dialString, ?int $timeoutSeconds = null, string $options = ''): DialStatus` | reads `DIALSTATUS` after `Dial` finishes; a failed dial is a `DialStatus` value (`Busy`, `NoAnswer`, `Congestion`, `ChanUnavail`, …), not an exception. **When the caller hangs up an answered call**, `Dial` returns `-1` with `DIALSTATUS=ANSWER`: `execDial()` probes (setting `isHungUp()`) and still **returns** `DialStatus::Answer`. It never throws `ChannelHungUpException` itself |
| `SET CONTEXT` | `set_context` | `setContext(string $context): void` | Asterisk always answers `0` |
| `SET EXTENSION` | `set_extension` | `setExtension(string $extension): void` | always `0` |
| `SET PRIORITY` | `set_priority` | `setPriority(int\|string $priority): void` | label or number; throws if a label can't be resolved |
| `SET AUTOHANGUP` | `set_autohangup` | `setAutoHangup(int $seconds): void` | `0` disables; always `0` |

### Variables, caller ID and database

| AGI command | 2.x | v3 signature | On failure / "nothing" |
|---|---|---|---|
| `GET VARIABLE` | `get_variable($v, true)` | `getVariable(string $name): ?string` | `null` = not set |
| `GET FULL VARIABLE` | `get_fullvariable($v,$ch)` | `evaluateExpression(string $expression, ?string $channel = null): ?string` | `null` = empty or unset |
| `SET VARIABLE` | `set_variable` | `setVariable(string $name, string\|int\|float $value): void` | `null` is a `TypeError` by design |
| `EXEC Set(…,g)` | `set_global_var` / `set_var` | `setGlobalVariable(string $name, string\|int\|float $value): void` | throws on failure. Fixes the 2.x bug that sent `Set(...)` as a raw AGI command |
| `SET VARIABLE CHANNEL(language)` | `exec_setlanguage` | `setLanguage(string $language): void` | |
| `SET CALLERID` | `set_callerid` | `setCallerId(string $callerId): void` | always `1` |
| `DATABASE GET` | `database_get` | `databaseGet(string $family, string $key): ?string` | `null` = key doesn't exist |
| `DATABASE PUT` | `database_put` | `databasePut(string $family, string $key, string $value): void` | throws on `0` |
| `DATABASE DEL` | `database_del` | `databaseDel(string $family, string $key): bool` | `false` = nothing deleted. Asterisk reports "key not found" and "failed" both as `0`, so they can't be told apart; documented |
| `DATABASE DELTREE` | `database_deltree` | `databaseDelTree(string $family, ?string $keyTree = null): bool` | `false` = nothing deleted |

### Audio and DTMF

| AGI command | 2.x | v3 signature | On failure / "nothing" |
|---|---|---|---|
| `STREAM FILE` | `stream_file` | `streamFile(string $file, string $escapeDigits = '', int $offset = 0): PlaybackResult` | throws on `-1` (e.g. file not found). `PlaybackResult::$digit` is `null` when playback completed without a key |
| `CONTROL STREAM FILE` | — | `controlStreamFile(string $file, string $escapeDigits = '', int $skipMs = 3000, string $forwardKey = '#', string $rewindKey = '*', string $pauseKey = '', int $offsetMs = 0): PlaybackResult` | as `streamFile()` |
| `GET OPTION` | — | `getOption(string $file, string $escapeDigits, ?int $timeoutMs = null): PlaybackResult` | as `streamFile()`; `$digit` is `null` on timeout |
| `GET DATA` | `get_data` | `getData(string $file, ?int $timeoutMs = null, ?int $maxDigits = null): DtmfInput` | throws on `-1`. **Digits typed before a timeout are kept:** `DtmfInput::$digits` holds them (`''` if none) and `$timedOut` says whether the timeout ended input. Never `null` |
| `WAIT FOR DIGIT` | `wait_for_digit` | `waitForDigit(int $timeoutMs = -1): ?string` | `null` = timeout; throws on `-1` |
| `SAY DIGITS` | `say_digits` | `sayDigits(string $digits, string $escapeDigits = ''): ?string` | returns the key pressed, or `null`. `$digits` validated `[0-9*#]+` (keeps leading zeros) |
| `SAY NUMBER` | `say_number` | `sayNumber(int $number, string $escapeDigits = '', ?string $gender = null): ?string` | key or `null` |
| `SAY ALPHA` | `say_alpha` | `sayAlpha(string $text, string $escapeDigits = ''): ?string` | key or `null` |
| `SAY PHONETIC` | `say_phonetic` | `sayPhonetic(string $text, string $escapeDigits = ''): ?string` | key or `null` |
| `SAY TIME` | `say_time` | `sayTime(int $timestamp, string $escapeDigits = ''): ?string` | key or `null` |
| `SAY DATE` | — | `sayDate(int $timestamp, string $escapeDigits = ''): ?string` | key or `null` |
| `SAY DATETIME` | — | `sayDateTime(int $timestamp, string $escapeDigits = '', ?string $format = null, ?string $timezone = null): ?string` | key or `null` |
| `RECORD FILE` | `record_file` | `recordFile(string $file, string $format, string $escapeDigits = '', int $timeoutMs = -1, bool $beep = false, ?int $silenceSeconds = null, ?int $offset = null): RecordingResult` | throws on a recording error (`(randomerror)`, `(writefile)`, `(waitfor)`). A hangup during recording is **returned** (`endReason: Hangup`, from Asterisk's own `(hangup)`, no probe), not thrown. `endReason: Timeout` covers both the time limit and silence detection: Asterisk reports both as `(timeout)`, so they can't be told apart; documented |
| `SET MUSIC` | `set_music` | `setMusic(bool $enabled, string $class = ''): void` | always `0` |

### Text, TDD and logging

| AGI command | 2.x | v3 signature | On failure / "nothing" |
|---|---|---|---|
| `RECEIVE CHAR` | `receive_char` | `receiveChar(int $timeoutMs = 0): ?string` | `null` = timeout or channel can't receive text (Asterisk answers `0` for both). `-1` is always reported as `(hangup)` → `ChannelHungUpException`, no probe |
| `RECEIVE TEXT` | — | `receiveText(int $timeoutMs = 0): string` | throws on `-1` |
| `SEND TEXT` | `send_text` | `sendText(string $text): void` | throws on `-1`. A channel without text support also answers `0`, so "sent" can't be confirmed; documented |
| `SEND IMAGE` | `send_image` | `sendImage(string $image): void` | as `sendText()` |
| `TDD MODE` | `tdd_mode` | `tddMode(TddMode $mode): bool` | `false` = channel isn't TDD-capable (`0`); throws on `-1` |
| `VERBOSE` | `verbose` | `verbose(string $message, int $level = 1): void` | level 1–4; multi-line messages are split; no syslog side effect |
| `NOOP` | `noop` | `noop(string $message = ''): void` | |
| any | — | `command(string $name, string\|int ...$args): AgiResponse` | only rules 1 and 2 apply; the caller interprets `result` |

`SPEECH *` and `ASYNCAGI BREAK` get no dedicated methods in 3.0; they're available through `command()`.

### Not commands

| 2.x | v3 |
|---|---|
| `$agi->request['agi_*']` | `request(): AgiRequest` (`->channel`, `->extension`, `->arguments`, …) |
| — | `isHungUp(): bool` (what the library already knows; sends nothing) |
| — | `checkHangup(): bool` (sends the `CHANNEL STATUS` probe and updates `isHungUp()`) |
| `$res['result']`, `AGIRES_OK` | the typed return values above; `AgiResponse::isSuccess()` for `command()`/`exec()` |
| `AST_STATE_*` | `ChannelState` |
| `conlog()` | the injected PSR-3 logger |

`#[\NoDiscard]` goes on every method that only reads (`getVariable`, `evaluateExpression`, `databaseGet`, `channelStatus`, `request`, `isHungUp`).

Removed with no replacement: `menu`, `text_input`, `parse_callerid`, `text2wav`, `swift`, `say_punctuation`, all `fastpass_*`, `exec_absolutetimeout` (use `TIMEOUT(absolute)` via `setVariable`), `exec_enumlookup`, `exec_agi`, `which`, `make_folder`, `$agi->config`, `$agi->audio` (EAGI audio could come back later as an explicit `EagiAudio` class if there's demand), `new_AsteriskManager` and `AGI_AsteriskManager` (use an AMI client such as Pegamento/PAMI), FastAGI, `phpagi_error_handler`, all global `define()`s.

**To verify during implementation:** check every command's syntax, result values and failure values against the Asterisk 20 AGI command docs (docs.asterisk.org → Asterisk 20 → AGI Commands) and `res_agi.c`. In particular:
- the `DATABASE DELTREE` result;
- the `RECORD FILE` result format on hangup, silence and timeout, and its argument order;
- `RECEIVE CHAR` and `SEND TEXT` on channels without text support;
- `GET OPTION`, `CONTROL STREAM FILE` and `SAY ALPHA` case-sensitivity;
- ~~which commands are allowed on a dead channel~~ verified against Asterisk 20's `res_agi.c`; the list is in [Hangup handling](#hangup-handling-decided-channelhungupexception). The integration tests check it on 20 and 22, so a change in a later Asterisk version shows up as a failing test.

If a result differs from the table, the table is updated in the same PR.

## Backward-compatibility promise

SemVer applies to everything below. Minor releases add; only majors remove or change.

| Part | Promise |
|---|---|
| `Agi` public methods | signatures and documented behavior don't change in minors; new methods may be added |
| `AgiInterface` | **for type-hinting and test doubles, not for implementing.** Minor releases may add methods (a new Asterisk command, a new helper). Mocks generated by PHPUnit, Mockery or Prophecy are unaffected, because they're built from the interface at runtime. A hand-written class implementing it isn't covered by the BC promise. That's stated in the interface docblock, the README and the migration guide |
| `AbstractAgiDecorator` | **the supported way to wrap `Agi`** (logging, metrics, tracing, retries). It implements `AgiInterface` by forwarding every method to an inner instance. When a minor adds a method to the interface, it adds the forwarding method here too, so decorators that extend it keep working |
| `Transport` | **meant to be implemented** (custom I/O, FastAGI-style sockets in user land). Frozen within a major: no methods added in minors |
| Result objects (`AgiRequest`, `AgiResponse`, `PlaybackResult`, `DtmfInput`, `RecordingResult`) | properties aren't removed or renamed in minors; new properties may be added. Constructing them yourself is supported through `Testing\` factories, not their constructors |
| Enums | cases aren't removed or renamed in minors. **New cases may be added** (Asterisk adds hangup causes and dial statuses), so a `match` over a phpagi enum should have a `default` arm. Documented per enum |
| Exceptions | classes aren't removed, moved or re-parented in minors; new subclasses of existing ones may be added |
| `Testing\FakeTransport`, `Testing\AgiRequestFactory` | covered like `Agi`: they're part of the public API |
| `Protocol\*` | `@internal`, no promise |

Considered and rejected: splitting `AgiInterface` into small per-area interfaces (`ChannelControl`, `Variables`, `Playback`, …). It doesn't remove the problem, since each small interface still gains methods when Asterisk adds commands. It also makes type-hinting awkward for scripts that use several areas, which is nearly all of them.

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

- **Unit tests** for `ResponseParser` covering every response shape: `200 result=1 (data) endpos=123`, `200 result=-1`, `510 …`, `511 …`, the multi-line `520-…`/`520 End of proper usage.`, a stray blank line, the `(hangup)` markers of `RECORD FILE` and `RECEIVE CHAR`, the `HANGUP` line (custom transports only), and EOF.
- **Unit tests** for `HangupDetector`: the dead-safe list, when a probe is sent (non-dead-safe failure values and `exec()` `-1`) and when not (dead-safe commands, self-reported hangups), and both probe outcomes.
- **Unit tests** for `CommandBuilder` escaping: quotes, backslashes, newlines, empty strings, unicode.
- **Command tests** for every public method: exact bytes written and the parsed result, using `FakeTransport`. Each method is tested for success, its "nothing" value (`null`/`false`), its failure value followed by a probe that shows the channel is up (→ `CommandFailedException`), its failure value followed by a 511 on the probe (→ `ChannelHungUpException`), and a direct 511 where the command isn't dead-safe, exactly as listed in the [Public API](#public-api-v3) tables. Because the library tests itself with the same tool consumers get, `FakeTransport` stays complete and correct.
- **Migrate the 2.x characterization tests:** each still-relevant case is ported to v3 or deliberately dropped, recorded in the PR. The `known-bug` cases become normal tests asserting the fixed behavior.
- **Integration tests:** a small dialplan runs the `examples/` and a test AGI script against a Local channel on a real Asterisk, including a hangup mid-script. It covers the hangup behavior that the unit tests only simulate, on real Asterisk 20 and 22, with `AGISIGHUP=no`:
  - a hangup during `streamFile()` and `getData()` gives `ChannelHungUpException` via the probe;
  - a hangup during `recordFile()` returns `endReason: Hangup` with the file on disk;
  - a caller hanging up an answered `execDial()` returns `DialStatus::Answer`;
  - every command in the dead-safe list still works after the hangup, and a sample of the others answers 511.

  Built in implementation step 8, so it's in place before the first beta.
- **Coverage:** line coverage of at least 90% on `src/`, and 100% on `Protocol/`, enforced in CI.
- **Mutation testing** (Infection) on `Protocol/` and `Agi`, so the parser and command tests really pin behavior instead of only executing it.
- How all of this runs automatically is described in [Continuous integration](#continuous-integration-github-actions).

## Continuous integration (GitHub Actions)

Everything runs on GitHub Actions, on every push and pull request, plus a weekly scheduled run. Workflows live in `.github/workflows/`.

### PHP versions: always the latest patch releases

- The matrix targets **PHP 8.4.latest** and **PHP 8.5.latest**, via `shivammathur/setup-php` with `php-version: '8.4'` / `'8.5'`, which installs the newest available patch release of each line.
- **Version guard (warn-only):** a step compares the installed `PHP_VERSION` with the newest release that php.net publishes (`https://www.php.net/releases/?json&version=8.4`, and the same for 8.5). If CI runs an older patch, the step writes a GitHub warning annotation on the run. It **never fails a job**: php.net being slow or down, or setup-php trailing a release by a day or two, must not turn CI red on its own. The weekly scheduled run turns a lag of more than 7 days (php.net's JSON includes the release date) into a GitHub issue, so a persistent lag still gets noticed. A network error or unexpected response is logged and ignored.
- Each job prints `php -v` and the loaded extensions in its log, so every run records exactly which patch release it tested.
- The **weekly scheduled run** (Monday morning) catches new PHP patch releases and new dependency releases, even when nobody pushes. A failure opens or updates a GitHub issue automatically.
- **Next PHP version:** an allowed-to-fail job on the upcoming PHP version (nightly or RC builds) gives early warning before it's released. Once it's GA, it joins the required matrix (see [Support policy](#support-policy)).

### Workflows and jobs

| Workflow | Job | PHP | What it checks |
|---|---|---|---|
| `ci.yml` | **tests** | 8.4.latest, 8.5.latest | PHPUnit, full suite; deprecations, notices and warnings fail the run |
| | **tests-lowest** | 8.4.latest | `composer update --prefer-lowest --prefer-stable` and PHPUnit, proving the declared minimum dependency versions really work |
| | **static-analysis** | 8.4.latest, 8.5.latest | PHPStan max level, with `phpVersion` matching the job's PHP |
| | **code-style** | 8.5.latest | PHP-CS-Fixer or PHP_CodeSniffer (PSR-12) in check mode |
| | **coverage** | 8.5.latest | PHPUnit with PCOV, enforcing the coverage thresholds; coverage report uploaded as an artifact (optionally to Codecov) |
| | **composer** | 8.5.latest | `composer validate --strict`, `composer audit` (known vulnerabilities in dependencies), `composer normalize --dry-run` |
| | **rector-fixtures** | 8.5.latest | the 2.x → 3.x Rector rule set against its before/after fixture files (added in implementation step 7, when the rule set is built) |
| | **next-php** (allowed to fail) | nightly/RC | the PHPUnit suite on the upcoming PHP version |
| `integration.yml` | **asterisk** | 8.4.latest, 8.5.latest × Asterisk 20, 22 | starts our own Asterisk test image (see [Integration environment](#integration-environment)) with a test dialplan, runs `examples/` and the integration AGI scripts over real channels (including a hangup mid-script), and checks the expected results in the Asterisk log/CDR |
| `mutation.yml` | **infection** | 8.5.latest | Infection on `Protocol/` and `Agi`, with a minimum mutation score (MSI); weekly plus on PRs that touch `src/` |
| `release.yml` | **release** | 8.4.latest, 8.5.latest | on a `v*` tag: reruns the whole of `ci.yml` and `integration.yml`, checks that `CHANGELOG.md` has an entry for the tag, then creates the GitHub release with that entry. Packagist updates through its GitHub hook |

### Integration environment

There's no official Asterisk Docker image, so this is a real choice.

**Decision: build our own minimal test image** from a `Dockerfile` in the repository (`tests/integration/docker/`).
- It's based on `debian:bookworm-slim` and builds Asterisk from the official source tarballs on downloads.asterisk.org.
- Versions are pinned exactly (e.g. 20.x.y and 22.x.y) and verified against the published SHA-256 checksums.
- `menuselect` keeps only the modules the tests need: `res_agi`, `chan_local`, `app_*` used by the examples, and the `pbx_config` dialplan.
- A separate `asterisk-image.yml` workflow builds both versions and pushes them to GitHub Container Registry (`ghcr.io/pegamento/phpagi-asterisk:20`, `:22`), weekly and whenever the Dockerfile changes. `integration.yml` pulls the prebuilt image, so integration runs take seconds instead of a full Asterisk build.
- Dependabot-style bumps of the pinned Asterisk versions come as PRs, so a new Asterisk release is tested before it's trusted.

Considered and rejected:
- **Community images** (e.g. `andrius/asterisk`): convenient, but unofficial, with no guarantee of timely updates or of which modules are included. A third party's image in CI is a supply-chain dependency we don't control.
- **Distribution packages:** where Debian or Ubuntu package Asterisk at all, it's one version per release, trailing upstream, so there's no way to test 20 and 22 side by side at current patch levels.

The test dialplan, the AGI scripts and an `asterisk -rx`-based assertion helper live next to the Dockerfile. The same image is usable locally (`docker compose -f tests/integration/compose.yml up`) for contributors.

### Rules

- **Required checks:** branch protection on `master` requires every job above except `next-php` and the optional Codecov upload. Nothing merges with a red or skipped required job.
- **Fail fast off, concurrency on:** `fail-fast: false`, so one PHP version's failure doesn't hide the other's result; in-progress runs for the same branch are cancelled when a new push arrives.
- **Caching:** Composer's cache directory is cached per PHP version and `composer.json` hash, to keep runs fast without a committed `composer.lock`.
- **Dependabot** keeps GitHub Actions versions and Composer dev dependencies up to date, with weekly PRs that run through the same CI.
- **Pinned actions:** third-party actions are pinned to a full commit SHA, with the version in a comment (Dependabot updates them).
- **Minimal permissions:** workflows default to `permissions: contents: read`; only `release.yml` gets `contents: write`.
- **Status badges** for `ci.yml` and `integration.yml` in the README.

## Implementation steps

### Step 0: finish 2.x first (prerequisite, before any v3 work)

The order matters, because the `2.x` branch must start from the last 2.x release:

1. **Merge the PHPUnit work.** [Pegamento/phpagi#1](https://github.com/Pegamento/phpagi/pull/1) (`add-phpunit-tests`) is still open. Merge it into `master` once its review is done.
2. **Release 2.30.0** from `master`: the characterization tests, the PHP 8.4 floor and the rewritten README, still with the 2.x API. The 2.x changelog gets its entry, and the version jumps from 2.21.1 to 2.30.0 to mark the PHP floor change, with 3.0 reserved for this plan.
3. **Claim the Packagist name.** Check that `pegamento/phpagi` is still free (it was on 2026-10-29: `repo.packagist.org/p2/pegamento/phpagi.json` returned 404), then submit the repository to Packagist under the `pegamento` vendor account with 2.30.0 as the first published version. That claims the name now, gives 2.x users a normal `composer require`, and avoids a name collision surfacing only at the 3.0 release. Do the same check for `pegamento/pami`, which the migration guide points to.
4. **Create the `2.x` branch from the `2.30.0` tag.** From then on, 2.x hotfixes (2.30.x) land there.
5. **Create the `v3` branch from `master`.** All steps below happen there. `master` becomes 3.x when v3 is merged back for the beta.

### Steps 1–9: v3

Each step is one or more small commits on the `v3` branch, with tests passing at every commit.

1. **Skeleton:** `composer.json` (PSR-4, `psr/log`, `php: ^8.4`, so 8.5 is allowed), strict types, PHPStan config (`phpVersion` 8.4–8.5), code style config, PHPUnit strict-deprecation settings. GitHub Actions `ci.yml` with the 8.4.latest/8.5.latest matrix, the warn-only version guard, the weekly schedule, Dependabot and branch protection, from the very first commit, so every later step is tested automatically. Remove the 2.x sources from `v3` (they live on in `2.x`).
2. **Protocol core:** `Transport`, `StreamTransport` (with the optional read timeout), `FakeTransport` (with its strict failure behavior and the PHPUnit trait), `ResponseParser`, `CommandBuilder`, enums, exceptions, logging, plus tests.
3. **`AgiRequest` + `Agi` constructor:** header parsing, `agi_arg_N`, EOF handling, `AgiRequestFactory`.
4. **Commands:** grouped as channel/flow (answer, hangup, exec, goto, gosub, dial), variables/database, audio/DTMF (stream, record, say*, getData, waitForDigit), text/misc, and the generic `command()`. Return types and failure behavior follow the [Public API](#public-api-v3) tables and [Command failures](#command-failures), both settled before this step starts. Each group also adds its methods to `AbstractAgiDecorator`.
5. **Hangup handling:** `HangupDetector` (dead-safe list, probe, self-reported `(hangup)`, `HANGUP` line for custom transports), 511 → `ChannelHungUpException`, `isHungUp()`, `checkHangup()`, `FakeTransport::hangUp()` scripting the failure value plus the 511 probe, and the per-method exceptions (`hangup()`, `recordFile()`, `execDial()`). Tests for a hangup mid-command, dead-safe commands after a hangup, and `hangup()` followed by a command.
6. **Docs and examples:** README, `docs/` pages, `docs/UPGRADING-3.0.md`, `examples/`, CHANGELOG, CONTRIBUTING, SECURITY. Remove the old `docs/README.*`, `docs/README.phpagi-asmanager`, `docs/README.phpagi-fastagi` and `docs/fastagi.xinetd`.
7. **Rector rule set** (`rector/phpagi-3.php`): the rules, before/after fixture files per rule, the `rector-fixtures` CI job, and the "what Rector can't do" list in the migration guide. Scope is in [Migration guide](#migration-guide-docsupgrading-30md), item 9. It's built after step 4, because it needs the final API.
8. **Remaining CI:** the Asterisk test image and `asterisk-image.yml`, `integration.yml` (Asterisk 20 and 22), `mutation.yml` (Infection), the coverage thresholds, and `release.yml`.
9. **Release `3.0.0-beta.1`** (Packagist already has the package from step 0) and announce it. Validate it in real-world applications (Pegamento's own applications are the first adopters; their migrations are tracked in their own repositories), collect feedback, fix findings, then release `3.0.0`.

## Migration guide (`docs/UPGRADING-3.0.md`)

A standalone guide for any 2.x user. It's written alongside the implementation, and every API change in a v3 PR updates it. Contents:

1. **Requirements:** PHP 8.4+ (8.5 recommended), Asterisk ≥ 20, and the `composer require` line. 2.x stays available on the `2.x` branch for anyone who can't upgrade yet.
2. **AMI removed (prominent notice at the top of the guide):**
   > The Asterisk Manager Interface client (`AGI_AsteriskManager`, `$agi->new_AsteriskManager()`, `phpagi-asmanager.php` and the `[asmanager]` section of `phpagi.conf`) has been removed in 3.0. phpagi is now an AGI-only library. For AMI, use [Pegamento/PAMI](https://github.com/Pegamento/PAMI) (`composer require pegamento/pami`).

   Followed by a short table mapping the AMI actions 2.x users used most often (`Originate`, `Redirect`, `Hangup`, `GetVar`/`SetVar`, `QueueAdd`/`QueueRemove`, `Command`, event handlers) to their PAMI equivalents, with one before/after example. **To verify against the PAMI fork's actual class names when writing.**
3. **Other removals:** FastAGI/xinetd, TTS (`text2wav`, `swift`), fastpass helpers, `menu`, `text_input`, `parse_callerid`, `say_punctuation`, the global error handler and error emails, `phpagi.conf`, global constants. Each gets a one-line "do this instead".
4. **Construction and bootstrapping:** `include 'phpagi.php'; new AGI()` → autoloading plus `new Agi(logger: …)`; injecting a logger.
5. **Method mapping:** the full 2.x → 3.x table from [Public API (v3)](#public-api-v3), with the signature and return-type changes.
6. **Behavior changes to watch for:** failed commands throw `CommandFailedException` instead of returning `result=-1`, and "not found" is `null`/`false`; `getVariable()` returns `?string`, not an array; `getData()` returns a `DtmfInput` (digits typed before a timeout are kept); playback and say methods return the key pressed instead of an ASCII code; `sayDigits()` takes a string; `exec()` is variadic and always uses `,`; `setVariable()` rejects `null`; `setGlobalVariable()` now actually works; and hangup handling (`ChannelHungUpException`, `isHungUp()`/`checkHangup()`, the `AGISIGHUP=no` requirement with a dialplan example and a recommended top-level `try/catch`, the list of commands that keep running after a hangup, including everything built on `EXEC`, and the extra `CHANNEL STATUS` probe visible in Asterisk's AGI debug output).
7. **Request and responses:** `$agi->request['agi_*']` → `$agi->request()->…`; `$res['result']`/`AGIRES_OK` → `AgiResponse`; `AST_STATE_*` → `ChannelState`; hangup cause codes → `HangupCause`.
8. **Testing and wrapping your code:** use `FakeTransport` for realistic tests, or mock `AgiInterface` (not the final `Agi` class), and make sure mocks return values the real methods can return. To wrap `Agi`, extend `AbstractAgiDecorator` instead of implementing `AgiInterface` yourself, since the interface may gain methods in minor releases.
9. **Automated upgrade, with a deliberately modest scope.** A Rector rule set ships in the repository (`rector/phpagi-3.php`), tested against fixture files (built in implementation step 7). It does the purely mechanical work and marks the rest; it doesn't pretend to migrate everything.

   **Rewrites automatically** (only exact, unambiguous patterns):
   - method renames where the arguments carry over unchanged (`set_variable` → `setVariable`, `stream_file` → `streamFile`, …);
   - `$agi->get_variable($x, true)` → `$agi->getVariable($x)`, and the same for `get_fullvariable` → `evaluateExpression`, only when the second argument is the literal `true`;
   - `new AGI(...)` → `new Agi()`, dropping config arguments that no longer exist;
   - `$agi->request['agi_channel']` and the other known header keys → `$agi->request()->channel`;
   - `AST_STATE_*` → `ChannelState::*`, `AST_DIGIT_ANY` → a literal;
   - type hints `AGI` → `AgiInterface`.

   **Marks with a `// TODO(phpagi-3): …` comment, and never rewrites:**
   - array access on results: `$agi->get_variable($x)['data']`, `$res['result']`, `$res['data']`, including through variables;
   - `get_variable($x)` without `true`, and `get_variable($x, $flag)` with a non-literal flag;
   - comparisons with `AGIRES_OK` or `-1`, which now need `try/catch` or a typed check;
   - `exec()` calls whose option string contains `|`;
   - calls to removed methods (`menu`, `text2wav`, `fastpass_*`, `new_AsteriskManager`, …);
   - mocks and stubs of `AGI` in tests.

   The guide gives a manual recipe for each TODO type, and a `grep -rn 'TODO(phpagi-3)'` check to confirm none are left.

## Risks

| Risk | Mitigation |
|---|---|
| Subtle protocol differences on Asterisk 20/22 versus what the 2.x tests assumed | parser tests from real Asterisk output; the Docker integration job |
| A design that only fits the first adopters | review the API against the Asterisk 20 AGI command list rather than against any one application; the beta period with external feedback before 3.0.0 |
| 2.x users stuck on the old API | the `2.x` branch with a clear end-of-support date, the migration guide, the Rector rule set |
| Users who relied on the AMI client | a prominent notice in the README and migration guide pointing to Pegamento/PAMI |
| Pegamento/PAMI isn't on Packagist, so `composer require pegamento/pami` from the guide fails | publish Pegamento/PAMI on Packagist before 3.0.0, or document the VCS repository setup in the guide |
| The name `pegamento/phpagi` gets taken on Packagist before we publish | claim it in step 0 by publishing 2.30.0 there |
| A stuck (but alive) Asterisk blocks a script forever | the documented opt-in read timeout on `StreamTransport` |
| Users over-trust the Rector rule set | its scope is explicit, everything it can't do gets a `TODO(phpagi-3)` marker, and the guide has the grep to find them |
| A hangup exception escaping from hangup handlers in user code | document the dead-safe commands and the recommended entry-point `try/catch`; cover it in `examples/hangup-handling.php` |
| A call flow keeps running after a hangup because it only uses dead-safe commands (`exec()` chains, variables, database) | rule 4 probes `exec()` on `-1`; the docs list the dead-safe commands and recommend `checkHangup()` in such stretches; the integration tests pin the behavior |
| Asterisk changes its hangup signalling or the dead-safe list in a later version | the integration job runs on 20 and 22 and pins both behaviors; new Asterisk LTS versions are added to it |
