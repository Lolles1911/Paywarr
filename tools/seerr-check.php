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
echo PHP_EOL . "1. Seerr settings on the Wizarr server \"{$server->name}\"" . PHP_EOL;
$found = 0;
foreach (['1' => 'seerr_', '2' => 'seerr2_', '3' => 'seerr3_'] as $n => $prefix) {
    $name = $n === '1' ? 'Seerr' : 'Seerr ' . $n;
    $url = trim((string) ($settings[$prefix . 'url'] ?? ''));
    $key = trim((string) ($settings[$prefix . 'api_key'] ?? ''));
    if ($n !== '1' && $url === '' && $key === '') {
        continue; // optional and not used
    }
    echo '  ' . ($url === '' ? 'FAIL  ' : 'OK    ') . $name . ' URL (internal): ' . ($url ?: 'missing') . PHP_EOL;
    echo '  ' . ($key === '' ? 'FAIL  ' : 'OK    ') . $name . ' API key: ' . ($key === '' ? 'missing' : '(filled in)') . PHP_EOL;
    echo '  ' . (trim((string) ($settings[$prefix . 'public_url'] ?? '')) === '' ? '-     ' : 'OK    ') . $name . ' URL (public): ' . (trim((string) ($settings[$prefix . 'public_url'] ?? '')) ?: 'missing (no button in the client area)') . PHP_EOL;
    if ($url === '' || $key === '') {
        continue;
    }
    $found++;
    $seerr = new SeerrClient($url, $key);
    $step($name . ' version (URL and key work)', fn () => $seerr->version());
    $type = $step($name . ' is connected to', fn () => $seerr->mediaServerType() ?? 'NOTHING - connect it to a media server in Seerr');
    if ($type === 'plex') {
        $step($name . ': Plex users without a Seerr account yet', fn () => count($seerr->plexUsersToImport()) . ' users');
    } elseif ($type !== null) {
        $step($name . ': ' . $type . ' users it can see', fn () => count($seerr->mediaServerUsers()) . ' users');
    }
}
if (!$found) {
    echo PHP_EOL . '  Fill in the Seerr fields and Save; nothing else can work before that.' . PHP_EOL . PHP_EOL;
    exit(1);
}

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
    $option = Wizarr::requestsChoice($properties);
    echo '  ' . ($option === null ? '-     ' : 'OK    ') . 'configurable option "' . Wizarr::REQUESTS_OPTION . '": '
        . ($option === null ? 'not on this service (environment variable of the option must be "requests", or "seerr" as before)' : '"' . $option . '"') . PHP_EOL;
    echo '  ' . (($productSettings['seerr_included'] ?? false) ? 'OK    ' : '-     ') . 'Requests included in product: ' . (($productSettings['seerr_included'] ?? false) ? 'yes' : 'no') . PHP_EOL;
    $plan = $extension->seerrPlan($productSettings, $properties);
    $wants = $plan !== null;
    $limit = $plan === null ? '' : ($plan['limit'] === null ? ", Seerr's default request limits" : ($plan['limit'] === 0 ? ', unlimited requests' : ', ' . $plan['limit'] . ' requests every ' . $plan['days'] . ' days'));
    echo '  ' . ($wants ? 'OK    ' : 'FAIL  ') . 'gets Seerr: ' . ($wants ? 'yes' . $limit : 'NO - the chosen value\'s environment variable must be "yes", a package like "5" or "10/30", or "unlimited"') . PHP_EOL;

    // "wizarr_users", or "wizarr_users:<server ID>" per media server since 1.11.
    $recorded = [];
    foreach ($properties as $key => $value) {
        if (($key === 'wizarr_users' || str_starts_with((string) $key, 'wizarr_users:')) && (string) $value !== '') {
            $recorded[] = (string) $value;
        }
    }
    $users = implode(', ', $recorded);
    echo '  ' . ($users === '' ? 'FAIL  ' : 'OK    ') . 'media server user recorded: ' . ($users ?: 'none yet - has the customer created their user? Opening the subscription in the client area records it') . PHP_EOL;
    $instance = ($properties['seerr_instance'] ?? '') ?: '1';
    echo '  -     Seerr account managed by Paymenter: ' . (($properties['seerr_user_id'] ?? '') ? $properties['seerr_user_id'] . ($instance === '1' ? '' : ' (on Seerr ' . $instance . ')') : 'none yet') . PHP_EOL;

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
