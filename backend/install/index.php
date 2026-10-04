<?php

/**
 * Kaneas web installer.
 *
 * Creates the database tables, the first admin account and api/config/config.php,
 * optionally stores SMTP settings, and points the SPA at the folder it lives in
 * (works in a sub folder on shared hosting). Locks itself when done.
 */

declare(strict_types=1);

require __DIR__ . '/../api/src/bootstrap.php';
require __DIR__ . '/Installer.php';

use Kaneas\Core\Config;
use Kaneas\Core\Database;
use Kaneas\Install\InstallException;
use Kaneas\Install\Installer;

session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Strict']);
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

// ---------------------------------------------------------------- i18n

$lang = ($_GET['lang'] ?? $_POST['lang'] ?? 'nl') === 'en' ? 'en' : 'nl';

$T = [
    'nl' => [
        'title' => 'Kaneas installatie',
        'intro' => 'Vul de gegevens hieronder in om Kaneas te installeren.',
        'requirements' => 'Systeemvereisten',
        'database' => 'Database',
        'db_host' => 'Host', 'db_port' => 'Poort', 'db_name' => 'Databasenaam', 'db_user' => 'Gebruiker', 'db_pass' => 'Wachtwoord',
        'db_prefix' => 'Tabelprefix', 'db_prefix_help' => 'Handig als de database gedeeld wordt. Alleen letters, cijfers en _.',
        'admin' => 'Beheerdersaccount',
        'admin_name' => 'Naam', 'admin_email' => 'E-mailadres', 'admin_password' => 'Wachtwoord', 'admin_password2' => 'Herhaal wachtwoord',
        'password_help' => 'Minimaal 8 tekens.',
        'mail' => 'E-mail (optioneel)',
        'mail_help' => 'Wordt gebruikt voor uitnodigingen. Je kunt dit ook later instellen in het beheerpaneel.',
        'mail_enabled' => 'E-mail versturen inschakelen',
        'mail_host' => 'SMTP-server', 'mail_port' => 'Poort', 'mail_encryption' => 'Beveiliging',
        'mail_username' => 'Gebruikersnaam', 'mail_password' => 'Wachtwoord',
        'mail_from_address' => 'Afzenderadres', 'mail_from_name' => 'Afzendernaam',
        'enc_tls' => 'STARTTLS (poort 587)', 'enc_ssl' => 'SSL/TLS (poort 465)', 'enc_none' => 'Geen',
        'app' => 'Applicatie',
        'allow_registration' => 'Iedereen mag een account aanmaken',
        'install' => 'Installeren',
        'installed_title' => 'Installatie voltooid',
        'installed_text' => 'Kaneas is geïnstalleerd. De installer is nu vergrendeld. Verwijder voor de zekerheid de map "install" van je server.',
        'open_app' => 'Open Kaneas',
        'already_installed' => 'Kaneas is al geïnstalleerd. Verwijder api/config/config.php om opnieuw te installeren.',
        'fix_requirements' => 'Los eerst de bovenstaande problemen op.',
        'err_csrf' => 'De sessie is verlopen. Probeer het opnieuw.',
        'err_required' => 'Verplicht veld.',
        'err_email' => 'Ongeldig e-mailadres.',
        'err_password' => 'Minimaal 8 tekens (max. 72 bytes).',
        'err_password_match' => 'Wachtwoorden komen niet overeen.',
        'err_prefix' => 'Alleen letters, cijfers en _ (max. 20).',
        'err_port' => 'Ongeldige poort.',
        'err_db' => 'Kan geen verbinding maken met de database: %s',
        'err_exists' => 'Er bestaan al Kaneas-tabellen met prefix "%s". Kies een andere prefix of leeg de database.',
        'err_install' => 'Installatie mislukt: %s',
        'err_write' => 'Kan api/config/config.php niet schrijven. Controleer de schrijfrechten.',
        'req_php' => 'PHP %s of hoger (huidig: %s)',
        'req_ext' => 'PHP-extensie "%s"',
        'req_writable' => 'Map api/config is schrijfbaar',
        'req_schema' => 'install/schema.sql aanwezig',
        'location' => 'Installatielocatie',
    ],
    'en' => [
        'title' => 'Kaneas installation',
        'intro' => 'Fill in the details below to install Kaneas.',
        'requirements' => 'System requirements',
        'database' => 'Database',
        'db_host' => 'Host', 'db_port' => 'Port', 'db_name' => 'Database name', 'db_user' => 'User', 'db_pass' => 'Password',
        'db_prefix' => 'Table prefix', 'db_prefix_help' => 'Useful when the database is shared. Letters, digits and _ only.',
        'admin' => 'Administrator account',
        'admin_name' => 'Name', 'admin_email' => 'Email address', 'admin_password' => 'Password', 'admin_password2' => 'Repeat password',
        'password_help' => 'At least 8 characters.',
        'mail' => 'Email (optional)',
        'mail_help' => 'Used for invitations. You can also configure this later in the admin panel.',
        'mail_enabled' => 'Enable sending email',
        'mail_host' => 'SMTP server', 'mail_port' => 'Port', 'mail_encryption' => 'Security',
        'mail_username' => 'Username', 'mail_password' => 'Password',
        'mail_from_address' => 'From address', 'mail_from_name' => 'From name',
        'enc_tls' => 'STARTTLS (port 587)', 'enc_ssl' => 'SSL/TLS (port 465)', 'enc_none' => 'None',
        'app' => 'Application',
        'allow_registration' => 'Anyone may create an account',
        'install' => 'Install',
        'installed_title' => 'Installation complete',
        'installed_text' => 'Kaneas has been installed. The installer is now locked. To be safe, delete the "install" folder from your server.',
        'open_app' => 'Open Kaneas',
        'already_installed' => 'Kaneas is already installed. Remove api/config/config.php to install again.',
        'fix_requirements' => 'Please fix the problems above first.',
        'err_csrf' => 'Your session expired. Please try again.',
        'err_required' => 'Required field.',
        'err_email' => 'Invalid email address.',
        'err_password' => 'At least 8 characters (max. 72 bytes).',
        'err_password_match' => 'Passwords do not match.',
        'err_prefix' => 'Letters, digits and _ only (max. 20).',
        'err_port' => 'Invalid port.',
        'err_db' => 'Could not connect to the database: %s',
        'err_exists' => 'Kaneas tables with prefix "%s" already exist. Choose another prefix or empty the database.',
        'err_install' => 'Installation failed: %s',
        'err_write' => 'Could not write api/config/config.php. Check the file permissions.',
        'req_php' => 'PHP %s or newer (current: %s)',
        'req_ext' => 'PHP extension "%s"',
        'req_writable' => 'Folder api/config is writable',
        'req_schema' => 'install/schema.sql present',
        'location' => 'Install location',
    ],
][$lang];

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---------------------------------------------------------------- paths

