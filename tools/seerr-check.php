<?php

/*
 * Checks the Seerr add-on of the Wizarr extension for one customer, step by
 * step, and then runs the normal Seerr update for their subscriptions.
 *
 *   su -s /bin/bash www-data -c "php /opt/paywarr/tools/seerr-check.php /opt/paymenter customer@example.com"
 *
 * Never prints the API key.
 */

use App\Helpers\ExtensionHelper;
use App\Models\Server;
use App\Models\User;
use Paymenter\Extensions\Servers\Wizarr\SeerrClient;
use Paymenter\Extensions\Servers\Wizarr\Wizarr;

$root = rtrim($argv[1] ?? '/opt/paymenter', '/');
$email = $argv[2] ?? null;

if (!is_file($root . '/artisan') || !$email) {
    fwrite(STDERR, "Usage: php seerr-check.php /path/to/paymenter customer@email\n");
    exit(1);
}
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$step = function (string $label, callable $fn) {
    try {
        $result = $fn();
        echo '  OK    ' . $label . ': ' . $result . PHP_EOL;

        return $result;
    } catch (Throwable $e) {
        echo '  FAIL  ' . $label . ': ' . $e->getMessage() . PHP_EOL;

        return null;
    }
};

$server = Server::where('extension', 'Wizarr')->first();
$settings = ExtensionHelper::settingsToArray($server->settings);
$url = trim((string) ($settings['seerr_url'] ?? ''));
$key = trim((string) ($settings['seerr_api_key'] ?? ''));

echo PHP_EOL . "1. Seerr settings on the Wizarr server \"{$server->name}\"" . PHP_EOL;
echo '  ' . ($url === '' ? 'FAIL  ' : 'OK    ') . 'Seerr URL (internal): ' . ($url ?: 'missing') . PHP_EOL;
echo '  ' . ($key === '' ? 'FAIL  ' : 'OK    ') . 'Seerr API key: ' . ($key === '' ? 'missing' : '(filled in)') . PHP_EOL;
echo '  ' . (trim((string) ($settings['seerr_public_url'] ?? '')) === '' ? '-     ' : 'OK    ') . 'Seerr URL (public): ' . (trim((string) ($settings['seerr_public_url'] ?? '')) ?: 'missing (no button in the client area)') . PHP_EOL;
if ($url === '' || $key === '') {
    echo PHP_EOL . '  Fill in the Seerr fields and Save; nothing else can work before that.' . PHP_EOL . PHP_EOL;
    exit(1);
}

$seerr = new SeerrClient($url, $key);
echo PHP_EOL . '2. Seerr' . PHP_EOL;
$step('version (URL and key work)', fn () => $seerr->version());
$jellyfin = $step('Jellyfin users Seerr can see', fn () => count($seerr->mediaServerUsers()) . ' users');

echo PHP_EOL . "3. Customer $email" . PHP_EOL;
$user = User::where('email', $email)->first();
if (!$user) {
    echo '  FAIL  no Paymenter user with that e-mail' . PHP_EOL;
    exit(1);
}
$extension = new Wizarr($settings);

foreach ($user->services()->with(['product.settings', 'product.server'])->get() as $service) {
    if ($service->product?->server?->extension !== 'Wizarr') {
        continue;
    }
    $productSettings = ExtensionHelper::settingsToArray($service->product->settings);
    $properties = ExtensionHelper::getServiceProperties($service);

    echo PHP_EOL . '  Service ' . $service->id . ' (' . $service->product->name . '), status ' . $service->status . PHP_EOL;
    $option = $properties[Wizarr::SEERR_OPTION] ?? null;
    echo '  ' . ($option === null ? '-     ' : 'OK    ') . 'configurable option "' . Wizarr::SEERR_OPTION . '": '
        . ($option === null ? 'not on this service (environment variable of the option must be "seerr")' : '"' . $option . '"') . PHP_EOL;
    echo '  ' . (($productSettings['seerr_included'] ?? false) ? 'OK    ' : '-     ') . 'Seerr included in product: ' . (($productSettings['seerr_included'] ?? false) ? 'yes' : 'no') . PHP_EOL;
    $wants = $extension->wantsSeerr($productSettings, $properties);
    echo '  ' . ($wants ? 'OK    ' : 'FAIL  ') . 'gets Seerr: ' . ($wants ? 'yes' : 'NO - the chosen value\'s environment variable must be "yes" (or "ja")') . PHP_EOL;

    $users = (string) ($properties['wizarr_users'] ?? '');
    echo '  ' . ($users === '' ? 'FAIL  ' : 'OK    ') . 'media server user recorded: ' . ($users ?: 'none yet - has the customer created their user? Opening the subscription in the client area records it') . PHP_EOL;
    echo '  -     Seerr account managed by Paymenter: ' . (($properties['seerr_user_id'] ?? '') ?: 'none yet') . PHP_EOL;

    if ($wants && $service->status === 'active') {
        $step('run the Seerr update now', function () use ($extension, $service, $productSettings, $properties) {
            $extension->syncSeerr($service, $productSettings, $properties, 'active');

            return 'done';
        });
        $service->refresh();
        echo '  -     Seerr account managed by Paymenter now: ' . ($service->properties()->where('key', 'seerr_user_id')->value('value') ?: 'none') . PHP_EOL;
    }
}

$log = $root . '/storage/logs/laravel-' . date('Y-m-d') . '.log';
if (is_readable($log)) {
    $lines = array_filter(file($log), fn ($l) => str_contains($l, 'Wizarr') && stripos($l, 'seerr') !== false);
    echo PHP_EOL . '  Latest Seerr lines in the log:' . PHP_EOL;
    foreach (array_slice($lines, -3) as $line) {
        echo '  ' . trim(preg_replace('/^\[[^\]]+\] production\.\w+: /', '', $line)) . PHP_EOL;
    }
}
echo PHP_EOL;
