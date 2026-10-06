<?php
// Editable key/value settings stored in the app_settings table.

function ckx_get_setting(PDO $pdo, string $name, string $default = ''): string
{
    try {
        $stmt = $pdo->prepare("SELECT value FROM app_settings WHERE name = ? LIMIT 1");
        $stmt->execute([$name]);
        $v = $stmt->fetchColumn();
        return $v !== false ? (string) $v : $default;
    } catch (Throwable $e) {
        // Table not present yet (pre-migration) — fall back to the default.
        return $default;
    }
}

function ckx_set_setting(PDO $pdo, string $name, string $value): void
{
    $pdo->prepare(
        "INSERT INTO app_settings (name, value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value)"
    )->execute([$name, $value]);
}

// Effective admin account: DB-stored values fall back to the .env defaults.
function ckx_admin_account(PDO $pdo, array $cfg): array
{
    return [
        'name'          => ckx_get_setting($pdo, 'admin_name', $cfg['name'] ?? 'Owner'),
        'email'         => ckx_get_setting($pdo, 'admin_email', $cfg['email'] ?? ''),
        'password_hash' => ckx_get_setting($pdo, 'admin_password_hash', $cfg['password_hash'] ?? ''),
    ];
}