$appDir = dirname(__DIR__);
$configDir = $appDir . '/api/config';

$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/install/index.php'));
$basePath = rtrim(str_replace('\\', '/', dirname($scriptDir)), '/') . '/';
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int) ($_SERVER['SERVER_PORT'] ?? 80) === 443;
$host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
$appUrl = ($https ? 'https' : 'http') . '://' . $host . $basePath;

// ---------------------------------------------------------------- requirements

$requirements = [
    [sprintf($T['req_php'], KANEAS_MIN_PHP, PHP_VERSION), version_compare(PHP_VERSION, KANEAS_MIN_PHP, '>=')],
];
foreach (['pdo_mysql', 'openssl', 'mbstring', 'json'] as $ext) {
    $requirements[] = [sprintf($T['req_ext'], $ext), extension_loaded($ext)];
}
$requirements[] = [$T['req_writable'], is_dir($configDir) && is_writable($configDir)];
$requirements[] = [$T['req_schema'], is_file(__DIR__ . '/schema.sql')];
$requirementsOk = !in_array(false, array_column($requirements, 1), true);

// ---------------------------------------------------------------- state

$installed = Config::isInstalled();
$done = false;
$errors = [];
$generalError = null;

$devDefault = static fn (string $name, string $fallback): string => (string) (getenv('KANEAS_DEV_' . $name) ?: $fallback);
$values = [
    'db_host' => $devDefault('DB_HOST', 'localhost'),
    'db_port' => $devDefault('DB_PORT', '3306'),
    'db_name' => $devDefault('DB_NAME', ''),
    'db_user' => $devDefault('DB_USER', ''),
    'db_pass' => $devDefault('DB_PASS', ''),
    'db_prefix' => 'kb_',
    'admin_name' => '',
    'admin_email' => '',
    'mail_enabled' => '',
    'mail_host' => '',
    'mail_port' => '587',
    'mail_encryption' => 'tls',
    'mail_username' => '',
    'mail_from_address' => '',
    'mail_from_name' => 'Kaneas',
    'allow_registration' => '1',
];

