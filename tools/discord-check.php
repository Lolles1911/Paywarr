<?php

/*
 * Checks the Discord setup of the Wizarr extension, one step at a time, and
 * shows Discord's own answers. Changes nothing, except step 6 when an e-mail
 * is given: that runs the normal role update for that customer.
 *
 *   su -s /bin/bash www-data -c "php /opt/paywarr/tools/discord-check.php /opt/paymenter customer@example.com"
 *
 * The e-mail is optional. Never prints the bot token or client secret.
 */

use App\Helpers\ExtensionHelper;
use App\Models\Product;
use App\Models\Server;
use App\Models\User;
use Paymenter\Extensions\Servers\Wizarr\DiscordClient;
use Paymenter\Extensions\Servers\Wizarr\Wizarr;

$root = rtrim($argv[1] ?? '/opt/paymenter', '/');
$email = $argv[2] ?? null;

if (!is_file($root . '/artisan')) {
    fwrite(STDERR, "No Paymenter in $root. Usage: php discord-check.php /path/to/paymenter [customer@email]\n");
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
if (!$server) {
    fwrite(STDERR, "No Wizarr server in Paymenter (Admin -> Servers).\n");
    exit(1);
}
$settings = ExtensionHelper::settingsToArray($server->settings);
$guild = trim((string) ($settings['discord_guild_id'] ?? ''));
$discord = new DiscordClient(trim((string) ($settings['discord_bot_token'] ?? '')));

echo PHP_EOL . "1. Settings on the Wizarr server \"{$server->name}\"" . PHP_EOL;
foreach (['discord_guild_id' => 'Discord server ID', 'discord_client_id' => 'Client ID', 'discord_client_secret' => 'Client Secret', 'discord_bot_token' => 'Bot token'] as $key => $label) {
    $value = trim((string) ($settings[$key] ?? ''));
    $shown = in_array($key, ['discord_client_secret', 'discord_bot_token'], true) ? ($value === '' ? '' : '(filled in, ' . strlen($value) . ' chars)') : $value;
    echo '  ' . ($value === '' ? 'FAIL  ' : 'OK    ') . $label . ': ' . ($shown === '' ? 'missing' : $shown) . PHP_EOL;
}

echo PHP_EOL . '2. The bot' . PHP_EOL;
$bot = $step('bot user ID (token works)', fn () => $discord->botUserId());

echo PHP_EOL . "3. The bot in server $guild" . PHP_EOL;
if ($bot) {
    $step('bot is a member of the server', fn () => $discord->isMember($guild, $bot) ? 'yes' : 'NO - invite it with scope "bot"');
    $step("bot's own role IDs", fn () => implode(', ', $discord->memberRoles($guild, $bot) ?? []) ?: '(none)');
}
$roles = $step('roles the bot can see (highest first)', function () use ($discord, $guild) {
    return implode(', ', array_map(fn ($r) => $r['name'] . ' [' . $r['id'] . ']', $discord->assignableRoles($guild)));
});

echo PHP_EOL . '4. Products with a Discord role' . PHP_EOL;
foreach (Product::with(['settings', 'server'])->get() as $product) {
    if ($product->server?->extension !== 'Wizarr') {
        continue;
    }
    $role = (string) (ExtensionHelper::settingsToArray($product->settings)['discord_role_id'] ?? '');
    echo '  ' . ($role === '' ? '-     ' : 'OK    ') . $product->name . ': ' . ($role === '' ? 'no role' : $role) . PHP_EOL;
}

if ($email) {
    echo PHP_EOL . "5. Customer $email" . PHP_EOL;
    $user = User::where('email', $email)->first();
    if (!$user) {
        echo "  FAIL  no Paymenter user with that e-mail" . PHP_EOL;
        exit(1);
    }
    $discordId = $user->properties()->where('key', 'discord_id')->value('value');
    echo '  ' . ($discordId ? 'OK    ' : 'FAIL  ') . 'Discord connected: ' . ($discordId ?: 'no - connect it at /wizarr/discord') . PHP_EOL;
    foreach ($user->services()->with('product')->get() as $service) {
        echo '  -     service ' . $service->id . ' (' . ($service->product->name ?? '?') . '): ' . $service->status . PHP_EOL;
    }
    if ($discordId) {
        $step('member of the server', fn () => $discord->isMember($guild, $discordId) ? 'yes' : 'NO');
        $step('roles before', fn () => implode(', ', $discord->memberRoles($guild, $discordId) ?? []) ?: '(none)');

        echo PHP_EOL . '6. Role update for this customer' . PHP_EOL;
        $step('give/take roles', function () use ($settings, $user) {
            (new Wizarr($settings))->syncRolesForUser($user);

            return 'ran - problems are in the log, see below';
        });
        $step('roles after', fn () => implode(', ', $discord->memberRoles($guild, $discordId) ?? []) ?: '(none)');
        $step('recorded as given by Paymenter', fn () => (string) $user->properties()->where('key', 'discord_roles_granted')->value('value') ?: '(none)');

        $log = $root . '/storage/logs/laravel-' . date('Y-m-d') . '.log';
        if (is_readable($log)) {
            $lines = array_filter(file($log), fn ($l) => str_contains($l, 'Wizarr') && stripos($l, 'role') !== false);
            echo PHP_EOL . '  Latest role lines in the log:' . PHP_EOL;
            foreach (array_slice($lines, -3) as $line) {
                echo '  ' . trim(preg_replace('/^\[[^\]]+\] production\.\w+: /', '', $line)) . PHP_EOL;
            }
        }
    }
}
echo PHP_EOL;
