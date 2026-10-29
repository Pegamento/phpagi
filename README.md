# PHPAGI

[![Tests](https://github.com/Pegamento/phpagi/actions/workflows/tests.yml/badge.svg)](https://github.com/Pegamento/phpagi/actions/workflows/tests.yml)
[![License: LGPL v2.1+](https://img.shields.io/badge/license-LGPL--2.1--or--later-blue.svg)](COPYING)

PHP classes for building [Asterisk](https://www.asterisk.org/) telephony applications:

- **`AGI`**: control a call from a script started by the dialplan (Asterisk Gateway Interface).
- **`AGI_AsteriskManager`**: control Asterisk over TCP through the Asterisk Manager Interface (AMI).
- **FastAGI bootstrap**: serve AGI scripts over the network with xinetd.

This is Pegamento's maintained fork of [welltime/phpagi](https://github.com/welltime/phpagi),
which itself descends from the original [phpagi on SourceForge](http://phpagi.sourceforge.net/).

## Requirements

- PHP 8.4 or newer
- Asterisk with AGI and/or AMI enabled

## Installation

The package is installed from this GitHub repository, so add it as a Composer repository first:

```bash
composer config repositories.pegamento-phpagi vcs https://github.com/Pegamento/phpagi
```

```bash
composer require pegamento/phpagi
```

The classes are loaded through Composer's autoloader; no `require` of the source files is needed.

## Quick start

### An AGI script

Asterisk starts the script and talks to it over STDIN/STDOUT. The `AGI` constructor reads the
call's request variables, after which every method sends one AGI command:

```php
#!/usr/bin/env php
<?php
require __DIR__ . '/vendor/autoload.php';

$agi = new AGI();

$agi->answer();
$agi->stream_file('hello-world');

$res = $agi->get_data('enter-ext-of-person', 5000, 4); // prompt, timeout (ms), max digits
$agi->say_digits($res['result']);

$agi->caused_hangup(16); // hang up with cause 16 (normal clearing)
```

Call it from the dialplan (`extensions.conf`). Relative paths are looked up in Asterisk's `agi-bin`
directory, usually `/var/lib/asterisk/agi-bin`:

```ini
exten => 100,1,AGI(hello.php)
```

Request variables are available in `$agi->request`, for example `$agi->request['agi_callerid']`
or `$agi->request['agi_extension']`.

Commands return an array:

| Key      | Meaning                                                               |
|----------|-----------------------------------------------------------------------|
| `code`   | AGI status code; `200` on success                                     |
| `result` | The command's result, e.g. the digit pressed, or `-1` on failure      |
| `data`   | Extra text from Asterisk (the text in parentheses), or the error text |

Extra `key=value` values in the reply, such as `endpos`, are added as keys too.

### The Asterisk Manager

```php
$ami = new AGI_AsteriskManager(null, [
    'server'   => '127.0.0.1',
    'username' => 'phpagi',
    'secret'   => 'change-me',
]);

if (!$ami->connect()) {
    exit("Could not connect to the Asterisk Manager\n");
}

$res = $ami->Command('core show uptime');
echo $res['data'];

$ami->disconnect();
```

Inside an AGI script, `$agi->new_AsteriskManager()` creates a manager that shares the AGI's
configuration and logs to the Asterisk console.

Events can be handled with `add_event_handler('Hangup', 'my_function')`, or `'*'` for all events.

### FastAGI

`src/phpagi-fastagi.php` is a bootstrap that xinetd runs for each FastAGI connection. It creates
the AGI object as `$fastagi` and then includes the requested script from `[fastagi] basedir`.
Scripts must use that `$fastagi` instance and not create their own `AGI`.

```ini
exten => 100,1,AGI(agi://127.0.0.1/hello.php)
```

See [docs/README.phpagi-fastagi](docs/README.phpagi-fastagi) and
[docs/fastagi.xinetd](docs/fastagi.xinetd) for the xinetd setup.

## Configuration

Both classes read an ini file, by default `/etc/asterisk/phpagi.conf`, or the path given as the
first constructor argument. The second argument is an array of options that override the
`[phpagi]` section (for `AGI`) or the `[asmanager]` section (for `AGI_AsteriskManager`).

```php
$agi = new AGI('/path/to/phpagi.conf', ['debug' => true]);
```

| Section       | Key             | Default                    | Purpose                                                      |
|---------------|-----------------|----------------------------|--------------------------------------------------------------|
| `[phpagi]`    | `debug`         | `false`                    | Log internal messages to the Asterisk console                |
| `[phpagi]`    | `error_handler` | `true`                     | Install phpagi's PHP error handler and set `E_ALL` reporting |
| `[phpagi]`    | `admin`         | none                       | Email address the error handler mails errors to              |
| `[phpagi]`    | `tempdir`       | `/var/spool/asterisk/tmp/` | Where text-to-speech files are written                       |
| `[asmanager]` | `server`        | `localhost`                | Manager host; `host:port` is also accepted                   |
| `[asmanager]` | `port`          | `5038`                     | Manager port                                                 |
| `[asmanager]` | `username`      | `phpagi`                   | Manager username                                             |
| `[asmanager]` | `secret`        | `phpagi`                   | Manager password                                             |
| `[asmanager]` | `write_log`     | `false`                    | Write manager log messages with `error_log()`                |
| `[fastagi]`   | `basedir`       | the bootstrap's folder     | Folder FastAGI scripts are loaded from                       |
| `[fastagi]`   | `setuid`        | off                        | Drop privileges to the owner of the requested script         |
| `[festival]`  | `text2wave`     | found on `PATH`            | Festival binary used by `text2wav()`                         |
| `[cepstral]`  | `swift`         | found on `PATH`            | Cepstral binary used by `swift()`                            |
| `[cepstral]`  | `voice`         | none                       | Default Cepstral voice                                       |

[docs/phpagi.example.conf](docs/phpagi.example.conf) contains an example file.

> `error_handler` is on by default, so constructing an `AGI` replaces your application's PHP
> error handler. Pass `['error_handler' => false]` to keep your own.

## Security

- **Validate everything you pass to the Manager.** Values are written to the socket as-is, so a
  value containing `\r\n` can inject extra AMI actions. If Asterisk runs as root, the Manager can
  be used to run shell commands as root.
- **Keep the Manager secret out of your code** by putting it in the config file, and restrict who
  can read that file.
- **FastAGI has no authentication.** Bind it to `127.0.0.1` or a trusted network and use the
  xinetd access controls. Use `setuid` or a non-root xinetd user where possible.

See [docs/README.phpagi-asmanager](docs/README.phpagi-asmanager) for more detail.

## Development

```bash
composer install
composer test
```

The test suite pins down the library's current behaviour, as a safety net for the planned
modernization. CI runs it on PHP 8.4 and 8.5.

Bugs found while writing the tests are described in
[tests/KnownBugsTest.php](tests/KnownBugsTest.php). Those tests assert the intended behaviour,
fail today, and are excluded from the default run:

```bash
composer test:known-bugs
```

When you fix one, move its test into the regular suite.

## Further reading

- [docs/README.phpagi](docs/README.phpagi): the original AGI class notes
- [docs/README.phpagi-asmanager](docs/README.phpagi-asmanager): Manager class notes and security advice
- [docs/README.phpagi-fastagi](docs/README.phpagi-fastagi): FastAGI with xinetd
- [docs/CHANGELOG](docs/CHANGELOG): changes up to the welltime fork
- The method docblocks in [src/phpagi.php](src/phpagi.php) and
  [src/phpagi-asmanager.php](src/phpagi-asmanager.php)

## License

LGPL 2.1 or later. See [COPYING](COPYING).

Original authors: Matthew Asham and David Eder, with contributions from Florian Overkamp,
Roland Hu and others.
