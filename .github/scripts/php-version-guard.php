<?php

declare(strict_types=1);

/**
 * Compare the running PHP patch level with php.net's latest release for this minor line.
 * Always exits 0; CI interprets key=value lines on stdout.
 */
function emit(string $key, string $value): void
{
    echo $key . '=' . $value . "\n";
}

$installed = PHP_VERSION;
$versionParts = explode('.', PHP_VERSION);
if (count($versionParts) < 2) {
    emit('status', 'ignored');
    emit('installed', $installed);
    emit('message', 'Could not determine major.minor from PHP_VERSION');

    exit(0);
}

$majorMinor = $versionParts[0] . '.' . $versionParts[1];

$url = getenv('PHPAGI_PHP_RELEASES_URL');
if ($url === false || $url === '') {
    $url = 'https://www.php.net/releases/?json&version=' . rawurlencode($majorMinor);
}

$context = stream_context_create([
    'http' => [
        'timeout' => 15,
        'ignore_errors' => true,
    ],
]);

$response = @file_get_contents($url, false, $context);
if ($response === false) {
    emit('status', 'ignored');
    emit('installed', $installed);
    emit('message', 'Network error fetching PHP release data');

    exit(0);
}

$statusCode = 0;
if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches) === 1) {
    $statusCode = (int) $matches[1];
}

if ($statusCode !== 200) {
    emit('status', 'ignored');
    emit('installed', $installed);
    emit('message', 'Release endpoint returned HTTP ' . (string) $statusCode);

    exit(0);
}

/** @var mixed $payload */
$payload = json_decode($response, true);
if (
    ! is_array($payload)
    || ! isset($payload['version'], $payload['date'])
    || ! is_string($payload['version'])
    || ! is_string($payload['date'])
) {
    emit('status', 'ignored');
    emit('installed', $installed);
    emit('message', 'Unexpected JSON shape from PHP release endpoint');

    exit(0);
}

$latest = $payload['version'];
$releaseDateRaw = $payload['date'];

$releaseDate = DateTimeImmutable::createFromFormat('!j M Y', $releaseDateRaw);
if ($releaseDate === false) {
    emit('status', 'ignored');
    emit('installed', $installed);
    emit('message', 'Could not parse release date: ' . $releaseDateRaw);

    exit(0);
}

$today = new DateTimeImmutable('today');
$lagDays = (int) $releaseDate->diff($today)->days;

emit('installed', $installed);
emit('latest', $latest);
emit('release_date', $releaseDateRaw);
emit('lag_days', (string) $lagDays);

if (version_compare($installed, $latest, '<')) {
    emit(
        'warning',
        sprintf(
            'Installed PHP %s is behind php.net latest %s (released %s, %d day(s) ago).',
            $installed,
            $latest,
            $releaseDateRaw,
            $lagDays,
        ),
    );
    emit('status', 'behind');

    exit(0);
}

emit('status', 'ok');

exit(0);