$_SESSION['kaneas_install_csrf'] ??= bin2hex(random_bytes(32));
$csrf = $_SESSION['kaneas_install_csrf'];

// ---------------------------------------------------------------- install

if (!$installed && $requirementsOk && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    foreach ($values as $key => $_) {
        $values[$key] = isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : '';
    }
    // Passwords are taken verbatim.
    $dbPass = is_string($_POST['db_pass'] ?? null) ? $_POST['db_pass'] : '';
    $adminPassword = is_string($_POST['admin_password'] ?? null) ? $_POST['admin_password'] : '';
    $adminPassword2 = is_string($_POST['admin_password2'] ?? null) ? $_POST['admin_password2'] : '';
    $mailPassword = is_string($_POST['mail_password'] ?? null) ? $_POST['mail_password'] : '';
    $values['db_pass'] = $dbPass;

    if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
        $generalError = $T['err_csrf'];
    } else {
        foreach (['db_host', 'db_name', 'db_user', 'admin_name', 'admin_email'] as $required) {
            if ($values[$required] === '') {
                $errors[$required] = $T['err_required'];
            }
        }
        if (!ctype_digit($values['db_port']) || (int) $values['db_port'] < 1 || (int) $values['db_port'] > 65535) {
            $errors['db_port'] = $T['err_port'];
        }
        if (!Database::isValidPrefix($values['db_prefix'])) {
            $errors['db_prefix'] = $T['err_prefix'];
        }
        if ($values['admin_email'] !== '' && !filter_var($values['admin_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['admin_email'] = $T['err_email'];
        }
        if (mb_strlen($adminPassword) < 8 || strlen($adminPassword) > 72) {
            $errors['admin_password'] = $T['err_password'];
        } elseif ($adminPassword !== $adminPassword2) {
            $errors['admin_password2'] = $T['err_password_match'];
        }
        if ($values['mail_enabled'] === '1') {
            foreach (['mail_host', 'mail_from_address'] as $required) {
                if ($values[$required] === '') {
                    $errors[$required] = $T['err_required'];
                }
            }
            if (!ctype_digit($values['mail_port']) || (int) $values['mail_port'] < 1 || (int) $values['mail_port'] > 65535) {
                $errors['mail_port'] = $T['err_port'];
            }
        }
        if ($values['mail_from_address'] !== '' && !filter_var($values['mail_from_address'], FILTER_VALIDATE_EMAIL)) {
            $errors['mail_from_address'] = $T['err_email'];
        }

        if (!$errors) {
            $generalError = runInstall($values, $dbPass, $adminPassword, $mailPassword, $appDir, $basePath, $appUrl, $T);
            $done = $generalError === null;
            if ($done) {
                unset($_SESSION['kaneas_install_csrf']);
            }
        }
    }
}

/** @return string|null error message, null on success */
function runInstall(array $v, string $dbPass, string $adminPassword, string $mailPassword, string $appDir, string $basePath, string $appUrl, array $T): ?string
{
    $installer = new Installer($appDir);
    try {
        $installer->install([
            'db' => [
                'host' => $v['db_host'],
                'port' => (int) $v['db_port'],
                'name' => $v['db_name'],
                'user' => $v['db_user'],
                'pass' => $dbPass,
                'prefix' => $v['db_prefix'],
            ],
            'admin' => [
                'name' => $v['admin_name'],
                'email' => $v['admin_email'],
                'password' => $adminPassword,
                'locale' => $GLOBALS['lang'],
            ],
            'mail' => [
                'enabled' => $v['mail_enabled'] === '1',
                'host' => $v['mail_host'],
                'port' => (int) ($v['mail_port'] ?: 587),
                'encryption' => in_array($v['mail_encryption'], ['tls', 'ssl', 'none'], true) ? $v['mail_encryption'] : 'tls',
                'username' => $v['mail_username'],
                'password' => $mailPassword,
                'from_address' => $v['mail_from_address'],
                'from_name' => $v['mail_from_name'] ?: 'Kaneas',
            ],
            'allow_registration' => $v['allow_registration'] === '1',
            'app_url' => $appUrl,
            'base_path' => $basePath,
        ]);
    } catch (InstallException $e) {
        return match ($e->reason) {
            'db' => sprintf($T['err_db'], $e->getMessage()),
            'exists' => sprintf($T['err_exists'], $v['db_prefix']),
            'write' => $T['err_write'],
            default => sprintf($T['err_install'], $e->getMessage()),
        };
    }
    $installer->lock();
    return null;
}

// ---------------------------------------------------------------- view

$field = static function (string $name, string $type = 'text', array $attrs = []) use ($T, $values, $errors): string {
    $attrString = '';
    foreach ($attrs as $k => $v) {
        $attrString .= ' ' . $k . '="' . h($v) . '"';
    }
    $value = in_array($type, ['password'], true) ? '' : ($values[$name] ?? '');
    $error = isset($errors[$name]) ? '<span class="error">' . h($errors[$name]) . '</span>' : '';
    return '<label><span>' . h($T[$name]) . '</span><input type="' . $type . '" name="' . $name . '" value="' . h($value) . '"' . $attrString . '>' . $error . '</label>';
};
?>
<!doctype html>
<html lang="<?= $lang ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title><?= h($T['title']) ?></title>
  <style>
    :root { --bg:#f4f5f7; --card:#fff; --text:#172b4d; --muted:#5e6c84; --accent:#2563eb; --ok:#16a34a; --bad:#dc2626; --border:#dfe1e6; }
    * { box-sizing: border-box; }
    body { margin:0; font:15px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background:var(--bg); color:var(--text); }
    main { max-width: 720px; margin: 0 auto; padding: 32px 16px 64px; }
    header { display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; }
    h1 { font-size: 26px; margin: 0; }
    .lang a { color:var(--muted); text-decoration:none; margin-left:8px; } .lang a.active { color:var(--accent); font-weight:600; }
    section { background:var(--card); border:1px solid var(--border); border-radius:10px; padding:20px; margin-top:16px; }
    h2 { font-size:17px; margin:0 0 12px; }
    .grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px 16px; }
    label { display:flex; flex-direction:column; gap:4px; font-size:13px; color:var(--muted); }
    label.check { flex-direction:row; align-items:center; gap:8px; color:var(--text); font-size:15px; }
    input, select { font:inherit; padding:8px 10px; border:1px solid var(--border); border-radius:6px; color:var(--text); background:#fff; }
    input:focus, select:focus { outline:2px solid var(--accent); outline-offset:-1px; }
    .help { font-size:13px; color:var(--muted); margin:0 0 12px; }
    .error { color:var(--bad); font-size:12px; }
    .alert { background:#fef2f2; color:var(--bad); border:1px solid #fecaca; padding:12px 16px; border-radius:8px; margin-top:16px; }
    .success { background:#f0fdf4; color:#166534; border-color:#bbf7d0; }
    ul.req { list-style:none; padding:0; margin:0; } ul.req li::before { content:"✔ "; color:var(--ok); } ul.req li.fail::before { content:"✘ "; color:var(--bad); }
    button, .button { display:inline-block; margin-top:20px; background:var(--accent); color:#fff; border:0; padding:10px 22px; border-radius:6px; font:inherit; font-weight:600; cursor:pointer; text-decoration:none; }
    code { background:#eef0f3; padding:1px 6px; border-radius:4px; }
  </style>
</head>
<body>
<main>
  <header>
    <h1><?= h($T['title']) ?></h1>
    <nav class="lang"><a href="?lang=nl" class="<?= $lang === 'nl' ? 'active' : '' ?>">NL</a><a href="?lang=en" class="<?= $lang === 'en' ? 'active' : '' ?>">EN</a></nav>
  </header>

<?php if ($done): ?>
  <section class="success">
    <h2><?= h($T['installed_title']) ?></h2>
    <p><?= h($T['installed_text']) ?></p>
    <a class="button" href="<?= h($basePath) ?>"><?= h($T['open_app']) ?></a>
  </section>
<?php elseif ($installed): ?>
  <div class="alert"><?= h($T['already_installed']) ?></div>
<?php else: ?>
  <p class="help"><?= h($T['intro']) ?> <?= h($T['location']) ?>: <code><?= h($appUrl) ?></code></p>

  <section>
    <h2><?= h($T['requirements']) ?></h2>
    <ul class="req">
      <?php foreach ($requirements as [$label, $ok]): ?>
        <li class="<?= $ok ? '' : 'fail' ?>"><?= h($label) ?></li>
      <?php endforeach; ?>
    </ul>
  </section>

  <?php if (!$requirementsOk): ?>
    <div class="alert"><?= h($T['fix_requirements']) ?></div>
  <?php else: ?>
    <?php if ($generalError): ?><div class="alert"><?= h($generalError) ?></div><?php endif; ?>

    <form method="post" action="?lang=<?= $lang ?>" autocomplete="off" novalidate>
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="lang" value="<?= $lang ?>">

      <section>
        <h2><?= h($T['database']) ?></h2>
        <div class="grid">
          <?= $field('db_host', 'text', ['required' => 'required']) ?>
          <?= $field('db_port', 'number', ['min' => '1', 'max' => '65535']) ?>
          <?= $field('db_name', 'text', ['required' => 'required']) ?>
          <?= $field('db_user', 'text', ['required' => 'required']) ?>
          <label><span><?= h($T['db_pass']) ?></span><input type="password" name="db_pass" value="<?= h($values['db_pass']) ?>"></label>
          <?= $field('db_prefix', 'text', ['maxlength' => '20', 'pattern' => '[A-Za-z0-9_]*']) ?>
        </div>
        <p class="help" style="margin:8px 0 0"><?= h($T['db_prefix_help']) ?></p>
      </section>

      <section>
        <h2><?= h($T['admin']) ?></h2>
        <div class="grid">
          <?= $field('admin_name', 'text', ['required' => 'required', 'maxlength' => '100', 'autocomplete' => 'name']) ?>
          <?= $field('admin_email', 'email', ['required' => 'required', 'autocomplete' => 'email']) ?>
          <?= $field('admin_password', 'password', ['required' => 'required', 'minlength' => '8', 'autocomplete' => 'new-password']) ?>
          <?= $field('admin_password2', 'password', ['required' => 'required', 'minlength' => '8', 'autocomplete' => 'new-password']) ?>
        </div>
        <p class="help" style="margin:8px 0 0"><?= h($T['password_help']) ?></p>
      </section>

      <section>
        <h2><?= h($T['mail']) ?></h2>
        <p class="help"><?= h($T['mail_help']) ?></p>
        <label class="check"><input type="checkbox" name="mail_enabled" value="1" <?= $values['mail_enabled'] === '1' ? 'checked' : '' ?>> <?= h($T['mail_enabled']) ?></label>
        <div class="grid" style="margin-top:12px">
          <?= $field('mail_host') ?>
          <?= $field('mail_port', 'number', ['min' => '1', 'max' => '65535']) ?>
          <label><span><?= h($T['mail_encryption']) ?></span>
            <select name="mail_encryption">
              <?php foreach (['tls', 'ssl', 'none'] as $enc): ?>
                <option value="<?= $enc ?>" <?= $values['mail_encryption'] === $enc ? 'selected' : '' ?>><?= h($T['enc_' . $enc]) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <?= $field('mail_username', 'text', ['autocomplete' => 'off']) ?>
          <?= $field('mail_password', 'password', ['autocomplete' => 'new-password']) ?>
          <?= $field('mail_from_address', 'email') ?>
          <?= $field('mail_from_name') ?>
        </div>
      </section>

      <section>
        <h2><?= h($T['app']) ?></h2>
        <input type="hidden" name="allow_registration" value="0">
        <label class="check"><input type="checkbox" name="allow_registration" value="1" <?= $values['allow_registration'] === '1' ? 'checked' : '' ?>> <?= h($T['allow_registration']) ?></label>
      </section>

      <button type="submit"><?= h($T['install']) ?></button>
    </form>
  <?php endif; ?>
<?php endif; ?>
</main>
</body>
</html>
