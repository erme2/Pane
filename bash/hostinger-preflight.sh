#!/usr/bin/env bash

set -euo pipefail

ENV_FILE=".env"
CHECK_DATABASE="yes"

while getopts ":e:d:" opt
   do
     # shellcheck disable=SC2220
     case $opt in
        e ) ENV_FILE=$OPTARG;;
        d ) CHECK_DATABASE=$OPTARG;;
     esac
done

pass() {
    echo "PASS: $1"
}

fail() {
    echo "FAIL: $1" >&2
    exit 1
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || fail "$1 is not available"
    pass "$1 is available"
}

if [ ! -f "${ENV_FILE}" ]; then
    fail "${ENV_FILE} was not found"
fi

require_command php
require_command composer

php -r 'exit(version_compare(PHP_VERSION, "8.5.0", ">=") ? 0 : 1);' \
    || fail "PHP 8.5 or newer is required"
pass "PHP version is compatible"

php <<'PHP'
<?php

$requiredExtensions = [
    'ctype',
    'curl',
    'dom',
    'fileinfo',
    'filter',
    'hash',
    'mbstring',
    'openssl',
    'pcre',
    'PDO',
    'pdo_mysql',
    'session',
    'tokenizer',
    'xml',
];

foreach ($requiredExtensions as $extension) {
    if (! extension_loaded($extension)) {
        fwrite(STDERR, "FAIL: PHP extension {$extension} is not loaded\n");
        exit(1);
    }
}

echo "PASS: required PHP extensions are loaded\n";
PHP

php -- "${ENV_FILE}" "${CHECK_DATABASE}" <<'PHP'
<?php

[$script, $envFile, $checkDatabase] = $argv;

$values = [];
$lines = file($envFile, FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    fwrite(STDERR, "FAIL: environment file could not be read\n");
    exit(1);
}

foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
        continue;
    }

    [$key, $value] = explode('=', $line, 2);
    $key = trim($key);
    $value = trim($value);
    if (
        strlen($value) >= 2
        && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'"))
    ) {
        $value = substr($value, 1, -1);
    }

    $values[$key] = $value;
}

$fail = static function (string $message): void {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

$pass = static function (string $message): void {
    echo "PASS: {$message}\n";
};

$required = [
    'APP_ENV',
    'APP_KEY',
    'APP_DEBUG',
    'APP_URL',
    'SESSION_SECURE_COOKIE',
    'DB_CONNECTION',
    'DB_HOST',
    'DB_PORT',
    'DB_DATABASE',
    'DB_USERNAME',
    'DB_PASSWORD',
    'WORKOS_API_KEY',
    'WORKOS_CLIENT_ID',
    'FRONTEND_URL',
    'LATTE_REDIRECT_URIS',
    'WORKOS_REDIRECT_URI',
    'WORKOS_PROVIDER',
    'PANE_MANAGED_CREDENTIAL_ACTIVE_KEY_ID',
    'PANE_MANAGED_CREDENTIAL_KEYS',
];

foreach ($required as $key) {
    if (! array_key_exists($key, $values) || trim($values[$key]) === '') {
        $fail("{$key} must be set");
    }
}

$placeholderPrefixes = [
    'APP_KEY' => ['base64:old-32-byte-key-material', 'base64:new-32-byte-key-material'],
    'WORKOS_API_KEY' => ['sk_', 'sk_test_', 'sk_live_'],
    'WORKOS_CLIENT_ID' => ['client_'],
    'WORKOS_ORGANIZATION_ID' => ['org_'],
    'WORKOS_CONNECTION_ID' => ['conn_'],
    'DB_PASSWORD' => ['secret', 'password', 'changeme'],
    'PANE_MANAGED_CREDENTIAL_ACTIVE_KEY_ID' => ['2026-08-primary'],
];

foreach ($placeholderPrefixes as $key => $placeholders) {
    if (! array_key_exists($key, $values) || $values[$key] === '') {
        continue;
    }

    foreach ($placeholders as $placeholder) {
        if ($values[$key] === $placeholder) {
            $fail("{$key} still uses a placeholder value");
        }
    }
}

if ($values['APP_ENV'] !== 'production') {
    $fail('APP_ENV must be production');
}

if (strtolower($values['APP_DEBUG']) !== 'false') {
    $fail('APP_DEBUG must be false');
}

if (strtolower($values['SESSION_SECURE_COOKIE']) !== 'true') {
    $fail('SESSION_SECURE_COOKIE must be true');
}

if (! str_starts_with($values['APP_URL'], 'https://')) {
    $fail('APP_URL must use https');
}

if (! str_starts_with($values['FRONTEND_URL'], 'https://')) {
    $fail('FRONTEND_URL must use https');
}

$managedKeys = json_decode($values['PANE_MANAGED_CREDENTIAL_KEYS'], true);
if (! is_array($managedKeys) || $managedKeys === []) {
    $fail('PANE_MANAGED_CREDENTIAL_KEYS must be a non-empty JSON object');
}

if (! array_key_exists($values['PANE_MANAGED_CREDENTIAL_ACTIVE_KEY_ID'], $managedKeys)) {
    $fail('PANE_MANAGED_CREDENTIAL_ACTIVE_KEY_ID must exist in PANE_MANAGED_CREDENTIAL_KEYS');
}

foreach ($managedKeys as $keyId => $keyMaterial) {
    if (! is_string($keyId) || $keyId === '' || ! is_string($keyMaterial) || ! str_starts_with($keyMaterial, 'base64:')) {
        $fail('PANE_MANAGED_CREDENTIAL_KEYS must map key IDs to base64 keys');
    }
}

$pass('production environment values are present and safe to report by key name');

foreach (['storage', 'bootstrap/cache'] as $path) {
    if (! is_dir($path) || ! is_writable($path)) {
        $fail("{$path} must exist and be writable");
    }
}

$pass('Laravel writable directories are ready');

if ($checkDatabase !== 'yes') {
    $pass('database connectivity check skipped');
    exit(0);
}

$driverMap = [
    'mysql' => 'mysql',
    'mariadb' => 'mysql',
];

$connection = $values['DB_CONNECTION'];
if (! array_key_exists($connection, $driverMap)) {
    $fail("database connectivity check supports mysql or mariadb, not {$connection}");
}

if (! extension_loaded('pdo_mysql')) {
    $fail('PHP extension pdo_mysql is required for database connectivity');
}

$dsn = sprintf(
    '%s:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $driverMap[$connection],
    $values['DB_HOST'],
    $values['DB_PORT'],
    $values['DB_DATABASE']
);

try {
    new PDO($dsn, $values['DB_USERNAME'], $values['DB_PASSWORD'], [
        PDO::ATTR_TIMEOUT => 5,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (Throwable $exception) {
    $fail('database connectivity check failed');
}

$pass('database connectivity check passed');
PHP

php artisan config:clear --env=production >/dev/null
pass "Laravel configuration cache can be cleared"

composer check-platform-reqs --no-dev >/dev/null
pass "Composer production platform requirements are satisfied"
