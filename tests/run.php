<?php

/**
 * Drives the extension through a subscription's whole life against
 * stub_wizarr.py. Start it with ./run.sh, which also starts the stub.
 */

declare(strict_types=1);

require __DIR__ . '/shims.php';
require __DIR__ . '/../extensions/Servers/Wizarr/WizarrException.php';
require __DIR__ . '/../extensions/Servers/Wizarr/WizarrClient.php';
require __DIR__ . '/../extensions/Servers/Wizarr/SiloClient.php';
require __DIR__ . '/../extensions/Servers/Wizarr/DiscordException.php';
require __DIR__ . '/../extensions/Servers/Wizarr/DiscordClient.php';
require __DIR__ . '/../extensions/Servers/Wizarr/ShopGate.php';
require __DIR__ . '/../extensions/Servers/Wizarr/SeerrException.php';
require __DIR__ . '/../extensions/Servers/Wizarr/SeerrClient.php';
require __DIR__ . '/../extensions/Servers/Wizarr/TracearrClient.php';
foreach (glob(__DIR__ . '/../extensions/Servers/Wizarr/Concerns/*.php') as $concern) {
    require $concern;
}
require __DIR__ . '/../extensions/Servers/Wizarr/Wizarr.php';

use App\Exceptions\DisplayException;
use App\Helpers\NotificationHelper;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Paymenter\Extensions\Servers\Wizarr\Wizarr;
use Paymenter\Extensions\Servers\Wizarr\WizarrException;

$STUB = 'http://127.0.0.1:' . ($argv[1] ?? '8766');

/* ---------- tiny test kit ---------- */

$results = ['pass' => 0, 'fail' => []];

function stub(string $path, ?array $body = null): array
{
    global $STUB;
    $curl = curl_init($STUB . $path);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode($body ?? new stdClass),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_PROXY => '',
    ]);
    $raw = curl_exec($curl);
    if ($raw === false) {
        fwrite(STDERR, "stub not reachable at $STUB\n");
        exit(2);
    }
    curl_close($curl);

    return json_decode($raw, true);
}

function state(): array
{
    return stub('/_test/state');
}

function redeem(string $code, string $username, ?string $email = null): array
{
    return stub('/_test/redeem', ['code' => $code, 'username' => $username, 'email' => $email]);
}

function same(mixed $expected, mixed $actual, string $what): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($what . "\n      expected: " . json_encode($expected) . "\n      actual:   " . json_encode($actual));
    }
}

function ok(bool $condition, string $what): void
{
    if (!$condition) {
        throw new RuntimeException($what);
    }
}

function throws(callable $fn, string $needle, string $what): void
{
    try {
        $fn();
    } catch (WizarrException $e) {
        ok(stripos($e->getMessage(), $needle) !== false, $what . ' - message was: ' . $e->getMessage());

        return;
    }
    throw new RuntimeException($what . ' - nothing was thrown');
}

/** Fails unless $fn throws the exception Paymenter shows to the customer. */
function tellsCustomer(callable $fn, string $needle, string $what): void
{
    try {
        $fn();
    } catch (DisplayException $e) {
        ok(str_contains($e->getMessage(), $needle), $what . ' - message was: ' . $e->getMessage());

        return;
    } catch (Throwable $e) {
        throw new RuntimeException($what . ' - a raw ' . get_class($e) . ' reached the customer: ' . $e->getMessage());
    }
    throw new RuntimeException($what . ' - nothing was thrown');
}

function resetTokens(): array
{
    return array_values(array_filter(state()['reset_tokens'], fn ($t) => !$t['used']));
}

function test(string $name, callable $fn): void
{
    global $results;
    stub('/_test/reset');
    \Illuminate\Support\Facades\Cache::$store = [];
    NotificationHelper::$sent = [];
    NotificationHelper::$fail = false;
    \App\Models\Service::$all = [];
    Log::$warnings = [];
    Log::$infos = [];

    try {
        $fn();
        $results['pass']++;
        echo "  ok    $name\n";
    } catch (Throwable $e) {
        $results['fail'][] = $name;
        echo "  FAIL  $name\n      " . $e->getMessage() . "\n";
    }
}

/* ---------- fixtures ---------- */

/** The extension, with Discord's API pointed at the stub. */
class TestWizarr extends Wizarr
{
    protected function discordApiUrl(): string
    {
        return $GLOBALS['STUB'] . '/discord/api/v10';
    }
}

function ext(array $config = []): Wizarr
{
    global $STUB;

    return new TestWizarr($config + [
        'host' => $STUB . '/',
        'public_url' => 'https://join.example.com/',
        'api_key' => 'stub-key',
    ]);
}

function discordSettings(array $overrides = []): array
{
    return $overrides + [
        'discord_guild_id' => '100000000000000111',
        'discord_client_id' => 'client',
        'discord_client_secret' => 'secret',
        'discord_bot_token' => 'bot-token',
        'discord_invite_url' => 'https://discord.gg/waffle',
    ];
}

function discordCalls(): int
{
    return count(array_filter(state()['requests'], fn ($r) => str_starts_with($r['path'], 'discord:')));
}

/** What Paymenter's queue job does when it calls a hook: exceptions propagate. */
function tryHook(callable $fn): ?Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

/** retryPending() runner that calls the hooks the way ExtensionHelper would. */
function hookRunner(array $settings): callable
{
    return function (string $action, Service $service) use ($settings) {
        $action === 'suspend'
            ? ext()->suspendServer($service, $settings, $service->propertyArray())
            : ext()->terminateServer($service, $settings, $service->propertyArray());
    };
}

function settings(array $overrides = []): array
{
    return $overrides + [
        'server_ids' => ['1'],          // Jellyfin
        'library_ids' => ['10', '11'],
        'link_lifetime' => '7',
        'access_days' => null,
        'allow_downloads' => false,
        'allow_live_tv' => false,
        'login_url' => 'https://jellyfin.example.com',
    ];
}

function service(int $id = 1, string $email = 'mette@example.com'): Service
{
    return new Service($id, new User($email));
}

/** Subscribes and creates the user, as a paying customer would. */
function subscribed(array $settings, string $username = 'mette', ?Service $service = null): Service
{
    $service ??= service();
    $data = ext()->createServer($service, $settings, $service->propertyArray());
    redeem($data['invite_code'], $username, $service->user->email);

    return $service;
}

/**
 * A property of the first media server: subscriptions made since 1.11 keep
 * one invitation per server ("wizarr_invite_code:1"), older ones one without
 * a suffix.
 */
function grantProp($serviceOrProperties, string $name): ?string
{
    $props = is_array($serviceOrProperties) ? $serviceOrProperties : $serviceOrProperties->propertyArray();
    if (isset($props[$name])) {
        return $props[$name];
    }
    foreach ($props as $key => $value) {
        if (str_starts_with((string) $key, $name . ':')) {
            return $value;
        }
    }

    return null;
}

/**
 * A subscription as versions before 1.11 made it: one invitation for all of
 * the product's servers, stored without a server suffix, already redeemed.
 */
function legacySubscription(array $settings, string $username = 'mette', int $id = 1): Service
{
    global $STUB;
    $invitation = (new \Paymenter\Extensions\Servers\Wizarr\WizarrClient($STUB . '/', 'stub-key'))->createInvitation([
        'server_ids' => array_map('intval', is_array($settings['server_ids']) ? $settings['server_ids'] : explode(',', $settings['server_ids'])),
        'duration' => 'unlimited',
        'unlimited' => false,
    ]);
    $service = service($id);
    foreach (['wizarr_invitation_id' => (string) $invitation['id'], 'wizarr_invite_code' => $invitation['code'], 'wizarr_invite_url' => 'https://join.example.com/j/' . $invitation['code']] as $key => $value) {
        $service->properties()->updateOrCreate(['key' => $key], ['name' => $key, 'value' => $value]);
    }
    redeem($invitation['code'], $username, $service->user->email);
    ext()->getActions($service, $settings, $service->propertyArray()); // records the users, as a page view did

    return $service;
}

/** The actions without the "Overview" tab. */
function acts(array $actions): array
{
    return array_values(array_filter($actions, fn ($a) => $a['type'] !== 'view'));
}

function labels(array $actions): array
{
    // The "Overview" tab (a view) is tested on its own.
    return array_values(array_map(fn ($a) => $a['type'] . ':' . ($a['label'] ?? '') . (isset($a['text']) ? '=' . $a['text'] : ''), array_filter($actions, fn ($a) => $a['type'] !== 'view')));
}

echo "\nPaymenter Wizarr extension\n\n";

/* ---------- configuration ---------- */

test('testConfig accepts a working host and key', function () {
    same(true, ext()->testConfig(), 'testConfig');
});

test('testConfig names the API key when Wizarr answers 401', function () {
    $result = ext(['api_key' => 'wrong'])->testConfig();
    ok(is_string($result) && str_contains($result, 'API key'), 'got: ' . json_encode($result));
});

test('testConfig explains an unreachable host instead of throwing', function () {
    $result = ext(['host' => 'http://127.0.0.1:1'])->testConfig();
    ok(is_string($result) && str_contains($result, 'Could not reach Wizarr'), 'got: ' . json_encode($result));
});

test('product config offers only verified servers', function () {
    $fields = array_column(ext()->getProductConfig(), null, 'name');
    same('select', $fields['server_ids']['type'], 'server field type');
    same(['1', '2', '4'], array_column($fields['server_ids']['options'], 'value'), 'server options');
    same(true, $fields['server_ids']['multiple'], 'multi select');
    same(['10', '11', '20', '40'], array_column($fields['library_ids']['options'], 'value'), 'library options');
});

test('product page still renders when Wizarr is down', function () {
    $fields = array_column(ext(['host' => 'http://127.0.0.1:1'])->getProductConfig(), null, 'name');
    same('text', $fields['server_ids']['type'], 'falls back to a text field');
    same('text', $fields['library_ids']['type'], 'falls back to a text field');
});

/* ---------- first payment ---------- */

test('first payment creates one single-use invitation and returns the link for the mail', function () {
    $service = service();
    $data = ext()->createServer($service, settings(), $service->propertyArray());

    $invitations = state()['invitations'];
    same(1, count($invitations), 'one invitation');
    $inv = $invitations[0];

    same(false, $inv['unlimited'], 'must be single-use (Wizarr defaults this to true)');
    same(null, $inv['duration'], 'subscription: no expiry on the user');
    same(7, $inv['expires_in_days'], 'link lifetime');
    same([1], $inv['server_ids'], 'server ids sent as integers');
    same([10, 11], $inv['library_ids'], 'library ids');

    same('https://join.example.com/j/' . $inv['code'], $data['invite_url'], 'absolute link built from the public URL');
    same($inv['code'], $data['invite_code'], 'code');
    same('7 days', $data['invite_valid_for'], 'validity text');

    $props = $service->propertyArray();
    same((string) $inv['id'], grantProp($props, 'wizarr_invitation_id'), 'invitation id stored');
    same($data['invite_url'], grantProp($props, 'wizarr_invite_url'), 'link stored');
});

test('running create twice does not hand out a second seat', function () {
    $service = service();
    $first = ext()->createServer($service, settings(), $service->propertyArray());
    $second = ext()->createServer($service, settings(), $service->propertyArray());

    same(1, count(state()['invitations']), 'still one invitation');
    same($first['invite_url'], $second['invite_url'], 'same link returned');
});

test('create after the customer already signed up returns the place to watch, not a new link', function () {
    $service = subscribed(settings());
    $data = ext()->createServer($service, settings(), $service->propertyArray());

    same(1, count(state()['invitations']), 'no new invitation');
    same('https://jellyfin.example.com', $data['invite_url'], 'points at the media server');
});

test('one-time product puts the access period on the Wizarr user', function () {
    $service = service();
    ext()->createServer($service, settings(['access_days' => '365', 'link_lifetime' => 'never']), []);

    $inv = state()['invitations'][0];
    same('365', $inv['duration'], 'duration in days');
    same(null, $inv['expires'], 'link never expires');
});

test('an unsupported link lifetime becomes 7 days, not "never"', function () {
    ext()->createServer(service(), settings(['link_lifetime' => '14']), []);
    same(7, state()['invitations'][0]['expires_in_days'], 'fallback');
});

test('server ids are understood as array, JSON or typed text', function () {
    foreach ([['1', '2'], '[1,2]', '1, 2', '1;2'] as $i => $shape) {
        ext()->createServer(service(10 + $i), settings(['server_ids' => $shape, 'library_ids' => '']), []);
    }
    $invitations = state()['invitations'];
    same(8, count($invitations), 'one invitation per server, for each of the four');
    foreach (array_chunk($invitations, 2) as $pair) {
        same([[1], [2]], array_column($pair, 'server_ids'), 'server ids');
        same([[], []], array_column($pair, 'library_ids'), 'no libraries');
    }
});

test('a product without a server fails with an instruction, before calling Wizarr', function () {
    throws(fn () => ext()->createServer(service(), settings(['server_ids' => '']), []), 'no media server selected', 'missing server');
    same(0, count(state()['invitations']), 'nothing created');
});

test('an unverified server is reported with Wizarr\'s own reason', function () {
    throws(fn () => ext()->createServer(service(), settings(['server_ids' => ['3']]), []), 'not found or not verified', 'unverified server');
});

/* ---------- client area ---------- */

test('client area shows the sign-up button while the invitation is unused', function () {
    $service = service();
    $data = ext()->createServer($service, settings(), []);
    $actions = ext()->getActions($service, settings(), $service->propertyArray());

    same(['text:Status=Waiting for you to create your user', 'button:Create your user', 'button:Problems? Get a new link'], labels($actions), 'actions');
    same($data['invite_url'], acts($actions)[1]['url'], 'button target');
});

test('client area switches to "active" once redeemed, and then stops calling Wizarr', function () {
    $service = subscribed(settings());

    $actions = ext()->getActions($service, settings(), $service->propertyArray());
    same(['text:Status=Active', 'text:Username=mette', 'button:Reset password', 'button:Open the media library'], labels($actions), 'actions');
    same('1:mette:jellyfin', grantProp($service, 'wizarr_users'), 'user recorded with its server type');

    stub('/_test/clear-requests');
    ext()->getActions($service, settings(), $service->propertyArray());
    same(0, count(state()['requests']), 'no HTTP calls on later page views');
});

test('client area still shows the stored link when Wizarr is down', function () {
    $service = service();
    $data = ext()->createServer($service, settings(), []);
    $actions = ext(['host' => 'http://127.0.0.1:1'])->getActions($service, settings(), $service->propertyArray());

    same($data['invite_url'], acts($actions)[1]['url'], 'stored link');
});

test('an expired link can be renewed by the customer', function () {
    $service = service();
    $old = ext()->createServer($service, settings(), []);
    stub('/_test/expire', ['code' => $old['invite_code']]);

    $actions = ext()->getActions($service, settings(), $service->propertyArray());
    same(['text:Status=Your invitation link has expired', 'button:Get a new invitation link'], labels($actions), 'actions');
    same('renewInvitation', acts($actions)[1]['function'], 'button calls the extension');

    $url = ext()->renewInvitation($service, settings(), $service->propertyArray());
    $invitations = state()['invitations'];
    same(1, count($invitations), 'old one removed, one new');
    ok($invitations[0]['code'] !== $old['invite_code'], 'a different code');
    same('https://join.example.com/j/' . $invitations[0]['code'], $url, 'redirect target');
    same($url, grantProp($service, 'wizarr_invite_url'), 'new link stored');
});

test('renewing cannot be used to get a second seat', function () {
    $service = subscribed(settings());
    $url = ext()->renewInvitation($service, settings(), $service->propertyArray());

    same('https://jellyfin.example.com', $url, 'sent to the media server');
    same(1, count(state()['invitations']), 'no new invitation');
});

/* ---------- overdue / paid again ---------- */

test('Jellyfin: overdue disables the user, paying enables the same user again', function () {
    $service = subscribed(settings());

    ext()->suspendServer($service, settings(), $service->propertyArray());
    $user = state()['users'][0];
    same(true, $user['is_disabled'], 'disabled, not deleted');

    ext()->unsuspendServer($service, settings(), $service->propertyArray());
    same(false, state()['users'][0]['is_disabled'], 'enabled again');
    same(1, count(state()['invitations']), 'no new invitation needed');
    same(0, count(NotificationHelper::$sent), 'no mail needed');
});

test('Plex: paying after a suspension sends a new invitation, because Plex cannot re-enable', function () {
    $plex = settings(['server_ids' => ['2'], 'library_ids' => ['20'], 'login_url' => 'https://app.plex.tv']);
    $service = subscribed($plex);
    $firstCode = grantProp($service, 'wizarr_invite_code');

    ext()->suspendServer($service, $plex, $service->propertyArray());
    same(true, state()['users'][0]['plex_share_removed'], 'share removed');

    ext()->unsuspendServer($service, $plex, $service->propertyArray());
    $state = state();
    same(0, count($state['users']), 'stale user record removed');
    same(1, count($state['invitations']), 'old invitation replaced');
    ok($state['invitations'][0]['code'] !== $firstCode, 'new code');
    same(false, $state['invitations'][0]['unlimited'], 'still single-use');

    same(1, count(NotificationHelper::$sent), 'customer is mailed the new link');
    same('mette@example.com', NotificationHelper::$sent[0]['email'], 'to the customer');
    same('https://join.example.com/j/' . $state['invitations'][0]['code'], NotificationHelper::$sent[0]['data']['invite_url'], 'with the new link');
});

test('media server offline during suspension: Wizarr deletes the user, paying re-invites', function () {
    $service = subscribed(settings());
    stub('/_test/set', ['server_id' => 1, 'offline' => true]);

    ext()->suspendServer($service, settings(), $service->propertyArray());
    same(0, count(state()['users']), 'Wizarr fell back to deleting');
    ok(grantProp($service, 'wizarr_users') === null, 'deleted user is not remembered');

    stub('/_test/set', ['server_id' => 1, 'offline' => false]);
    ext()->unsuspendServer($service, settings(), $service->propertyArray());
    same(1, count(state()['invitations']), 'one fresh invitation');
    same(false, state()['invitations'][0]['used'], 'unused, so the customer can sign up again');
    same(1, count(NotificationHelper::$sent), 'customer is told');
});

test('overdue before ever signing up withdraws the link; paying issues a new one', function () {
    $service = service();
    $data = ext()->createServer($service, settings(), []);

    ext()->suspendServer($service, settings(), $service->propertyArray());
    same(0, count(state()['invitations']), 'link withdrawn');
    same(['wizarr_servers' => '1'], $service->propertyArray(), 'nothing left on the service but which server it has');
    ok(isset(redeem($data['invite_code'], 'mette')['error']), 'old link no longer works');

    ext()->unsuspendServer($service, settings(), $service->propertyArray());
    same(1, count(state()['invitations']), 'new invitation');
    same(1, count(NotificationHelper::$sent), 'mailed');
});

test('paying while an unused link is still valid changes nothing', function () {
    $service = service();
    ext()->createServer($service, settings(), []);
    stub('/_test/clear-requests');

    ext()->unsuspendServer($service, settings(), $service->propertyArray());
    same(1, count(state()['invitations']), 'same invitation');
    same(0, count(NotificationHelper::$sent), 'no mail');
});

test('a mail failure on re-invite does not fail the job', function () {
    $service = service();
    ext()->createServer($service, settings(), []);
    ext()->suspendServer($service, settings(), $service->propertyArray());

    NotificationHelper::$fail = true;
    same(true, ext()->unsuspendServer($service, settings(), $service->propertyArray()), 'unsuspend succeeds');
    same(1, count(state()['invitations']), 'invitation exists');
    ok(grantProp($service, 'wizarr_invite_url') !== null, 'link is in the client area');
    same(1, count(Log::$warnings), 'problem is logged');
});

/* ---------- cancellation ---------- */

test('cancelling deletes the user and the invitation, and leaves nothing behind', function () {
    $service = subscribed(settings());

    ext()->terminateServer($service, settings(), $service->propertyArray());
    $state = state();
    same(0, count($state['users']), 'user deleted');
    same(0, count($state['invitations']), 'invitation deleted');
    same([], $service->propertyArray(), 'service properties cleared');
});

test('cancelling twice is harmless', function () {
    $service = subscribed(settings());
    $before = $service->propertyArray();

    ext()->terminateServer($service, settings(), $before);
    ext()->terminateServer($service, settings(), $before); // same stale snapshot, as a retried job would have
    same(0, count(state()['users']), 'still gone');
});

test('cancelling before signing up removes the unused link', function () {
    $service = service();
    ext()->createServer($service, settings(), []);

    ext()->terminateServer($service, settings(), $service->propertyArray());
    same(0, count(state()['invitations']), 'link removed');
});

test('cancelling a suspended subscription still finds the disabled user', function () {
    $service = subscribed(settings());
    ext()->suspendServer($service, settings(), $service->propertyArray());

    ext()->terminateServer($service, settings(), $service->propertyArray());
    same(0, count(state()['users']), 'deleted');
});

test('a pre-1.11 invitation for two servers removes the user from both', function () {
    $both = settings(['server_ids' => ['1', '2'], 'library_ids' => '']);
    $service = legacySubscription($both);
    same(2, count(state()['users']), 'one user per server');

    ext()->terminateServer($service, $both, $service->propertyArray());
    same(0, count(state()['users']), 'both removed');
});

/* ---------- not touching the wrong person ---------- */

test('other customers are never touched', function () {
    $a = subscribed(settings(), 'mette', service(1, 'mette@example.com'));
    $b = subscribed(settings(), 'anders', service(2, 'anders@example.com'));

    ext()->suspendServer($a, settings(), $a->propertyArray());
    $users = array_column(state()['users'], 'is_disabled', 'username');
    same(['mette' => true, 'anders' => false], $users, 'only mette suspended');

    ext()->terminateServer($a, settings(), $a->propertyArray());
    same(['anders'], array_column(state()['users'], 'username'), 'only mette deleted');
    same(1, count(state()['invitations']), "anders' invitation untouched");
});

test('an existing account with the same e-mail address is left alone', function () {
    // The customer already had access (say, as a friend) before subscribing,
    // and signed up for the subscription under a second username.
    stub('/_test/add-user', ['username' => 'mette-gammel', 'email' => 'mette@example.com']);
    $service = subscribed(settings(), 'mette');

    ext()->terminateServer($service, settings(), $service->propertyArray());
    same(['mette-gammel'], array_column(state()['users'], 'username'), 'only the subscription user is removed');
});

test('same username on a server the invitation does not cover is left alone', function () {
    stub('/_test/add-user', ['username' => 'mette', 'server_id' => 2]); // unrelated Plex account
    $service = subscribed(settings(), 'mette');                         // Jellyfin subscription

    ext()->terminateServer($service, settings(), $service->propertyArray());
    $left = state()['users'];
    same(1, count($left), 'one user left');
    same(2, $left[0]['server_id'], 'the Plex account');
});

test('a recorded user id that now belongs to someone else is not trusted', function () {
    $service = service();
    // Recorded earlier; meanwhile that user was removed and the id reused.
    $service->properties()->updateOrCreate(['key' => 'wizarr_users'], ['name' => 'Wizarr users', 'value' => '1:mette']);
    stub('/_test/add-user', ['username' => 'anders']); // gets id 1

    ext()->terminateServer($service, settings(), $service->propertyArray());
    same(['anders'], array_column(state()['users'], 'username'), 'anders survives');
});

test('works when Wizarr reports used_by as a username, as its docs say', function () {
    stub('/_test/set', ['used_by_mode' => 'username']);
    $service = subscribed(settings());

    ext()->suspendServer($service, settings(), $service->propertyArray());
    same(true, state()['users'][0]['is_disabled'], 'found and disabled');
});

test('the user is still found after the invitation record was cleaned up in Wizarr', function () {
    $service = subscribed(settings());
    ext()->getActions($service, settings(), $service->propertyArray()); // records the user
    $state = state();
    // an admin deletes the used invitation from Wizarr's list
    $curl = curl_init($GLOBALS['STUB'] . '/api/invitations/' . $state['invitations'][0]['id']);
    curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['X-API-Key: stub-key'], CURLOPT_PROXY => '']);
    curl_exec($curl);
    curl_close($curl);

    ext()->terminateServer($service, settings(), $service->propertyArray());
    same(0, count(state()['users']), 'deleted via the recorded user');
});

/* ---------- forgotten password ---------- */

test('reset button sends the customer to Wizarr\'s reset page for their own user', function () {
    $service = subscribed(settings());
    ext()->getActions($service, settings(), $service->propertyArray()); // records the user

    $url = ext()->resetPassword($service, settings(), $service->propertyArray());
    $tokens = resetTokens();
    same(1, count($tokens), 'one live reset link');
    same(1, $tokens[0]['user_id'], "for the subscription's user");
    same('https://join.example.com/reset/' . $tokens[0]['code'], $url, 'absolute link on the public URL');
});

test('works before the client area was ever opened', function () {
    $service = subscribed(settings()); // nothing recorded yet, only used_by on the invitation
    ext()->resetPassword($service, settings(), $service->propertyArray());
    same(1, resetTokens()[0]['user_id'], 'found through the invitation');
});

test('clicking twice leaves exactly one working link', function () {
    $service = subscribed(settings());
    $first = ext()->resetPassword($service, settings(), $service->propertyArray());
    $second = ext()->resetPassword($service, settings(), $service->propertyArray());

    ok($first !== $second, 'a new link');
    same(1, count(resetTokens()), 'the old one is no longer valid');
    ok(str_ends_with($second, resetTokens()[0]['code']), 'the newest one is the live one');
});

test('a reset never touches another customer', function () {
    $a = subscribed(settings(), 'mette', service(1, 'mette@example.com'));
    $b = subscribed(settings(), 'anders', service(2, 'anders@example.com'));

    ext()->resetPassword($b, settings(), $b->propertyArray());
    $tokens = resetTokens();
    same(1, count($tokens), 'one link');
    same('anders', array_column(state()['users'], 'username', 'id')[$tokens[0]['user_id']], "anders' own user");
});

test('a stale user record whose id now belongs to someone else gets no link', function () {
    $service = service();
    $service->properties()->updateOrCreate(['key' => 'wizarr_users'], ['name' => 'Wizarr users', 'value' => '1:mette:jellyfin']);
    stub('/_test/add-user', ['username' => 'anders']); // gets id 1

    tellsCustomer(fn () => ext()->resetPassword($service, settings(), $service->propertyArray()), 'Contact us', 'refused');
    same([], resetTokens(), 'no link created for anders');
});

test('Plex has no reset button, and the handler refuses politely', function () {
    $plex = settings(['server_ids' => ['2'], 'library_ids' => ['20']]);
    $service = subscribed($plex);

    $actions = ext()->getActions($service, $plex, $service->propertyArray());
    same(['text:Status=Active', 'text:Username=mette', 'button:Open the media library'], labels($actions), 'no reset button');

    tellsCustomer(fn () => ext()->resetPassword($service, $plex, $service->propertyArray()), 'Contact us', 'refused');
    same([], resetTokens(), 'no useless link created');
});

test('a product on Jellyfin and Plex shows the name once and resets the Jellyfin user', function () {
    $both = settings(['server_ids' => ['1', '2'], 'library_ids' => '']);
    $service = subscribed($both);

    $actions = ext()->getActions($service, $both, $service->propertyArray());
    ok(in_array('text:Username=mette', labels($actions), true), 'name shown once: ' . json_encode(labels($actions)));

    ext()->resetPassword($service, $both, $service->propertyArray());
    $users = array_column(state()['users'], 'server_id', 'id');
    same(1, $users[resetTokens()[0]['user_id']], 'the Jellyfin user (server 1)');
});

test('records stored by version 1.0.0 still get the button and work', function () {
    $service = legacySubscription(settings());
    $service->properties()->updateOrCreate(['key' => 'wizarr_users'], ['name' => 'Wizarr users', 'value' => '1:mette']);

    $actions = ext()->getActions($service, settings(), $service->propertyArray());
    ok(in_array('button:Reset password', labels($actions), true), 'button offered');

    ext()->resetPassword($service, settings(), $service->propertyArray());
    same(1, resetTokens()[0]['user_id'], 'link created');
});

test('Wizarr down: the customer gets a short notice, not an error page', function () {
    $service = subscribed(settings());
    ext()->getActions($service, settings(), $service->propertyArray());

    tellsCustomer(
        fn () => ext(['host' => 'http://127.0.0.1:1'])->resetPassword($service, settings(), $service->propertyArray()),
        'try again shortly',
        'friendly message',
    );
    same(1, count(Log::$warnings), 'the real reason is logged for you');
});

test('a reset link is not offered before the customer has a user', function () {
    $service = service();
    ext()->createServer($service, settings(), []);
    $actions = ext()->getActions($service, settings(), $service->propertyArray());
    ok(!in_array('button:Reset password', labels($actions), true), 'no reset while the invite is unused');
});

/* ---------- safety net: retry failed suspensions/terminations ---------- */

test('a suspension that failed is finished by the hourly retry', function () {
    $service = subscribed(settings());
    stub('/_test/set', ['fail_next' => 10]);            // Wizarr down while Paymenter suspends
    ok(tryHook(fn () => ext()->suspendServer($service, settings(), $service->propertyArray())) !== null, 'the job fails');
    $service->status = 'suspended';                     // Paymenter marks it suspended anyway
    same('suspend', $service->propertyArray()['wizarr_pending'] ?? null, 'marker left behind');
    same(false, state()['users'][0]['is_disabled'], 'customer still has access');

    stub('/_test/set', ['fail_next' => 0]);             // Wizarr is back
    $result = ext()->retryPending([$service], hookRunner(settings()));
    same(['retried' => 1, 'failed' => 0, 'errors' => []], $result, 'retried once');
    same(true, state()['users'][0]['is_disabled'], 'access closed');
    ok(!isset($service->propertyArray()['wizarr_pending']), 'marker cleared');
});

test('a suspension that worked leaves no marker, so the retry never touches it', function () {
    $service = subscribed(settings());
    ext()->suspendServer($service, settings(), $service->propertyArray());
    $service->status = 'suspended';
    ok(!isset($service->propertyArray()['wizarr_pending']), 'no marker');

    $calls = 0;
    // Paymenter only hands over services with a marker; even if it handed this one over, nothing runs.
    ext()->retryPending([], function () use (&$calls) { $calls++; });
    same(0, $calls, 'no work');
});

test('paying before the retry cancels it', function () {
    $service = subscribed(settings());
    stub('/_test/set', ['fail_next' => 10]);
    tryHook(fn () => ext()->suspendServer($service, settings(), $service->propertyArray()));
    stub('/_test/set', ['fail_next' => 0]);

    // Customer pays: Paymenter unsuspends and the service is active again.
    ext()->unsuspendServer($service, settings(), $service->propertyArray());
    $service->status = 'active';
    ok(!isset($service->propertyArray()['wizarr_pending']), 'unsuspend clears the marker');

    // Even a stale marker on an active service is dropped without action.
    $service->properties()->updateOrCreate(['key' => 'wizarr_pending'], ['name' => 'x', 'value' => 'suspend']);
    $calls = 0;
    ext()->retryPending([$service], function () use (&$calls) { $calls++; });
    same(0, $calls, 'nothing re-suspended');
    ok(!isset($service->propertyArray()['wizarr_pending']), 'stale marker removed');
    same(false, state()['users'][0]['is_disabled'], 'paying customer keeps access');
});

test('a termination that failed is finished by the retry', function () {
    $service = subscribed(settings());
    stub('/_test/set', ['fail_next' => 10]);
    ok(tryHook(fn () => ext()->terminateServer($service, settings(), $service->propertyArray())) !== null, 'the job fails');
    $service->status = 'cancelled';
    same(1, count(state()['users']), 'user still there');

    stub('/_test/set', ['fail_next' => 0]);
    ext()->retryPending([$service], hookRunner(settings()));
    same(0, count(state()['users']), 'user removed');
    ok(!isset($service->propertyArray()['wizarr_pending']), 'marker cleared');
});

test('a retry that still fails is logged and kept for the next hour', function () {
    $service = subscribed(settings());
    stub('/_test/set', ['fail_next' => 100]);
    tryHook(fn () => ext()->suspendServer($service, settings(), $service->propertyArray()));
    $service->status = 'suspended';

    $result = ext()->retryPending([$service], hookRunner(settings()));
    same([1, 0, [1]], [$result['failed'], $result['retried'], array_keys($result['errors'])], 'counted as failed, with the reason');
    same('suspend', $service->propertyArray()['wizarr_pending'] ?? null, 'marker kept');
    same(1, count(Log::$warnings), 'logged');
    stub('/_test/set', ['fail_next' => 0]);
});

test('a marker that does not match the status is dropped, not acted on', function () {
    $service = subscribed(settings());
    $service->status = 'suspended';
    $service->properties()->updateOrCreate(['key' => 'wizarr_pending'], ['name' => 'x', 'value' => 'terminate']);

    $calls = 0;
    ext()->retryPending([$service], function () use (&$calls) { $calls++; });
    same(0, $calls, 'no termination of a merely suspended service');
    same(1, count(state()['users']), 'user untouched');
});

/* ---------- Discord membership at checkout ---------- */

test('a product without the Discord requirement never asks Discord', function () {
    ext()->checkPurchaseAllowed([], ['require_discord' => false], null);
    same(0, discordCalls(), 'no calls');
});

test('required: no Discord account connected stops the purchase', function () {
    tellsCustomer(fn () => ext()->checkPurchaseAllowed(discordSettings(), ['require_discord' => '1'], null), 'Connect your Discord', 'refused');
    same(0, discordCalls(), 'no call needed');
});

test('required: a member may buy', function () {
    stub('/_test/set', ['discord' => ['members' => ['999', '4242']]]);
    ext()->checkPurchaseAllowed(discordSettings(), ['require_discord' => true], '4242');
    same(1, discordCalls(), 'one membership check');
});

test('required: a non-member is refused and told where to join', function () {
    tellsCustomer(fn () => ext()->checkPurchaseAllowed(discordSettings(), ['require_discord' => true], '4242'), 'member of our Discord', 'refused');
});

test('required: Discord down means wait, not "not a member" and not "go ahead"', function () {
    stub('/_test/set', ['discord' => ['down' => true, 'members' => ['999', '4242']]]);
    tellsCustomer(fn () => ext()->checkPurchaseAllowed(discordSettings(), ['require_discord' => true], '4242'), 'right now', 'fails closed');
    same(1, count(Log::$warnings), 'reason logged');
});

test('required: a wrong bot token fails closed and logs the reason', function () {
    tellsCustomer(fn () => ext()->checkPurchaseAllowed(discordSettings(['discord_bot_token' => 'wrong']), ['require_discord' => true], '4242'), 'right now', 'refused');
    ok(str_contains(Log::$warnings[0] ?? '', 'bot token'), 'log names the token: ' . json_encode(Log::$warnings));
});

test('required but Discord not set up on the server: closed, with a log line for you', function () {
    tellsCustomer(fn () => ext()->checkPurchaseAllowed(discordSettings(['discord_bot_token' => '']), ['require_discord' => true], '4242'), 'temporarily unavailable', 'refused');
    same(0, discordCalls(), 'no call');
    ok(str_contains(Log::$warnings[0] ?? '', 'incomplete'), 'logged');
});

test('Test Connection checks the Discord settings too', function () {
    same(true, ext(discordSettings())->testConfig(), 'all good');

    stub('/_test/set', ['discord' => ['members' => []]]);   // bot not invited
    $result = ext(discordSettings())->testConfig();
    ok(is_string($result) && str_contains($result, 'Missing Access') === false && str_contains($result, 'bot'), 'bot not in server: ' . json_encode($result));

    stub('/_test/set', ['discord' => ['members' => ['999']]]);
    $result = ext(discordSettings(['discord_guild_id' => '100000000000000222']))->testConfig();
    ok(is_string($result) && str_contains($result, 'server ID'), 'wrong server id: ' . json_encode($result));

    $result = ext(discordSettings(['discord_bot_token' => 'wrong']))->testConfig();
    ok(is_string($result) && str_contains($result, 'bot token'), 'bad token: ' . json_encode($result));

    $result = ext(['discord_guild_id' => '100000000000000111'])->testConfig();
    ok(is_string($result) && str_contains($result, 'incomplete'), 'half filled in: ' . json_encode($result));

    // A digit lost while editing the server (happened on a live install): Discord says so.
    $result = ext(discordSettings(['discord_guild_id' => '10000000000000011']))->testConfig();
    ok(is_string($result) && str_contains($result, 'server ID'), 'wrong ID named: ' . json_encode($result));
});

/* ---------- "your access is ready" banner ---------- */

function bannerFor(Service $service, ?int $now = null): ?array
{
    return ext()->bannerState($service, $service->propertyArray(), $now);
}

test('banner: just paid, invitation not created yet -> "being prepared"', function () {
    $service = service();
    $service->updated_at = new DateTimeImmutable('-20 seconds');
    same('preparing', bannerFor($service)['state'] ?? null, 'state');
});

test('banner: gives up on "being prepared" after 15 minutes', function () {
    $service = service();
    $service->updated_at = new DateTimeImmutable('-16 minutes');
    same(null, bannerFor($service), 'no banner');
});

test('banner: invitation created -> "ready" with the link, no call to Wizarr', function () {
    $service = service();
    $data = ext()->createServer($service, settings(), []);
    stub('/_test/clear-requests');

    $state = bannerFor($service);
    same('ready', $state['state'], 'state');
    same($data['invite_url'], $state['inviteUrl'], 'link');
    same(0, count(state()['requests']), 'rendered from stored data only');
});

test('banner: the expiry is stored, and an expired link says so', function () {
    $service = service();
    ext()->createServer($service, settings(), []);
    $expires = grantProp($service, 'wizarr_invite_expires');
    ok($expires !== null, 'expiry stored');

    same('expired', bannerFor($service, strtotime($expires) + 1)['state'], 'after expiry');
    same('ready', bannerFor($service, strtotime($expires) - 60)['state'], 'before expiry');
});

test('banner: a link that never expires stays "ready"', function () {
    $service = service();
    ext()->createServer($service, settings(['link_lifetime' => 'never']), []);
    ok(grantProp($service, 'wizarr_invite_expires') === null, 'nothing stored');
    same('ready', bannerFor($service, time() + 86400 * 365)['state'], 'still ready a year later');
});

test('banner: gone once the customer has a user', function () {
    $service = subscribed(settings());
    ext()->getActions($service, settings(), $service->propertyArray()); // records the user
    same(null, bannerFor($service), 'no banner');
});

test('banner: withdrawing the link (suspension) also clears the stored expiry', function () {
    $service = service();
    ext()->createServer($service, settings(), []);
    ext()->suspendServer($service, settings(), $service->propertyArray());
    ok(grantProp($service, 'wizarr_invite_expires') === null, 'cleared');
});

test('live check: notices that the link was used, records the user and hides the banner', function () {
    $service = service();
    $data = ext()->createServer($service, settings(), []);
    same('ready', ext()->liveBannerState($service, $service->propertyArray())['state'], 'before');

    redeem($data['invite_code'], 'mette');
    same('done', ext()->liveBannerState($service, $service->propertyArray())['state'], 'after signing up');
    same('1:mette:jellyfin', grantProp($service, 'wizarr_users'), 'user recorded');
    same(null, bannerFor($service), 'no banner on the next page');
});

test('live check: an expired link is reported as expired', function () {
    $service = service();
    $data = ext()->createServer($service, settings(), []);
    stub('/_test/expire', ['code' => $data['invite_code']]);
    same('expired', ext()->liveBannerState($service, $service->propertyArray())['state'], 'expired');
});

test('live check: Wizarr down keeps showing what is stored', function () {
    $service = service();
    $data = ext()->createServer($service, settings(), []);
    $state = ext(['host' => 'http://127.0.0.1:1'])->liveBannerState($service, $service->propertyArray());
    same(['ready', $data['invite_url']], [$state['state'], $state['inviteUrl']], 'unchanged');
});

test('banner HTML escapes everything it prints', function () {
    $html = ext()->bannerHtml([
        'state' => 'ready',
        'key' => 'service-1',
        'inviteUrl' => 'https://join.example.com/j/X"><script>alert(1)</script>',
        'serviceUrl' => 'https://pay.example.com/services/1',
        'statusUrl' => 'https://pay.example.com/wizarr/status',
    ]);
    ok(!str_contains($html, '"><script>alert(1)'), 'no injection');
    ok(str_contains($html, 'href="https://join.example.com/j/X&quot;&gt;&lt;script&gt;'), 'escaped');
    ok(str_contains($html, 'data-status-url="https://pay.example.com/wizarr/status"'), 'status url');
    same(1, substr_count($html, '<script>'), 'exactly one script: ours');
});

/* ---------- boot: what Paymenter registers on every request ---------- */

function freshBoot(bool $scheduleAlreadyResolved): \Illuminate\Foundation\FakeApplication
{
    \Illuminate\Foundation\FakeApplication::$instance = $app = new \Illuminate\Foundation\FakeApplication;
    $app->instance('router', new \Illuminate\Routing\Router);
    \Illuminate\Support\Facades\Route::$routes = [];
    \Illuminate\Support\Facades\Event::$listeners = [];
    \Illuminate\Support\Facades\View::$namespaces = [];
    // A new request is a new PHP process: nothing registered yet.
    (new ReflectionProperty(Wizarr::class, 'refundListener'))->setValue(null, false);
    (new ReflectionProperty(Wizarr::class, 'seerrGuardListener'))->setValue(null, false);
    if ($scheduleAlreadyResolved) {
        $app->make(\Illuminate\Console\Scheduling\Schedule::class);
    }

    return $app;
}

test('boot registers the Discord pages, the checkout guard and the hourly retry', function () {
    $app = freshBoot(scheduleAlreadyResolved: false);
    ext(discordSettings())->boot();

    $routes = array_map(fn ($r) => $r->method . ' ' . $r->uri . ' ' . $r->name, \Illuminate\Support\Facades\Route::$routes);
    same([
        'GET wizarr/discord extensions.wizarr.discord',
        'GET wizarr/discord/connect extensions.wizarr.discord.connect',
        'POST wizarr/discord/disconnect extensions.wizarr.discord.disconnect',
        'GET wizarr/discord/login extensions.wizarr.discord.login',
        'GET wizarr/discord/callback extensions.wizarr.discord.callback',
        'GET wizarr/status extensions.wizarr.status',
        'GET wizarr/report extensions.wizarr.report',
        'POST wizarr/report extensions.wizarr.report.submit',
        'GET wizarr/report/poster/{service} extensions.wizarr.report.poster',
        'GET wizarr/requests extensions.wizarr.requests',
        'POST wizarr/requests extensions.wizarr.requests.submit',
        'GET wizarr/gift/{service} extensions.wizarr.gift', 'POST wizarr/gift/{service} extensions.wizarr.gift',
        'GET wizarr/pause/{service} extensions.wizarr.pause', 'POST wizarr/pause/{service} extensions.wizarr.pause',
        'GET wizarr/invite extensions.wizarr.invite', 'POST wizarr/invite extensions.wizarr.invite',
        'POST wizarr/discord/interactions extensions.wizarr.discord.interactions', 'GET wizarr/news/off/{user} extensions.wizarr.news.off',
        'GET status extensions.wizarr.status-page',
        'GET wizarr/help extensions.wizarr.help', 'POST wizarr/checklist/close extensions.wizarr.checklist.close',
        'GET wizarr/switch/{service} extensions.wizarr.switch', 'POST wizarr/switch/{service} extensions.wizarr.switch',
        'GET wizarr/nps/{user}/{score} extensions.wizarr.nps', 'POST wizarr/nps/{user}/{score} extensions.wizarr.nps', 'GET wizarr/why/{cancellation}/{reason} extensions.wizarr.why',
        'GET wizarr/movie-night extensions.wizarr.party', 'POST wizarr/movie-night extensions.wizarr.party', 'GET wizarr/challenge extensions.wizarr.challenge',
        'GET wizarr/friend-pass extensions.wizarr.pass', 'POST wizarr/friend-pass extensions.wizarr.pass', 'GET wizarr/me extensions.wizarr.hub', 'GET wizarr/poster/{key} extensions.wizarr.poster', 'GET catalog extensions.wizarr.catalog', 'POST wizarr/mail-list extensions.wizarr.mail-list', 'GET wizarr/join/{code} extensions.wizarr.join',
    ], $routes, 'routes');
    $byName = array_column(array_map(fn ($r) => ['n' => $r->name, 'm' => $r->middleware], \Illuminate\Support\Facades\Route::$routes), 'm', 'n');
    same(['web', 'guest'], $byName['extensions.wizarr.discord.login'], 'login only for guests');
    same(['web'], $byName['extensions.wizarr.discord.callback'], 'callback serves guests and logged-in customers');
    same(['web', 'auth', 'throttle:40,1'], $byName['extensions.wizarr.status'], 'status check is throttled');
    same([['web', 'auth'], ['web', 'auth'], ['web', 'auth', 'throttle:120,1'], ['service' => '[0-9]+']],
        [$byName['extensions.wizarr.report'], $byName['extensions.wizarr.report.submit'], $byName['extensions.wizarr.report.poster'], \Illuminate\Support\Facades\Route::$routes[8]->wheres], 'reports: logged in; posters throttled, by number');
    same([['web', 'auth'], ['web', 'auth']], [$byName['extensions.wizarr.requests'], $byName['extensions.wizarr.requests.submit']], 'the Requests tab: logged in');
    same(['web', 'auth'], \Illuminate\Support\Facades\Route::$routes[0]->middleware, 'logged-in customers only');
    ok(isset(\Illuminate\Support\Facades\View::$namespaces['wizarr']), 'view namespace');

    $events = array_column(\Illuminate\Support\Facades\Event::$listeners, 0);
    ok(in_array(\App\Events\Service\Created::class, $events, true), 'checkout guard listens to Service Created');
    ok(in_array('navigation.account', $events, true), 'menu item');
    ok(in_array('footer', $events, true), 'access banner');
    ok(in_array('auth.login', $events, true), 'Discord button on the login page');
    ok(in_array('auth.register', $events, true), 'and on the sign-up page');
    ok(count(\App\Models\User::$deleting) > 0, 'listens for customers being deleted');
    ok(in_array(\App\Events\InvoiceTransaction\Updated::class, $events, true), 'listens for refunds');
    ok(in_array(\App\Events\InvoiceItem\Creating::class, $events, true) && in_array(\App\Events\ServiceUpgrade\Updated::class, $events, true), 'checks Requests at order and upgrade');

    // The scheduler is created later, when schedule:run starts.
    $schedule = $app->make(\Illuminate\Console\Scheduling\Schedule::class);
    same(2, count($schedule->events), 'two scheduled tasks: hourly, and the stream limits every minute');
    same('wizarr-retry-pending', $schedule->events[0]->description, 'named');
    same('0 * * * *', $schedule->events[0]->expression, 'hourly');
    same(true, $schedule->events[0]->withoutOverlapping, 'never two at once');
});

test('boot also works when the scheduler already exists', function () {
    $app = freshBoot(scheduleAlreadyResolved: true);
    ext(discordSettings())->boot();
    same(2, count($app->make(\Illuminate\Console\Scheduling\Schedule::class)->events), 'registered right away: the hourly run and the stream limits');
});

test('the scheduled task runs the retry', function () {
    $app = freshBoot(scheduleAlreadyResolved: false);
    ext()->boot();
    $service = service();
    $service->status = 'active';
    $service->properties()->updateOrCreate(['key' => 'wizarr_pending'], ['name' => 'x', 'value' => 'suspend']);
    Service::$all = [$service];

    $event = $app->make(\Illuminate\Console\Scheduling\Schedule::class)->events[0];
    ($event->callback)();
    ok(!isset($service->propertyArray()['wizarr_pending']), 'stale marker on an active service dropped by the scheduled run');
    Service::$all = [];
});

test('boot: the shop gate is only switched on when asked for', function () {
    $app = freshBoot(scheduleAlreadyResolved: false);
    ext(discordSettings())->boot();
    same([], $app->make('router')->middlewareGroups, 'off by default');
    ok(!$app->bound('wizarr.shopLocked'), 'no theme flag');

    $app = freshBoot(scheduleAlreadyResolved: false);
    ext(discordSettings(['discord_gate_shop' => '1']))->boot();
    same(['web' => [\Paymenter\Extensions\Servers\Wizarr\ShopGate::class]], $app->make('router')->middlewareGroups, 'gate on the web group');
    ok($app->bound('wizarr.shopLocked'), 'theme can ask whether the shop is locked');
    same(['category.show', 'products.show', 'products.checkout', 'cart'], \Paymenter\Extensions\Servers\Wizarr\ShopGate::GATED_ROUTES, 'only the shop pages');

    $app = freshBoot(scheduleAlreadyResolved: false);
    ext(['discord_gate_shop' => '1'])->boot();
    same([], $app->make('router')->middlewareGroups, 'never without working Discord settings');
});

test('without Discord settings there is no Discord menu item', function () {
    freshBoot(scheduleAlreadyResolved: false);
    ext()->boot();
    ok(!in_array('navigation.account', array_column(\Illuminate\Support\Facades\Event::$listeners, 0), true), 'no menu item');
});

/* ---------- shop gate ---------- */

function access(bool $loggedIn, bool $staff, ?string $discordId, $member): string
{
    return ext(discordSettings())->shopAccess($loggedIn, $staff, $discordId, function () use ($member) {
        if ($member instanceof Throwable) {
            throw $member;
        }

        return $member;
    });
}

test('shop gate: not logged in -> log in first', function () {
    same('login', access(false, false, null, true), 'login');
});

test('shop gate: staff always get in, Discord or not', function () {
    same('allow', access(true, true, null, false), 'admin');
});

test('shop gate: logged in without Discord -> connect it', function () {
    same('connect', access(true, false, null, true), 'connect');
});

test('shop gate: member gets in, non-member is sent to join', function () {
    same('allow', access(true, false, '4242', true), 'member');
    same('join', access(true, false, '4242', false), 'not a member');
});

test('shop gate: Discord down -> "try again", never silently open', function () {
    same('unavailable', access(true, false, '4242', new \Paymenter\Extensions\Servers\Wizarr\DiscordException('down')), 'closed');
    same(1, count(Log::$warnings), 'logged');
});

/* ---------- Discord roles ---------- */

function memberRoles(string $user = '4242'): array
{
    return state()['discord']['member_roles'][$user] ?? [];
}

function discordMember(array $roles = []): void
{
    stub('/_test/set', ['discord' => ['members' => ['999', '4242'], 'member_roles' => ['4242' => $roles]]]);
}

test('roles: an active subscription gives its role, and it is recorded as ours', function () {
    discordMember();
    $granted = ext(discordSettings())->syncDiscordRoles('4242', ['500'], []);
    same(['500'], memberRoles(), 'role given');
    same(['500'], $granted, 'recorded');
});

test('roles: trial ends and paid begins -> Trial taken back, Paid user given', function () {
    discordMember(['600']);
    $granted = ext(discordSettings())->syncDiscordRoles('4242', ['500'], ['600']);
    same(['500'], memberRoles(), 'swapped');
    same(['500'], $granted, 'recorded');
});

test('roles: subscription ends -> our role is taken back', function () {
    discordMember(['500']);
    same([], ext(discordSettings())->syncDiscordRoles('4242', [], ['500']), 'nothing left');
    same([], memberRoles(), 'removed');
});

test('roles: a role you gave by hand is never taken back', function () {
    discordMember(['500']);                                    // given manually before the purchase
    $granted = ext(discordSettings())->syncDiscordRoles('4242', ['500'], []);
    same([], $granted, 'not recorded as ours');
    ext(discordSettings())->syncDiscordRoles('4242', [], $granted); // subscription ends
    same(['500'], memberRoles(), 'still has it');
});

test('roles: a role above the bot cannot be given; the reason is logged, nothing recorded', function () {
    discordMember();
    same([], ext(discordSettings())->syncDiscordRoles('4242', ['700'], []), 'not recorded');
    ok(str_contains(Log::$warnings[0] ?? '', 'Manage Roles'), 'tells you what to fix: ' . json_encode(Log::$warnings));
});

test('roles: without "Manage Roles" the log says so, not "invite the bot"', function () {
    discordMember();
    stub('/_test/set', ['discord' => ['manage_roles' => false]]);
    same([], ext(discordSettings())->syncDiscordRoles('4242', ['500'], []), 'not given');
    ok(str_contains(Log::$warnings[0] ?? '', 'Manage Roles'), 'names the permission: ' . json_encode(Log::$warnings));
    ok(!str_contains(Log::$warnings[0] ?? '', 'Invite the bot'), 'no misleading advice');
});

test('roles: a role that cannot be taken back stays recorded, for the next hourly try', function () {
    discordMember(['500']);
    stub('/_test/set', ['discord' => ['manage_roles' => false]]);
    same(['500'], ext(discordSettings())->syncDiscordRoles('4242', [], ['500']), 'kept for retry');
    stub('/_test/set', ['discord' => ['manage_roles' => true]]);
    same([], ext(discordSettings())->syncDiscordRoles('4242', [], ['500']), 'retry works');
    same([], memberRoles(), 'removed');
});

test('roles: someone who left the server has nothing to sync', function () {
    stub('/_test/set', ['discord' => ['members' => ['999']]]);
    same([], ext(discordSettings())->syncDiscordRoles('4242', ['500'], ['600']), 'nothing');
});

test('roles: the product setting lists only roles a bot can hand out, highest first', function () {
    $fields = array_column(ext(discordSettings())->getProductConfig(), null, 'name');
    $role = $fields['discord_role_id'];
    same('select', $role['type'], 'a list');
    same(['', '700', '500', '600'], array_column($role['options'], 'value'), 'no @everyone, no bot role');
});

/** A customer with a connected Discord account and one subscription on a product with a role. */
function customerWithRole(string $role = '500'): Service
{
    discordMember();
    $service = service();
    $service->user->properties()->updateOrCreate(['key' => 'discord_id'], ['name' => 'Discord ID', 'value' => '4242']);
    $service->product = (object) ['server' => (object) ['extension' => 'Wizarr'], 'settings' => settings(['discord_role_id' => $role])];
    $service->user->serviceList = [$service];

    return $service;
}

test('roles follow the subscription: paid, overdue, paid again, cancelled', function () {
    $service = customerWithRole();
    $hooks = ext(discordSettings());
    $s = settings(['discord_role_id' => '500']);

    $hooks->createServer($service, $s, $service->propertyArray());
    same(['500'], memberRoles(), 'first payment gives the role');
    same('500', $service->user->properties()->where('key', 'discord_roles_granted')->value('value'), 'recorded on the customer');

    redeem(grantProp($service, 'wizarr_invite_code'), 'mette');
    $hooks->suspendServer($service, $s, $service->propertyArray());
    same([], memberRoles(), 'overdue takes it back');

    $hooks->unsuspendServer($service, $s, $service->propertyArray());
    same(['500'], memberRoles(), 'paying again gives it back');

    $hooks->terminateServer($service, $s, $service->propertyArray());
    same([], memberRoles(), 'cancelled takes it back');
    same(0, count(Log::$warnings), 'no problems along the way: ' . json_encode(Log::$warnings));
});

test('roles never break a payment: Discord down while creating still creates the invitation', function () {
    $service = customerWithRole();
    stub('/_test/set', ['discord' => ['down' => true]]);
    $data = ext(discordSettings())->createServer($service, settings(['discord_role_id' => '500']), []);
    ok(str_starts_with($data['invite_url'], 'https://join.example.com/j/'), 'invitation created');
    same(1, count(Log::$warnings), 'role problem logged for the hourly retry');
});

/* ---------- Seerr add-on ---------- */

function seerrSettings(array $overrides = []): array
{
    global $STUB;

    return $overrides + ['seerr_url' => $STUB . '/seerr', 'seerr_api_key' => 'seerr-key', 'seerr_public_url' => 'https://requests.example.com'];
}

function seerrUsers(): array
{
    return state()['seerr']['users'];
}

/** A subscription whose customer has created their Jellyfin user, with or without the add-on. */
function seerrCustomer(bool $addOn, string $username = 'mette'): Service
{
    $service = subscribed(settings(), $username);
    $service->product = (object) ['server' => (object) ['extension' => 'Wizarr'], 'settings' => settings()];
    if ($addOn) {
        $service->configs = ['seerr' => 'ja'];
    }

    return $service;
}

function seerrSync(Service $service, string $state, array $productSettings = []): void
{
    ext(seerrSettings())->syncSeerr($service, $productSettings + settings(), ExtensionHelperProps($service), $state);
}

function ExtensionHelperProps(Service $service): array
{
    return \App\Helpers\ExtensionHelper::getServiceProperties($service);
}

test('seerr: the add-on is bought as an option, or included in the product', function () {
    $w = ext();
    same(false, $w->wantsSeerr([], []), 'nothing');
    same(true, $w->wantsSeerr([], ['seerr' => 'Ja']), 'option "Ja"');
    same(false, $w->wantsSeerr([], ['seerr' => 'nej']), 'option "Nej"');
    same(true, $w->wantsSeerr(['seerr_included' => '1'], []), 'included');
});

test('seerr: with the add-on the customer is imported into Seerr, with Seerr\'s default permissions', function () {
    $service = seerrCustomer(addOn: true);
    seerrSync($service, 'active');

    $mine = array_values(array_filter(seerrUsers(), fn ($u) => $u['jellyfinUserId'] === 'jf-mette'));
    same(1, count($mine), 'imported');
    same(32, $mine[0]['permissions'], 'default permissions');
    same((string) $mine[0]['id'], $service->propertyArray()['seerr_user_id'] ?? null, 'recorded on the service');
});

test('seerr: without the add-on nobody is imported', function () {
    $service = seerrCustomer(addOn: false);
    seerrSync($service, 'active');
    same(1, count(seerrUsers()), 'only the owner');
});

test('seerr: nothing happens before the customer has created their user', function () {
    $service = service();
    ext()->createServer($service, settings(), []);
    $service->configs = ['seerr' => 'ja'];
    seerrSync($service, 'active');
    same(1, count(seerrUsers()), 'no one imported yet');
    ok(!isset($service->propertyArray()['seerr_user_id']), 'nothing recorded');
});

test('seerr: suspended -> no permissions (account and history kept); paid again -> permissions back', function () {
    $service = seerrCustomer(addOn: true);
    seerrSync($service, 'active');
    $id = (int) $service->propertyArray()['seerr_user_id'];
    $perm = fn () => array_column(seerrUsers(), 'permissions', 'id')[$id];

    seerrSync($service, 'suspended');
    same(0, $perm(), 'nothing allowed while suspended');
    same('32', $service->propertyArray()['seerr_saved_permissions'] ?? null, 'previous permissions saved');

    seerrSync($service, 'suspended');  // the hourly run again
    same('32', $service->propertyArray()['seerr_saved_permissions'] ?? null, 'not overwritten with 0');

    seerrSync($service, 'active');
    same(32, $perm(), 'restored');
    ok(!isset($service->propertyArray()['seerr_saved_permissions']), 'saved value cleared');
});

test('seerr: cancelled -> Seerr account deleted', function () {
    $service = seerrCustomer(addOn: true);
    seerrSync($service, 'active');
    seerrSync($service, 'ended');
    same(1, count(seerrUsers()), 'only the owner left');
    ok(!isset($service->propertyArray()['seerr_user_id']), 'forgotten');
});

test('seerr: add-on bought later through an upgrade, then dropped again', function () {
    $service = seerrCustomer(addOn: false);
    $service->properties()->updateOrCreate(['key' => 'wizarr_users'], ['name' => 'x', 'value' => '1:mette:jellyfin']);
    $hooks = ext(seerrSettings());

    $service->configs = ['seerr' => 'ja'];               // Paymenter has applied the paid upgrade
    same(true, $hooks->upgradeServer($service, settings(), ExtensionHelperProps($service)), 'upgrade hook exists and succeeds');
    same(2, count(seerrUsers()), 'imported');

    $service->configs = ['seerr' => 'nej'];              // downgraded
    $hooks->upgradeServer($service, settings(), ExtensionHelperProps($service));
    same(1, count(seerrUsers()), 'removed again');
});

test('seerr: a customer who already had a Seerr account is left alone', function () {
    $service = seerrCustomer(addOn: true);
    $service->configs = ['seerr' => '5'];
    stub('/_test/set', ['seerr' => ['users' => [['id' => 1, 'jellyfinUserId' => 'jf-owner', 'permissions' => 2], ['id' => 7, 'jellyfinUserId' => 'jf-mette', 'permissions' => 32]], 'next_id' => 8]]);
    seerrSync($service, 'active');
    ok(!isset($service->propertyArray()['seerr_user_id']), 'not taken over');
    same(0, quotaPosts(), 'and never given a request limit');
    seerrSync($service, 'ended');
    same(2, count(seerrUsers()), 'their own account is not deleted');
});

test('seerr: an account deleted by hand in Seerr is imported again', function () {
    $service = seerrCustomer(addOn: true);
    seerrSync($service, 'active');
    stub('/_test/set', ['seerr' => ['users' => [['id' => 1, 'jellyfinUserId' => 'jf-owner', 'permissions' => 2]]]]);
    seerrSync($service, 'active');
    same(2, count(seerrUsers()), 'back');
});

test('seerr: never creates access while suspended', function () {
    $service = seerrCustomer(addOn: true);
    seerrSync($service, 'suspended');
    same(1, count(seerrUsers()), 'no import');
});

test('seerr: problems never break a payment hook and are logged with the fix', function () {
    $service = seerrCustomer(addOn: true);
    stub('/_test/set', ['seerr' => ['csrf' => true]]);
    $service->product = (object) ['server' => (object) ['extension' => 'Wizarr'], 'settings' => settings()];
    $hooks = ext(seerrSettings());
    same(true, $hooks->upgradeServer($service, settings(), ExtensionHelperProps($service)), 'hook still succeeds');
    ok(str_contains(Log::$warnings[0] ?? '', 'CSRF'), 'tells you to turn CSRF off: ' . json_encode(Log::$warnings));
});

test('seerr: Test Connection checks the key and the Jellyfin link', function () {
    same(true, ext(seerrSettings())->testConfig(), 'ok');
    $bad = ext(seerrSettings(['seerr_api_key' => 'wrong']))->testConfig();
    ok(is_string($bad) && str_contains($bad, 'API key'), 'bad key: ' . json_encode($bad));
    $half = ext(['seerr_url' => 'http://x'])->testConfig();
    ok(is_string($half) && str_contains($half, 'incomplete'), 'half filled in: ' . json_encode($half));
});

test('seerr: customers with the add-on get a button to Seerr in the client area', function () {
    $service = seerrCustomer(addOn: true);
    seerrSync($service, 'active');
    ext(seerrSettings())->getActions($service, settings(), $service->propertyArray()); // records users
    $actions = ext(seerrSettings())->getActions($service, settings(), $service->propertyArray());
    ok(in_array('button:Request movies and shows', labels($actions), true), 'button: ' . json_encode(labels($actions)));

    $without = seerrCustomer(addOn: false, username: 'anders');
    $actions = ext(seerrSettings())->getActions($without, settings(), $without->propertyArray());
    ok(!in_array('button:Request movies and shows', labels($actions), true), 'no button without the add-on');
});

/* ---------- log ind med Discord ---------- */

function signIn(bool $linked = false, ?string $email = 'mette@example.com', bool $verified = true, bool $taken = false, bool $open = true): array
{
    return ext(discordSettings())->discordSignInDecision($linked, $email, $verified, $taken, $open);
}

test('discord login: a linked account just logs in, whatever else is true', function () {
    same(['login'], signIn(linked: true, email: null, verified: false, taken: true, open: false), 'login');
});

test('discord login: a new Discord account with a verified, unused e-mail gets an account', function () {
    same(['create'], signIn(), 'create');
});

test('discord login: never links by e-mail to an existing account', function () {
    [$action, $message] = signIn(taken: true);
    same('refuse', $action, 'refused');
    ok(str_contains($message, 'connect Discord'), 'tells them how: ' . $message);
});

test('discord login: an unverified or missing Discord e-mail is refused', function () {
    same('refuse', signIn(verified: false)[0], 'unverified');
    same('refuse', signIn(email: null)[0], 'no e-mail');
});

test('discord login: "Disable User Registration" in Paymenter is respected', function () {
    [$action, $message] = signIn(open: false);
    same('refuse', $action, 'no new accounts');
    ok(str_contains($message, "can't be created"), $message);
});

test('discord login: names come from the Discord display name', function () {
    same(['Mette', 'Hansen'], ext()->namesFromDiscord('Mette Hansen'), 'two words');
    same(['Mette', 'Louise Hansen'], ext()->namesFromDiscord('  Mette Louise Hansen '), 'first word, then the rest');
    same(['mette', ''], ext()->namesFromDiscord('mette'), 'single name: an empty last name, never null');
    same(['Discord', ''], ext()->namesFromDiscord(''), 'never empty');
});

test('discord login: the button escapes what it prints and shows the terms when set', function () {
    $html = ext()->discordLoginButton('https://pay.example.com/wizarr/discord/login', 'https://example.com/tos"><script>');
    ok(str_contains($html, 'Log in or create an account with Discord'), 'default label');
    ok(str_contains(ext()->discordLoginButton('https://x/login', '', 'Create an account with Discord'), '>Create an account with Discord</a>'), 'own label');
    ok(!str_contains(ext()->discordLoginButton('https://x/login', '', '<b>x</b>'), '<b>'), 'label escaped');
    ok(str_contains($html, '>terms</a>'), 'terms');
    ok(!str_contains($html, '"><script>'), 'escaped');
    ok(!str_contains(ext()->discordLoginButton('https://x/login', ''), 'terms'), 'no terms line without a terms URL');
});

/* ---------- Seerr request packages ---------- */

function seerrPlanFor(string $value, array $productSettings = []): ?array
{
    return ext()->seerrPlan($productSettings, $value === '' ? [] : ['seerr' => $value]);
}

function seerrUser(int $id): array
{
    foreach (seerrUsers() as $user) {
        if ($user['id'] === $id) {
            return $user;
        }
    }
    throw new RuntimeException("no Seerr user $id");
}

function quotaPosts(): int
{
    return count(array_filter(state()['requests'], fn ($r) => $r['method'] === 'POST' && str_ends_with($r['path'], '/settings/main')));
}

test('seerr packages: the option\'s value is read as a request limit', function () {
    $default = ['limit' => null, 'days' => null];
    same(null, seerrPlanFor('no'), 'no');
    same(null, seerrPlanFor('nej'), 'nej');
    same(null, seerrPlanFor(''), 'nothing chosen');
    same(null, seerrPlanFor('typo'), 'unknown means no');
    same($default, seerrPlanFor('yes'), 'yes: Seerr\'s defaults');
    same($default, seerrPlanFor('1'), '1 is yes, as before');
    same(['limit' => 1, 'days' => 7], seerrPlanFor('1/7'), 'one a week');
    same(['limit' => 5, 'days' => 7], seerrPlanFor('5'), 'a number: per week');
    same(['limit' => 10, 'days' => 30], seerrPlanFor('10/30'), 'per 30 days');
    same(['limit' => 0, 'days' => null], seerrPlanFor('unlimited'), 'no limit');
    same($default, seerrPlanFor('', ['seerr_included' => true]), 'included in the product');
    same(['limit' => 5, 'days' => 7], seerrPlanFor('5', ['seerr_included' => true]), 'included, with a package chosen');
});

test('seerr packages: the bought limit is set in Seerr, and the customer\'s own settings are kept', function () {
    $service = seerrCustomer(addOn: true);
    $service->configs = ['seerr' => '5'];
    seerrSync($service, 'active');

    $user = seerrUser((int) $service->propertyArray()['seerr_user_id']);
    same([5, 7, 5, 7], [$user['movieQuotaLimit'], $user['movieQuotaDays'], $user['tvQuotaLimit'], $user['tvQuotaDays']], 'movies and TV');
    same(['mette', 'mette@example.com'], [$user['username'], $user['email']], 'name and e-mail kept');
    same(['locale' => 'da', 'discoverRegion' => 'DK', 'streamingRegion' => 'DK', 'originalLanguage' => 'da', 'watchlistSyncMovies' => true, 'watchlistSyncTv' => false],
        $user['settings'], 'their own settings kept');
});

test('seerr packages: sent only when it changes: up to unlimited, back to plain yes', function () {
    $service = seerrCustomer(addOn: true);
    seerrSync($service, 'active');
    same(0, quotaPosts(), 'plain yes on a new account: Seerr\'s defaults, nothing to send');

    $service->configs = ['seerr' => '5'];
    seerrSync($service, 'active');
    seerrSync($service, 'active');
    same(1, quotaPosts(), 'set once, not every hour');

    $service->configs = ['seerr' => 'unlimited'];
    seerrSync($service, 'active');
    $id = (int) $service->propertyArray()['seerr_user_id'];
    same(0, seerrUser($id)['movieQuotaLimit'], 'unlimited');

    $service->configs = ['seerr' => 'yes'];
    seerrSync($service, 'active');
    same([null, null], [seerrUser($id)['movieQuotaLimit'], seerrUser($id)['movieQuotaDays']], 'back to Seerr\'s defaults');
    same(3, quotaPosts(), 'three changes, three calls');
});

test('seerr packages: the client area shows the limit', function () {
    $service = seerrCustomer(addOn: true);
    $service->configs = ['seerr' => '10/30'];
    seerrSync($service, 'active');
    $labels = labels(ext(seerrSettings())->getActions($service, settings(), ExtensionHelperProps($service)));
    ok(in_array('text:Requests=10 movies and 10 TV seasons every 30 days', $labels, true), json_encode($labels));

    $service->configs = ['seerr' => 'unlimited'];
    seerrSync($service, 'active');
    $labels = labels(ext(seerrSettings())->getActions($service, settings(), ExtensionHelperProps($service)));
    ok(in_array('text:Requests=Unlimited', $labels, true), json_encode($labels));
    same('Anmodninger', inLocale('da', fn () => Wizarr::t('Requests')), 'in Danish too');
});

test('seerr packages: a suspended customer keeps the limit, and it is not sent again when they pay', function () {
    $service = seerrCustomer(addOn: true);
    $service->configs = ['seerr' => '5'];
    seerrSync($service, 'active');
    seerrSync($service, 'suspended');
    seerrSync($service, 'active');
    same(1, quotaPosts(), 'one call in all');
    same(5, seerrUser((int) $service->propertyArray()['seerr_user_id'])['movieQuotaLimit'], 'still 5');
});

/* ---------- Requests only with the media server Seerr is connected to ---------- */

/** Product with Jellyfin (1), Plex (2) and Emby (4). */
function allServers(array $overrides = []): array
{
    return settings($overrides + ['server_ids' => ['1', '2', '4'], 'library_ids' => '']);
}

/** Runs the check; returns the refusal shown to the customer, or null when allowed. */
function seerrRefusal(array $properties, string|array|null $seerrType = 'jellyfin', array $product = []): ?string
{
    try {
        ext(seerrSettings())->checkSeerrServer(allServers($product), $properties, $seerrType);

        return null;
    } catch (DisplayException $e) {
        return $e->getMessage();
    }
}

test('requests need Seerr\'s media server: allowed with it, refused without it', function () {
    same(null, seerrRefusal(['media_server' => 'jellyfin', 'seerr' => 'yes']), 'Jellyfin');
    same(null, seerrRefusal(['media_server' => 'jellyfin,emby', 'seerr' => '5']), 'Both includes Jellyfin');
    same(null, seerrRefusal(['media_server' => 'all', 'seerr' => 'unlimited']), 'All');
    same(null, seerrRefusal(['media_server' => 'emby', 'seerr' => 'no']), 'Emby without Requests');
    same('Requests need Jellyfin. Choose a media server with Jellyfin, or remove Requests.', seerrRefusal(['media_server' => 'emby', 'seerr' => 'yes']), 'Emby only');
    ok(seerrRefusal(['media_server' => 'plex', 'seerr' => '5']) !== null, 'Plex only');
    ok(seerrRefusal(['media_server' => 'emby,plex', 'seerr' => 'yes']) !== null, 'Emby + Plex');
    ok(seerrRefusal(['media_server' => 'emby'], 'jellyfin', ['seerr_included' => true]) !== null, 'Seerr included in the product counts too');
});

test('requests: Seerr connected to Emby turns it around', function () {
    same(null, seerrRefusal(['media_server' => 'emby', 'seerr' => 'yes'], 'emby'), 'Emby');
    same('Requests need Emby. Choose a media server with Emby, or remove Requests.', seerrRefusal(['media_server' => 'jellyfin', 'seerr' => 'yes'], 'emby'), 'Jellyfin only');
    same('Anmodninger kræver Emby. Vælg en medieserver med Emby, eller fjern Anmodninger.', inLocale('da', fn () => seerrRefusal(['media_server' => 'jellyfin', 'seerr' => 'yes'], 'emby')), 'in Danish');
});

test('requests: asks Seerr what it is connected to, and lets the sale through if Seerr cannot answer', function () {
    \Illuminate\Support\Facades\Cache::$store = [];
    stub('/_test/set', ['seerr' => ['media' => 'emby']]);
    same(null, seerrRefusal(['media_server' => 'emby', 'seerr' => 'yes'], null), 'Seerr says Emby');

    \Illuminate\Support\Facades\Cache::$store = [];
    Log::$warnings = [];
    try {
        ext(seerrSettings(['seerr_url' => 'http://127.0.0.1:1']))->checkSeerrServer(allServers(), ['media_server' => 'emby', 'seerr' => 'yes']);
        $refused = false;
    } catch (DisplayException $e) {
        $refused = true;
    }
    same([false, 1], [$refused, count(Log::$warnings)], 'Seerr down: allowed, and logged');
});

test('requests: checked on the order\'s invoice line and on an upgrade, never when a paid upgrade completes', function () {
    $service = service(60);
    $service->configs = ['media_server' => 'emby', 'seerr' => 'yes'];
    global $STUB;
    $serverSettings = seerrSettings(['host' => $STUB . '/', 'api_key' => 'stub-key', 'public_url' => 'https://join.example.com/']);
    $service->product = (object) ['server' => (object) ['extension' => 'Wizarr', 'settings' => $serverSettings], 'settings' => allServers()];
    $item = (object) ['reference_type' => \App\Models\Service::class, 'reference' => $service];
    $refused = fn (callable $fn) => (function () use ($fn) {
        try {
            $fn();

            return false;
        } catch (DisplayException $e) {
            return true;
        }
    })();
    \Illuminate\Support\Facades\Cache::$store = ['wizarr:seerr-media-type' => 'jellyfin'];
    ok($refused(fn () => ext()->guardSeerrForItem($item)), 'order refused');

    $upgrade = new class
    {
        public $status = 'pending';

        public $invoice_id = 5;

        public $service;

        public $product;

        public array $configs = [];

        public array $changed = ['invoice_id'];

        public function wasChanged($attribute): bool
        {
            return in_array($attribute, $this->changed, true);
        }
    };
    $upgrade->service = service(61);
    $upgrade->service->configs = ['media_server' => 'jellyfin', 'seerr' => 'yes'];
    $upgrade->product = $service->product;
    $upgrade->configs = [(object) ['configOption' => (object) ['env_variable' => 'media_server'], 'configValue' => (object) ['env_variable' => 'emby']]];
    ok($refused(fn () => ext()->guardSeerrForUpgrade($upgrade)), 'switching to Emby only, keeping Requests: refused');

    $upgrade->status = 'completed';
    $upgrade->changed = ['status'];
    ok(!$refused(fn () => ext()->guardSeerrForUpgrade($upgrade)), 'a paid upgrade completing is never stopped');
    $upgrade->invoice_id = null;
    ok($refused(fn () => ext()->guardSeerrForUpgrade($upgrade)), 'a free change completing at once is checked');
});

/* ---------- Up to three Seerr, one per media server ---------- */

/** Seerr 1 on Jellyfin, Seerr 2 on Emby, Seerr 3 on Plex (see the stub). */
function threeSeerr(array $overrides = []): array
{
    global $STUB;

    return $overrides + seerrSettings() + [
        'seerr2_url' => $STUB . '/seerr2', 'seerr2_api_key' => 'seerr2-key', 'seerr2_public_url' => 'https://requests-emby.example.com',
        'seerr3_url' => $STUB . '/seerr3', 'seerr3_api_key' => 'seerr3-key', 'seerr3_public_url' => 'https://requests-plex.example.com',
    ];
}

/** A customer with Requests who picked $choice on a product with Jellyfin, Plex and Emby, users created. */
function requestsCustomer(string $choice, int $id = 70): Service
{
    $service = service($id, "customer$id@example.com");
    $service->configs = ['media_server' => $choice, 'seerr' => 'yes'];
    $service->product = (object) ['server' => (object) ['extension' => 'Wizarr', 'settings' => []], 'settings' => allServers()];
    $data = ext(threeSeerr())->createServer($service, allServers(), props($service));
    foreach ($data['invites'] as $invite) {
        redeem($invite['code'], "user$id", "customer$id@example.com");
    }
    ext(threeSeerr())->getActions($service, allServers(), props($service)); // records the users

    return $service;
}

/** Customers (not the owner) on each Seerr. */
function seerrCustomers(): array
{
    $state = state();

    return array_map(fn ($name) => count($state[$name]['users']) - 1, ['seerr' => 'seerr', 'seerr2' => 'seerr2', 'seerr3' => 'seerr3']);
}

test('more Seerr: each customer gets an account on the Seerr of their own media server', function () {
    foreach (['emby' => ['seerr' => 0, 'seerr2' => 1, 'seerr3' => 0], 'plex' => ['seerr' => 0, 'seerr2' => 1, 'seerr3' => 1], 'jellyfin,emby' => ['seerr' => 1, 'seerr2' => 1, 'seerr3' => 1]] as $choice => $expected) {
        static $id = 70;
        $service = requestsCustomer($choice, $id++);
        ext(threeSeerr())->syncSeerr($service, allServers(), props($service), 'active');
        same($expected, seerrCustomers(), "after \"$choice\"");
    }
});

test('more Seerr: the Requests button goes to the customer\'s own Seerr', function () {
    $service = requestsCustomer('emby');
    ext(threeSeerr())->syncSeerr($service, allServers(), props($service), 'active');
    $buttons = array_column(array_filter(ext(threeSeerr())->getActions($service, allServers(), props($service)), fn ($a) => ($a['label'] ?? '') === 'Request movies and shows'), 'url');
    same(['https://requests-emby.example.com'], array_values($buttons), 'Emby\'s Seerr');
});

test('more Seerr: switching from Both to Emby moves the account to the Emby Seerr; cancelling deletes it there', function () {
    $service = requestsCustomer('jellyfin,emby');
    ext(threeSeerr())->syncSeerr($service, allServers(), props($service), 'active');
    same(['seerr' => 1, 'seerr2' => 0, 'seerr3' => 0], seerrCustomers(), 'on the Jellyfin Seerr');

    $service->configs['media_server'] = 'emby';
    ext(threeSeerr())->upgradeServer($service, allServers(), props($service));
    same(['seerr' => 0, 'seerr2' => 1, 'seerr3' => 0], seerrCustomers(), 'moved to the Emby Seerr');
    same('2', $service->propertyArray()['seerr_instance'] ?? null, 'remembers which Seerr');

    ext(threeSeerr())->terminateServer($service, allServers(), props($service));
    same(['seerr' => 0, 'seerr2' => 0, 'seerr3' => 0], seerrCustomers(), 'deleted where it was');
});

test('more Seerr: a Plex customer who already has a Seerr account is left alone', function () {
    stub('/_test/set', ['seerr3' => ['users' => [['id' => 1, 'jellyfinUserId' => 'jf-owner', 'permissions' => 2], ['id' => 5, 'plexId' => 1, 'email' => 'customer70@example.com', 'permissions' => 32]], 'next_id' => 6]]);
    $service = requestsCustomer('plex');
    ext(threeSeerr())->syncSeerr($service, allServers(), props($service), 'active');
    same(1, seerrCustomers()['seerr3'], 'no second account');
    ok(!isset($service->propertyArray()['seerr_user_id']), 'not taken over');
});

test('more Seerr: checkout allows a server any Seerr is connected to, and names them all otherwise', function () {
    same(null, seerrRefusal(['media_server' => 'emby', 'seerr' => 'yes'], ['jellyfin', 'emby']), 'Emby, with an Emby Seerr');
    same('Requests need Jellyfin or Emby. Choose a media server with Jellyfin or Emby, or remove Requests.',
        seerrRefusal(['media_server' => 'plex', 'seerr' => 'yes'], ['jellyfin', 'emby']), 'Plex, without a Plex Seerr');
    try {
        ext(threeSeerr())->checkSeerrServer(allServers(), ['media_server' => 'plex', 'seerr' => 'yes']);
        $ok = true;
    } catch (DisplayException $e) {
        $ok = false;
    }
    ok($ok, 'asks every Seerr: Plex is fine with a Plex Seerr');
});

test('more Seerr: Test Connection checks every Seerr, and half-filled ones', function () {
    global $STUB;
    same(true, ext(threeSeerr())->testConfig(), 'all three fine');
    $result = ext(seerrSettings(['seerr2_url' => $STUB . '/seerr2']))->testConfig();
    ok(is_string($result) && str_contains($result, 'Seerr 2 settings are incomplete'), 'got: ' . json_encode($result));
});

/* ---------- Jellyfin, Emby or both: one invitation per server ---------- */

/** A product with Jellyfin (1) and Emby (4), each with a library chosen. */
function mediaSettings(array $overrides = []): array
{
    return settings($overrides + ['server_ids' => ['1', '4'], 'library_ids' => ['10', '40']]);
}

/** A service where the customer picked $choice in the "media_server" option. */
function chosen(string $choice, int $id = 1): Service
{
    $service = service($id, "customer$id@example.com");
    $service->configs = $choice === '' ? [] : [Wizarr::MEDIA_OPTION => $choice];
    $service->product = (object) ['server' => (object) ['extension' => 'Wizarr', 'settings' => []], 'settings' => mediaSettings()];

    return $service;
}

function props(Service $service): array
{
    return $service->propertyArray() + $service->configs;
}

function userOn(int $serverId): ?array
{
    foreach (state()['users'] as $user) {
        if ($user['server_id'] === $serverId) {
            return $user;
        }
    }

    return null;
}

test('media: the customer picks Jellyfin, Emby or both, and gets one invitation per server', function () {
    $cases = ['jellyfin' => ['Jellyfin'], 'emby' => ['Emby'], 'both' => ['Jellyfin', 'Emby'], 'jellyfin,emby' => ['Jellyfin', 'Emby'], '4' => ['Emby'], '' => ['Jellyfin', 'Emby']];
    $id = 1;
    foreach ($cases as $choice => $servers) {
        $service = chosen((string) $choice, $id++);
        $data = ext()->createServer($service, mediaSettings(), props($service));
        same($servers, array_column($data['invites'], 'server'), "choice \"$choice\"");
    }
    foreach (state()['invitations'] as $invitation) {
        same(1, count($invitation['server_ids']), 'every invitation is for one server');
        same($invitation['server_ids'] === [4] ? [40] : [10], $invitation['library_ids'], 'with that server\'s own libraries');
    }
});

test('media: a choice that matches no server on the product is refused with the reason', function () {
    $service = chosen('plex');
    throws(fn () => ext()->createServer($service, mediaSettings(), props($service)), 'matches none', 'refused');
    same(0, count(state()['invitations']), 'nothing created');
});

test('media: the mail has every link: {{ $invites }}, {{ $invite_urls }} and the first as {{ $invite_url }}', function () {
    $service = chosen('both');
    $data = ext()->createServer($service, mediaSettings(), props($service));
    same(2, count($data['invites']), 'two links');
    same($data['invites'][0]['url'], $data['invite_url'], 'invite_url is the first');
    ok(preg_match('#^Jellyfin: https://join\.example\.com/j/\w+\nEmby: https://join\.example\.com/j/\w+$#', $data['invite_urls']) === 1, 'invite_urls: ' . $data['invite_urls']);
    same('7 days', $data['invite_valid_for'], 'validity as before');
});

test('media: the client area shows each server\'s link, then a reset button per server', function () {
    $service = chosen('both');
    $data = ext()->createServer($service, mediaSettings(), props($service));
    same([
        'text:Jellyfin=Waiting for you to create your user', 'button:Create your user on Jellyfin', 'button:Problems? Get a new link for Jellyfin',
        'text:Emby=Waiting for you to create your user', 'button:Create your user on Emby', 'button:Problems? Get a new link for Emby',
    ], labels(ext()->getActions($service, mediaSettings(), props($service))), 'both waiting');

    redeem($data['invites'][0]['code'], 'mette');
    same([
        'text:Status=Active', 'text:Emby=Waiting for you to create your user', 'button:Create your user on Emby', 'button:Problems? Get a new link for Emby',
        'text:Username=mette', 'button:Reset password on Jellyfin', 'button:Open Jellyfin',
    ], labels(ext()->getActions($service, mediaSettings(), props($service))), 'Jellyfin done, Emby waiting');

    redeem($data['invites'][1]['code'], 'mette');
    $actions = ext()->getActions($service, mediaSettings(), props($service));
    same([
        'text:Status=Active', 'text:Username=mette', 'button:Reset password on Jellyfin', 'button:Reset password on Emby', 'button:Open Jellyfin', 'button:Open Emby',
    ], labels($actions), 'both done');
    same(['https://jellyfin.example.com', 'https://emby.example.com'], array_column(array_filter($actions, fn ($a) => str_starts_with($a['label'] ?? '', 'Open ')), 'url'), 'each to its own server');
    same(['resetPassword1', 'resetPassword2'], array_values(array_filter(array_column(array_filter($actions, fn ($a) => $a['type'] === 'button'), 'function'))), 'numbered buttons');
    same(['view', 'overview', 'overviewView'], [$actions[0]['type'], $actions[0]['name'], $actions[0]['function']], 'the Overview tab, first');
    $service->status = 'active';
    $service->product->server->extension = 'Wizarr';
    same([['label' => 'Open Jellyfin', 'url' => 'https://jellyfin.example.com'], ['label' => 'Open Emby', 'url' => 'https://emby.example.com']],
        ext()->navWatchLinks((object) ['id' => 1], [$service]), 'the top bar: one link per server the customer has');

    ext()->resetPassword2($service, mediaSettings(), props($service));
    same(userOn(4)['id'], resetTokens()[0]['user_id'], 'button 2 resets the Emby user');
});

test('media: an Emby customer\'s "Open" button goes to Emby, never to the product\'s Jellyfin address', function () {
    $service = chosen('emby');
    $data = ext()->createServer($service, mediaSettings(), props($service));
    redeem($data['invite_code'], 'mette');
    $actions = ext()->getActions($service, mediaSettings(), props($service));
    $open = array_values(array_filter($actions, fn ($a) => ($a['url'] ?? '') !== '' && ($a['target'] ?? '') === '_blank'));
    same(['https://emby.example.com'], array_column($open, 'url'), 'Emby\'s own address');
});

test('media: once the users exist, page views make no calls to Wizarr, with one server or two', function () {
    foreach (['emby', 'both'] as $i => $choice) {
        $service = chosen($choice, 20 + $i);
        $data = ext()->createServer($service, mediaSettings(), props($service));
        foreach ($data['invites'] as $invite) {
            redeem($invite['code'], "user$i");
        }
        ext()->getActions($service, mediaSettings(), props($service)); // records the users

        stub('/_test/clear-requests');
        ext()->getActions($service, mediaSettings(), props($service));
        same([], array_column(state()['requests'], 'path'), "no calls ($choice)");
    }
});

test('media: a server without an address in Wizarr gets no "Open" button, rather than the wrong one', function () {
    stub('/_test/set', ['servers' => [4 => ['external_url' => '']]]);
    $service = chosen('both');
    $data = ext()->createServer($service, mediaSettings(), props($service));
    foreach ($data['invites'] as $invite) {
        redeem($invite['code'], 'mette');
    }
    $labels = labels(ext()->getActions($service, mediaSettings(), props($service)));
    ok(in_array('button:Open Jellyfin', $labels, true) && !in_array('button:Open Emby', $labels, true), json_encode($labels));
});

test('media: an address added in Wizarr later shows up, with https:// added when it is missing', function () {
    stub('/_test/set', ['servers' => [4 => ['external_url' => '']]]);
    $service = chosen('both');
    $data = ext()->createServer($service, mediaSettings(), props($service));
    foreach ($data['invites'] as $invite) {
        redeem($invite['code'], 'mette');
    }
    $open = fn (array $config = []) => array_column(array_filter(ext($config)->getActions($service, mediaSettings(), props($service)), fn ($a) => str_starts_with($a['label'] ?? '', 'Open ')), 'url', 'label');
    same(['Open Jellyfin' => 'https://jellyfin.example.com'], $open(), 'no Emby address yet');

    stub('/_test/set', ['servers' => [4 => ['external_url' => 'emby.example.net']]]);
    same(['Open Jellyfin' => 'https://jellyfin.example.com'], $open(), 'kept for a while, so page views don\'t ask Wizarr');
    \Illuminate\Support\Facades\Cache::$store = []; // ten minutes later
    same(['Open Jellyfin' => 'https://jellyfin.example.com', 'Open Emby' => 'https://emby.example.net'], $open(), 'then it is there, as a full address');

    \Illuminate\Support\Facades\Cache::$store = [];
    same(['Open Jellyfin' => 'https://jellyfin.example.com', 'Open Emby' => 'https://emby.example.net'], $open(['host' => 'http://127.0.0.1:1']), 'Wizarr down: the last addresses seen');
});

test('media: an expired Emby link is renewed on its own; Jellyfin is untouched', function () {
    $service = chosen('both');
    $data = ext()->createServer($service, mediaSettings(), props($service));
    redeem($data['invites'][0]['code'], 'mette');
    stub('/_test/expire', ['code' => $data['invites'][1]['code']]);

    $actions = ext()->getActions($service, mediaSettings(), props($service));
    ok(in_array('text:Emby=Your invitation link has expired', labels($actions), true), json_encode(labels($actions)));
    same(['renewInvitation2'], array_values(array_filter(array_column($actions, 'function'), fn ($f) => str_starts_with($f, 'renew'))), 'its own button');

    $url = ext()->renewInvitation2($service, mediaSettings(), props($service));
    $fresh = array_values(array_filter(state()['invitations'], fn ($i) => str_ends_with($url, $i['code'])));
    same([[4]], array_column($fresh, 'server_ids'), 'new link for Emby');
    ok(userOn(1) !== null, 'Jellyfin user still there');
});

test('media: Emby works like Jellyfin: paused when overdue, the same user back when paid, password reset', function () {
    $service = chosen('emby');
    $data = ext()->createServer($service, mediaSettings(), props($service));
    redeem($data['invite_code'], 'mette');
    ext()->getActions($service, mediaSettings(), props($service));
    $id = userOn(4)['id'];

    ext()->suspendServer($service, mediaSettings(), props($service));
    same(true, userOn(4)['is_disabled'], 'paused');
    ext()->unsuspendServer($service, mediaSettings(), props($service));
    same([false, $id], [userOn(4)['is_disabled'], userOn(4)['id']], 'same user, on again');

    ext()->resetPassword($service, mediaSettings(), props($service));
    same($id, resetTokens()[0]['user_id'], 'password reset');
});

test('media: overdue with both: users paused, an unused link withdrawn; paid: back on, and that link sent again', function () {
    \App\Helpers\NotificationHelper::$sent = [];
    $service = chosen('both');
    $data = ext()->createServer($service, mediaSettings(), props($service));
    redeem($data['invites'][0]['code'], 'mette');
    ext()->getActions($service, mediaSettings(), props($service));

    ext()->suspendServer($service, mediaSettings(), props($service));
    same(true, userOn(1)['is_disabled'], 'Jellyfin paused');
    same(0, count(array_filter(state()['invitations'], fn ($i) => $i['server_ids'] === [4])), 'unused Emby link withdrawn');

    ext()->unsuspendServer($service, mediaSettings(), props($service));
    same(false, userOn(1)['is_disabled'], 'Jellyfin back on');
    same(1, count(array_filter(state()['invitations'], fn ($i) => $i['server_ids'] === [4])), 'a new Emby link');
    same(1, count(\App\Helpers\NotificationHelper::$sent), 'mailed');
    same(['Emby'], array_column(\App\Helpers\NotificationHelper::$sent[0]['data']['invites'], 'server'), 'with the Emby link');
});

test('media: switching later: adding Emby sends its link, dropping Jellyfin removes that user', function () {
    \App\Helpers\NotificationHelper::$sent = [];
    $service = chosen('jellyfin');
    $data = ext()->createServer($service, mediaSettings(), props($service));
    redeem($data['invite_code'], 'mette');
    ext()->getActions($service, mediaSettings(), props($service));

    $service->configs = [Wizarr::MEDIA_OPTION => 'both'];
    ext()->upgradeServer($service, mediaSettings(), props($service));
    same(1, count(array_filter(state()['invitations'], fn ($i) => $i['server_ids'] === [4])), 'Emby invitation');
    same(['Emby'], array_column(\App\Helpers\NotificationHelper::$sent[0]['data']['invites'] ?? [], 'server'), 'mailed');
    ok(userOn(1) !== null && !userOn(1)['is_disabled'], 'Jellyfin untouched');

    $service->configs = [Wizarr::MEDIA_OPTION => 'emby'];
    ext()->upgradeServer($service, mediaSettings(), props($service));
    same(null, userOn(1), 'Jellyfin user removed');
    same(['4'], ext()->grantKeys(props($service)), 'only Emby left');
    same(1, count(state()['invitations']), 'the Emby link is kept');
});

test('media: a pre-1.11 subscription can add Emby; its Jellyfin user stays where it is', function () {
    $service = legacySubscription(settings());
    $service->configs = [Wizarr::MEDIA_OPTION => 'both'];
    ext()->upgradeServer($service, mediaSettings(), props($service));

    same(['', '4'], ext()->grantKeys(props($service)), 'old grant kept, Emby added');
    same(2, count(state()['invitations']), 'one new invitation');
    ok(userOn(1) !== null, 'Jellyfin user kept');
    same('1:mette:jellyfin', $service->propertyArray()['wizarr_users'] ?? null, 'still recorded the old way');
});

test('media: Emby added to the product later is not handed out for free', function () {
    // Bought when the product only had Jellyfin; no choice made.
    $service = chosen('');
    $data = ext()->createServer($service, settings(), props($service));
    redeem($data['invite_code'], 'mette');
    ext()->getActions($service, settings(), props($service));
    // An old subscription: one invitation, no record of its servers.
    $old = legacySubscription(settings(), 'ole', 2);

    foreach ([$service, $old] as $s) {
        ext()->suspendServer($s, mediaSettings(), props($s));
        ext()->unsuspendServer($s, mediaSettings(), props($s));
        ext()->upgradeServer($s, mediaSettings(), props($s));
    }
    // ... and one that is only upgraded (e.g. adds Seerr), never suspended.
    $other = legacySubscription(settings(), 'kim', 3);
    ext()->upgradeServer($other, mediaSettings(), props($other));
    same(0, count(array_filter(state()['invitations'], fn ($i) => in_array(4, $i['server_ids'], true))), 'no Emby invitation for either');
});

test('media: a pre-1.11 link withdrawn when overdue comes back for the same server only', function () {
    // Never redeemed, overdue, and the product has Emby by the time they pay.
    global $STUB;
    $invitation = (new \Paymenter\Extensions\Servers\Wizarr\WizarrClient($STUB . '/', 'stub-key'))->createInvitation(['server_ids' => [1], 'duration' => 'unlimited', 'unlimited' => false]);
    $service = service(3);
    foreach (['wizarr_invitation_id' => (string) $invitation['id'], 'wizarr_invite_code' => $invitation['code'], 'wizarr_invite_url' => 'https://join.example.com/j/' . $invitation['code']] as $key => $value) {
        $service->properties()->updateOrCreate(['key' => $key], ['name' => $key, 'value' => $value]);
    }

    ext()->suspendServer($service, mediaSettings(), props($service));
    ext()->unsuspendServer($service, mediaSettings(), props($service));
    same([[1]], array_column(state()['invitations'], 'server_ids'), 'a new Jellyfin link, no Emby');
});

test('media: the banner shows the first server still waiting, and moves on when it is used', function () {
    $service = chosen('both');
    $data = ext()->createServer($service, mediaSettings(), props($service));
    $state = ext()->bannerState($service, props($service));
    same(['ready', 'Jellyfin', $data['invites'][0]['url']], [$state['state'], $state['server'] ?? null, $state['inviteUrl']], 'Jellyfin first');
    ok(str_contains(ext()->bannerHtml($state), 'Create your user on Jellyfin'), 'button names the server');

    redeem($data['invites'][0]['code'], 'mette');
    $live = ext()->liveBannerState($service, props($service));
    same(['ready', 'Emby', $data['invites'][1]['url']], [$live['state'], $live['server'] ?? null, $live['inviteUrl']], 'then Emby');

    redeem($data['invites'][1]['code'], 'mette');
    same('done', ext()->liveBannerState($service, props($service))['state'], 'then done');
});

test('media: cancelling removes the users and links on every server', function () {
    $service = chosen('both');
    $data = ext()->createServer($service, mediaSettings(), props($service));
    redeem($data['invites'][0]['code'], 'mette');
    ext()->getActions($service, mediaSettings(), props($service));

    ext()->terminateServer($service, mediaSettings(), props($service));
    same([0, 0], [count(state()['users']), count(state()['invitations'])], 'nothing left in Wizarr');
    same([], ext()->grantKeys(props($service)), 'nothing left on the service');
});

test('media: Seerr connected to Emby imports the customer\'s Emby user', function () {
    stub('/_test/set', ['seerr' => ['media' => 'emby']]);
    $service = chosen('both');
    $data = ext()->createServer($service, mediaSettings(), props($service));
    redeem($data['invites'][0]['code'], 'jellyuser');
    redeem($data['invites'][1]['code'], 'embyuser');
    ext()->getActions($service, mediaSettings(), props($service));

    ext(seerrSettings())->syncSeerr($service, mediaSettings(['seerr_included' => true]), props($service), 'active');
    same(['jf-owner', 'jf-embyuser'], array_column(seerrUsers(), 'jellyfinUserId'), 'the Emby user, the one Seerr knows');
});

test('media: a trial on both servers hands both users to the subscription bought after it', function () {
    $trial = chosen('both');
    $trial->product = (object) ['server' => (object) ['extension' => 'Wizarr'], 'settings' => mediaSettings(['trial_days' => '7'])];
    $data = ext()->createServer($trial, mediaSettings(['trial_days' => '7']), props($trial));
    redeem($data['invites'][0]['code'], 'mette');
    redeem($data['invites'][1]['code'], 'mette');
    $trial->user->serviceList[] = $trial;
    ext()->getActions($trial, mediaSettings(['trial_days' => '7']), props($trial));

    $paid = new Service(99, $trial->user);
    $paid->configs = [Wizarr::MEDIA_OPTION => 'both'];
    $paid->product = (object) ['server' => (object) ['extension' => 'Wizarr'], 'settings' => mediaSettings()];
    $before = count(state()['invitations']);
    ext()->createServer($paid, mediaSettings(), props($paid));

    same($before, count(state()['invitations']), 'no new invitation');
    same(['1', '4'], ext()->grantKeys(props($paid)), 'both servers moved over');
    $left = array_filter($trial->propertyArray(), fn ($key) => str_starts_with($key, 'wizarr_users'), ARRAY_FILTER_USE_KEY);
    same([], $left, 'the trial lets go of the users');
    same([false, false], [userOn(1)['is_disabled'], userOn(4)['is_disabled']], 'both on');
});

/* ---------- refunds ---------- */

/** A transaction as Paymenter's Refund button leaves it: refunded_amount just changed. */
function transaction(float $amount, float $refunded, $status = 'succeeded', bool $refundJustChanged = true): object
{
    return new class($amount, $refunded, $status, $refundJustChanged)
    {
        public ?object $invoice = null;

        public function __construct(public float $amount, public float $refunded_amount, public $status, private bool $changed) {}

        public function wasChanged($attribute): bool
        {
            return $this->changed && $attribute === 'refunded_amount';
        }
    };
}

/** An invoice for $services, paid by $transactions; the first transaction is the one just refunded. */
function refundedInvoice(array $services, array $transactions, string $referenceType = \App\Models\Service::class): object
{
    $invoice = (object) [
        'transactions' => $transactions,
        'items' => array_map(fn ($s) => (object) ['reference_type' => $referenceType, 'reference' => $s], $services),
    ];
    foreach ($transactions as $t) {
        $t->invoice = $invoice;
    }

    return $transactions[0];
}

function wizarrService(int $id, array $serverSettings = [], string $extension = 'Wizarr'): \App\Models\Service
{
    $service = service($id, "customer$id@example.com");
    $service->product = (object) ['server' => (object) ['extension' => $extension, 'settings' => $serverSettings], 'settings' => settings()];

    return $service;
}

test('refund: an invoice refunded in full suspends the access it paid for', function () {
    $service = wizarrService(41);
    $suspended = [];
    $result = ext()->afterRefund(refundedInvoice([$service], [transaction(99, 99)]), function ($s) use (&$suspended) { $suspended[] = $s->id; });
    same([41], $result, 'reported');
    same([41], $suspended, 'suspended');
});

test('refund: a partial refund, or one of two payments refunded, changes nothing', function () {
    $spy = function () { throw new RuntimeException('must not suspend'); };
    same([], ext()->afterRefund(refundedInvoice([wizarrService(42)], [transaction(99, 20)]), $spy), 'partial');
    same([], ext()->afterRefund(refundedInvoice([wizarrService(43)], [transaction(99, 99), transaction(99, 0)]), $spy), 'double charge, one refunded');
    same([], ext()->afterRefund(refundedInvoice([wizarrService(44)], [transaction(99, 99, 'succeeded', false)]), $spy), 'a later change to a refunded payment (e.g. its fee) is not a new refund');
    same([], ext()->afterRefund(refundedInvoice([wizarrService(45)], [transaction(99, 99, 'failed')]), $spy), 'nothing was paid');
});

test('refund: the setting, other products, upgrades and inactive services are left alone', function () {
    $spy = function () { throw new RuntimeException('must not suspend'); };
    same([], ext()->afterRefund(refundedInvoice([wizarrService(46, ['refund_action' => 'nothing'])], [transaction(99, 99)]), $spy), '"Do nothing"');
    same([], ext()->afterRefund(refundedInvoice([wizarrService(47, [], 'Pterodactyl')], [transaction(99, 99)]), $spy), 'another extension');
    same([], ext()->afterRefund(refundedInvoice([wizarrService(48)], [transaction(99, 99)], 'App\Models\ServiceUpgrade'), $spy), 'an upgrade (e.g. Seerr)');
    $suspended = wizarrService(49);
    $suspended->status = 'suspended';
    same([], ext()->afterRefund(refundedInvoice([$suspended], [transaction(99, 99)]), $spy), 'already suspended');
});

test('refund: Paymenter\'s status enum is understood, and cents do not count as kept', function () {
    $status = \App\Enums\InvoiceTransactionStatus::Succeeded;
    ok(ext()->fullyRefunded([transaction(99.99, 99.99, $status)]), 'enum status');
    ok(ext()->fullyRefunded([transaction(10.10, 10.1)]), 'float rounding');
    ok(!ext()->fullyRefunded([transaction(10.10, 10.09)]), 'a cent kept is a partial refund');
    ok(!ext()->fullyRefunded([]), 'no payments');
});

/* ---------- alerts ---------- */

/** A paid Wizarr subscription that got $age seconds ago and has no invitation yet. */
function waitingService(int $id, int $age): \App\Models\Service
{
    $service = wizarrService($id);
    $service->updated_at = new DateTimeImmutable('@' . (time() - $age));

    return $service;
}

/** Records what reportHealth() sends instead of sending it. */
function recorder(array &$sent, bool $fail = false): Closure
{
    return function (string $method, string $url, array $body) use (&$sent, $fail) {
        $sent[] = [$method, $url, $body];
        if ($fail) {
            throw new RuntimeException('Discord said no');
        }
    };
}

const WEBHOOK = 'https://discord.com/api/webhooks/1/abc';

test('alerts: all well -> nothing posted, but the heartbeat is opened', function () {
    \Illuminate\Support\Facades\Cache::$store = [];
    $sent = [];
    $posted = ext(['alert_webhook' => WEBHOOK, 'heartbeat_url' => 'https://kuma.example.com/api/push/x'])
        ->reportHealth(['failed' => 0, 'errors' => []], [subscribed(settings())], recorder($sent));
    same(null, $posted, 'nothing to say');
    same([['get', 'https://kuma.example.com/api/push/x', []]], $sent, 'only the heartbeat');
});

test('alerts: an invitation stuck for over 15 minutes points at the queue', function () {
    $problems = ext()->healthProblems([], [waitingService(51, 20 * 60), waitingService(52, 5 * 60), wizarrService(53, [], 'Pterodactyl')]);
    same(1, count($problems), 'one problem: ' . json_encode($problems));
    ok(str_contains($problems[0], '#51') && !str_contains($problems[0], '#52') && !str_contains($problems[0], '#53'), 'only the old one of ours');
    ok(str_contains($problems[0], 'queue worker'), 'says where to look');
});

test('alerts: failing suspensions and an unreachable Wizarr are reported', function () {
    $problems = ext()->healthProblems(['failed' => 1, 'errors' => [7 => 'Wizarr answered 503']], []);
    ok(str_contains($problems[0] ?? '', '#7') && str_contains($problems[0], 'still have access') && str_contains($problems[0], '503'), 'failing suspension: ' . json_encode($problems));
    $down = ext(['host' => 'http://127.0.0.1:1'])->healthProblems([], []);
    ok(count($down) === 1 && str_starts_with($down[0], 'Wizarr cannot be reached'), 'Wizarr down: ' . json_encode($down));
    $many = ext()->healthProblems(['errors' => array_fill_keys(range(1, 8), 'x')], []);
    ok(str_contains($many[0], '#1, #2, #3, #4, #5 and 3 more'), 'long lists are cut: ' . $many[0]);
});

test('alerts: posted once, again only when it changes or a day later, and "all good" once', function () {
    \Illuminate\Support\Facades\Cache::$store = [];
    $sent = [];
    $w = ext(['alert_webhook' => WEBHOOK]);
    $stuck = [waitingService(54, 3600)];
    $t = time();

    ok($w->reportHealth([], $stuck, recorder($sent), $t) !== null, 'first time: posted');
    same(null, $w->reportHealth([], $stuck, recorder($sent), $t + 3600), 'an hour later: quiet');
    ok($w->reportHealth(['errors' => [9 => 'x']], $stuck, recorder($sent), $t + 7200) !== null, 'a new problem: posted');
    ok($w->reportHealth(['errors' => [9 => 'x']], $stuck, recorder($sent), $t + 7200 + 86400) !== null, 'a day later: reminded');
    same('**Paywarr: all good again.** The problems reported earlier are gone.', $w->reportHealth([], [], recorder($sent), $t + 90000 + 7200), 'recovered');
    same(null, $w->reportHealth([], [], recorder($sent), $t + 90000 + 10800), 'and then quiet');
    same(4, count($sent), 'four messages in all');
    same(['parse' => []], $sent[0][2]['allowed_mentions'] ?? null, 'never pings anyone');
    same(WEBHOOK, $sent[0][1], 'to the webhook');
});

test('alerts: a broken webhook never breaks the hourly run', function () {
    \Illuminate\Support\Facades\Cache::$store = [];
    Log::$warnings = [];
    $sent = [];
    ext(['alert_webhook' => WEBHOOK, 'heartbeat_url' => 'https://kuma.example.com/x'])
        ->reportHealth([], [waitingService(55, 3600)], recorder($sent, fail: true));
    same(2, count($sent), 'webhook and heartbeat both tried');
    same(2, count(Log::$warnings), 'both failures logged');
});

/* ---------- languages ---------- */

/** Runs $fn with Paymenter set to $locale, then back to English. */
function inLocale(string $locale, callable $fn)
{
    app()->setLocale($locale);
    try {
        return $fn();
    } finally {
        app()->setLocale('en');
    }
}

test('languages: English by default, Danish when Paymenter is set to Danish', function () {
    same('Reset password', Wizarr::t('Reset password'), 'English');
    same('Nulstil adgangskode', inLocale('da', fn () => Wizarr::t('Reset password')), 'Danish');
    same('Nulstil adgangskode', inLocale('da_DK', fn () => Wizarr::t('Reset password')), 'da_DK uses da');
    same('Reset password', inLocale('de', fn () => Wizarr::t('Reset password')), 'no German file: English');
    same('Reset password', inLocale('../../x', fn () => Wizarr::t('Reset password')), 'odd locale: English, no file read');
});

test('languages: placeholders are filled in, in both languages', function () {
    same('4 days left', ext()->remainingText(4 * 86400), 'English');
    same('4 dage tilbage', inLocale('da', fn () => ext()->remainingText(4 * 86400)), 'Danish');
    same('Discord forbundet som Mette.', inLocale('da', fn () => Wizarr::t('Discord connected as :name.', ['name' => 'Mette'])), 'name');
    same(':date – ends Nov 19', Wizarr::t(':remaining – ends :date', ['remaining' => ':date', 'date' => 'Nov 19']), 'a value that looks like a placeholder stays as it is');
});

test('languages: the client area, the box and the Discord button follow the language', function () {
    $service = subscribed(settings());
    $actions = inLocale('da', fn () => labels(ext()->getActions($service, settings(), $service->propertyArray())));
    same(['text:Status=Aktiv', 'text:Brugernavn=mette', 'button:Nulstil adgangskode', 'button:Åbn mediebiblioteket'], $actions, 'client area');
    ok(str_contains(inLocale('da', fn () => ext()->bannerHtml(['state' => 'ready', 'key' => 'k'])), 'Din adgang er klar'), 'box');
    ok(str_contains(inLocale('da', fn () => ext()->discordLoginButton('https://x/login', '')), 'Log ind eller opret konto med Discord'), 'button');
});

test('languages: every text in the code has a Danish translation, and no translation is left over', function () {
    $dir = __DIR__ . '/../extensions/Servers/Wizarr';
    $code = '';
    $files = ['Wizarr.php', 'ShopGate.php', 'resources/views/discord.blade.php', 'resources/views/report.blade.php'];
    foreach (glob(__DIR__ . '/../extensions/Servers/Wizarr/Concerns/*.php') as $concern) {
        $files[] = 'Concerns/' . basename($concern);
    }
    foreach ($files as $file) {
        $code .= file_get_contents($dir . '/' . $file);
    }
    preg_match_all('/(?:self::t|Wizarr::t|\$t)\(\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")/', $code, $m);
    // PHP's own rules: in '...' only \\ and \' are escapes.
    $unquote = fn ($literal) => $literal[0] === "'"
        ? strtr(substr($literal, 1, -1), ['\\\\' => '\\', "\\'" => "'"])
        : stripcslashes(substr($literal, 1, -1));
    // Translated where they are shown, from the list.
    $used = array_unique([...array_map($unquote, $m[1]), ...array_values(Wizarr::REPORT_PROBLEMS)]);
    $danish = json_decode(file_get_contents($dir . '/lang/da.json'), true);
    ok(count($used) > 50, 'found the texts (' . count($used) . ')');
    same([], array_values(array_diff($used, array_keys($danish))), 'missing in da.json');
    same([], array_values(array_diff(array_keys($danish), $used)), 'not used any more');
});

/* ---------- trial products ---------- */

function trialSettings(array $overrides = []): array
{
    return settings($overrides + ['trial_days' => '7']);
}

/** A trial the customer has redeemed; their user was created $ago seconds ago. */
function trialService(int $ago = 60, ?Service $service = null, string $username = 'mette', array $productOverrides = []): Service
{
    $service ??= service();
    $product = trialSettings($productOverrides);
    $data = ext()->createServer($service, $product, $service->propertyArray());
    redeem($data['invite_code'], $username, null);
    $created = (new DateTimeImmutable('-' . $ago . ' seconds'))->format(DATE_ATOM);
    // the stub stamps "now"; move the creation time back
    $state = state();
    foreach ($state['users'] as $u) {
        if ($u['username'] === $username) {
            stub('/_test/set-user', ['id' => $u['id'], 'created_at' => $created]);
        }
    }
    $service->product = (object) ['server' => (object) ['extension' => 'Wizarr'], 'settings' => $product];
    $service->user->serviceList[] = $service;
    ext()->getActions($service, $product, $service->propertyArray()); // records the user, starts the clock

    return $service;
}

function paidService(Service $trial): Service
{
    $paid = new Service(99, $trial->user);
    $paid->product = (object) ['server' => (object) ['extension' => 'Wizarr'], 'settings' => settings()];
    $trial->user->serviceList[] = $paid;

    return $paid;
}

test('trial: only for customers who never had a trial or a subscription', function () {
    same(null, ext()->trialRefusal([]), 'new customer');
    same('The trial is for new customers only.', ext()->trialRefusal(['paid']), 'paid before');
    same('You have already had a trial.', ext()->trialRefusal(['trial']), 'trial before');
});

test('trial: one per server, so Silo and Jellyfin can each be tried once', function () {
    $user = new User('mette@example.com');
    $on = function (int $id, int $server, bool $trial, string $extension = 'Wizarr') use ($user) {
        $service = new Service($id, $user);
        $service->product = (object) ['server_id' => $server, 'server' => (object) ['id' => $server, 'extension' => $extension], 'settings' => $trial ? trialSettings() : settings()];

        return $service;
    };
    $jellyfinTrial = $on(1, 10, true);
    same([], ext()->trialHistory($jellyfinTrial, [$jellyfinTrial]), 'first trial');
    $siloTrial = $on(2, 20, true);
    same([], ext()->trialHistory($siloTrial, [$jellyfinTrial, $siloTrial]), 'a Silo trial after a Jellyfin trial (both in one cart, too)');
    same(['trial'], ext()->trialHistory($on(3, 10, true), [$jellyfinTrial, $siloTrial]), 'Jellyfin again: refused');
    same(['paid'], ext()->trialHistory($on(4, 20, true), [$on(5, 20, false)]), 'paid on Silo before: no Silo trial');
    same([], ext()->trialHistory($on(6, 10, true), [$on(7, 20, false), $on(8, 10, false, 'Pterodactyl')]), 'paid elsewhere does not count');
});

test('trial: never gets a Wizarr expiry, even if "Access expires after" is filled in', function () {
    ext()->createServer(service(), trialSettings(['access_days' => '7']), []);
    same(null, state()['invitations'][0]['duration'], 'no duration: the extension ends the trial, Wizarr must not');
});

test('trial: nothing starts before the customer has created their user', function () {
    $service = service();
    ext()->createServer($service, trialSettings(), []);
    same('none', ext()->processTrial($service, trialSettings(), $service->propertyArray()), 'not started');
    ok(!isset($service->propertyArray()['trial_ends_at']), 'no end date');
});

test('trial: the clock starts at the user\'s creation in Wizarr, not at purchase', function () {
    $service = trialService(ago: 3 * 86400);                       // created 3 days ago
    $ends = strtotime($service->propertyArray()['trial_ends_at'] ?? '');
    ok(abs($ends - (time() + 4 * 86400)) < 120, 'ends 4 days from now: ' . date(DATE_ATOM, $ends));
});

test('trial: a user recorded before the clock existed gets an end date on the next page view', function () {
    $service = service();
    $product = trialSettings();
    $data = ext()->createServer($service, $product, []);
    redeem($data['invite_code'], 'mette');
    // recorded by an older version: users known, no clock
    $service->properties()->updateOrCreate(['key' => 'wizarr_users'], ['name' => 'x', 'value' => '1:mette:jellyfin']);
    $service->product = (object) ['server' => (object) ['extension' => 'Wizarr'], 'settings' => $product];

    $actions = labels(ext()->getActions($service, $product, $service->propertyArray()));
    ok((bool) array_filter($actions, fn ($a) => str_starts_with($a, 'text:Trial=')), 'shown at once: ' . json_encode($actions));
    ok(isset($service->propertyArray()['trial_ends_at']), 'clock stored');
});

test('trial: time left reads naturally', function () {
    $w = ext();
    same('7 days left', $w->remainingText(7 * 86400 - 60), 'just started');
    same('2 days left', $w->remainingText(36 * 3600), 'a day and a half');
    same('1 day left', $w->remainingText(30 * 3600), 'a bit over a day');
    same('5 hours left', $w->remainingText(5 * 3600 + 120), 'the last day');
    same('1 hour left', $w->remainingText(3600), 'singular');
    same('less than an hour left', $w->remainingText(20 * 60), 'the last hour');
    same('ended', $w->remainingText(0), 'over');
});

test('trial: the box shows the time left during the whole trial', function () {
    $service = trialService(ago: 2 * 86400);                        // 5 days left
    $state = ext()->bannerState($service, $service->propertyArray());
    same('trial', $state['state'] ?? null, 'trial box');
    same('5 days left', $state['remaining'] ?? null, 'time left');
    same('trial', ext()->liveBannerState($service, $service->propertyArray())['state'], 'the live check keeps it');

    $html = ext()->bannerHtml($state + ['serviceUrl' => '/s', 'statusUrl' => '/w', 'shopUrl' => '/#subscriptions']);
    ok(str_contains($html, '<span data-remaining>5 days left</span>'), 'printed in the box');
});

test('trial: no trial box for a normal subscription with a user', function () {
    $service = subscribed(settings());
    ext()->getActions($service, settings(), $service->propertyArray());
    same(null, ext()->bannerState($service, $service->propertyArray()), 'nothing to say');
});

test('trial: the end date and a "continue" button are shown in the client area', function () {
    $service = trialService();
    $actions = labels(ext()->getActions($service, trialSettings(), $service->propertyArray()));
    ok((bool) array_filter($actions, fn ($a) => str_starts_with($a, 'text:Trial=')), json_encode($actions));
    ok(in_array('button:Continue with a subscription', $actions, true), 'continue button');
});

test('trial: at the end the user is disabled (not deleted) and the trial is closed', function () {
    $service = trialService(ago: 8 * 86400);                       // created 8 days ago: over
    same('cancelled', $service->status, 'closed already when the clock started');
    $user = state()['users'][0];
    same(true, $user['is_disabled'], 'disabled');
    ok(isset($service->propertyArray()['trial_ended_at']), 'end recorded');
    ok(grantProp($service, 'wizarr_users') !== null, 'user kept for a purchase');
});

test('trial: not bought within 30 days -> the user is deleted', function () {
    $service = trialService(ago: 8 * 86400);
    $ended = strtotime($service->propertyArray()['trial_ended_at']);
    same('none', ext()->processTrial($service, trialSettings(), $service->propertyArray(), $ended + 29 * 86400), 'kept on day 29');
    same('cleaned', ext()->processTrial($service, trialSettings(), $service->propertyArray(), $ended + 31 * 86400), 'deleted on day 31');
    same([], state()['users'], 'gone from Wizarr');
});

test('trial -> paid after the trial ended: same user switched back on, no new invitation', function () {
    $trial = trialService(ago: 8 * 86400);
    $paid = paidService($trial);
    $invitationsBefore = count(state()['invitations']);

    ext()->createServer($paid, settings(), $paid->propertyArray());
    same($invitationsBefore, count(state()['invitations']), 'no new invitation');
    same(false, state()['users'][0]['is_disabled'], 'enabled again');
    same('1:mette:jellyfin', grantProp($paid, 'wizarr_users'), 'user now on the paid subscription');
    same('99', $trial->propertyArray()['trial_converted_to'] ?? null, 'trial marked as bought');
    ok(grantProp($trial, 'wizarr_users') === null, 'the trial lets go of the user');

    // the 30-day clean-up must never touch the bought user
    same('none', ext()->processTrial($trial, trialSettings(), $trial->propertyArray(), time() + 60 * 86400), 'no clean-up');
    same(1, count(state()['users']), 'still there');
});

test('trial -> paid on another server: the trial is left alone', function () {
    $trial = trialService(ago: 8 * 86400);
    $trial->product->server_id = 10;
    $paid = paidService($trial);
    $paid->product->server_id = 20; // e.g. Silo after a Jellyfin trial
    ok(!ext()->adoptTrialUser($paid), 'not adopted');
    same(null, $trial->propertyArray()['trial_converted_to'] ?? null, 'trial not marked as bought');
    same(true, state()['users'][0]['is_disabled'], 'the trial user stays disabled');
});

test('trial -> paid during the trial: the trial is closed, the user keeps watching', function () {
    $trial = trialService(ago: 86400);
    same('active', $trial->status, 'trial running');
    $paid = paidService($trial);

    ext()->createServer($paid, settings(), $paid->propertyArray());
    same('cancelled', $trial->status, 'trial closed');
    same(false, state()['users'][0]['is_disabled'], 'never disabled');
    same('none', ext()->processTrial($trial, trialSettings(), $trial->propertyArray(), time() + 30 * 86400), 'trial end does nothing any more');
    same(false, state()['users'][0]['is_disabled'], 'still enabled');
});

test('trial -> paid on Plex: cannot be switched back on, so a normal invitation is sent', function () {
    $trial = trialService(ago: 8 * 86400, productOverrides: ['server_ids' => ['2'], 'library_ids' => '']);
    $paid = paidService($trial);
    $before = count(state()['invitations']);
    $data = ext()->createServer($paid, settings(['server_ids' => ['2'], 'library_ids' => '']), $paid->propertyArray());
    same($before + 1, count(state()['invitations']), 'new invitation');
    ok(str_contains($data['invite_url'], '/j/'), 'link in the mail');
});

test('trial: "your trial is over" box for two weeks, not after a purchase', function () {
    $trial = trialService(ago: 8 * 86400);
    same('trial_over', ext()->trialOverState($trial->user)['state'] ?? null, 'shown');
    same(null, ext()->trialOverState($trial->user, time() + 15 * 86400), 'gone after two weeks');

    paidService($trial);
    ext()->createServer($trial->user->serviceList[1], settings(), []);
    same(null, ext()->trialOverState($trial->user), 'not after buying');
});

test('a subscription that took over a trial user is never invited again (retried job)', function () {
    $trial = trialService(ago: 8 * 86400);
    $paid = paidService($trial);
    ext()->createServer($paid, settings(), $paid->propertyArray());
    $before = count(state()['invitations']);

    ext()->createServer($paid, settings(), $paid->propertyArray()); // the queue runs it again
    same($before, count(state()['invitations']), 'no invitation for a customer who already has a user');
});

test('createServer twice after a user exists never invites again', function () {
    $service = subscribed(settings());
    ext()->getActions($service, settings(), $service->propertyArray());
    $before = count(state()['invitations']);
    ext()->createServer($service, settings(), $service->propertyArray());
    same($before, count(state()['invitations']), 'no new invitation');
});

/* ---------- customer deleted in Paymenter ---------- */

function terminateLikePaymenter(): callable
{
    return fn (Service $service) => ext(discordSettings())->terminateServer($service, settings(), $service->propertyArray());
}

test('deleting a customer removes their access everywhere first', function () {
    $service = customerWithRole();                                   // Discord linked, role on product
    ext(discordSettings())->createServer($service, settings(['discord_role_id' => '500']), []);
    redeem(grantProp($service, 'wizarr_invite_code'), 'mette');
    ext()->getActions($service, settings(), $service->propertyArray());
    same(['500'], memberRoles(), 'had the role');

    $failed = ext(discordSettings())->removeAccessForUser($service->user, terminateLikePaymenter());
    same([], $failed, 'nothing failed');
    same([], state()['users'], 'Wizarr/Jellyfin user gone');
    same([], state()['invitations'], 'invitation gone');
    same([], memberRoles(), 'Discord role taken back');
});

test('deleting a customer whose invitation was never used removes the link', function () {
    $service = service();
    ext()->createServer($service, settings(), []);
    $service->product = (object) ['server' => (object) ['extension' => 'Wizarr'], 'settings' => settings()];
    $service->user->serviceList = [$service];

    same([], ext()->removeAccessForUser($service->user, fn ($s) => ext()->terminateServer($s, settings(), $s->propertyArray())), 'ok');
    same([], state()['invitations'], 'link gone');
});

test('Wizarr down: the deletion is refused and nothing is half-done', function () {
    $service = customerWithRole();
    ext(discordSettings())->createServer($service, settings(['discord_role_id' => '500']), []);
    redeem(grantProp($service, 'wizarr_invite_code'), 'mette');
    ext()->getActions($service, settings(), $service->propertyArray());
    stub('/_test/set', ['fail_next' => 100]);

    $failed = ext(discordSettings())->removeAccessForUser($service->user, terminateLikePaymenter());
    same(1, count($failed), 'reported');
    ok(str_contains($failed[0], 'subscription #'), $failed[0]);
    stub('/_test/set', ['fail_next' => 0]);
    same(['500'], memberRoles(), 'role left alone, since the customer is kept');
    same(1, count(state()['users']), 'user still there to retry');
});

test('other products than Wizarr are left to Paymenter', function () {
    $service = service();
    $service->product = (object) ['server' => (object) ['extension' => 'Pterodactyl'], 'settings' => []];
    $service->user->serviceList = [$service];
    $calls = 0;
    same([], ext()->removeAccessForUser($service->user, function () use (&$calls) { $calls++; }), 'ok');
    same(0, $calls, 'not touched');
});

/* ---------- outages ---------- */

test('a short Wizarr outage is retried for calls that are safe to repeat', function () {
    $service = subscribed(settings());
    stub('/_test/set', ['fail_next' => 2]);

    ext()->suspendServer($service, settings(), $service->propertyArray());
    same(true, state()['users'][0]['is_disabled'], 'suspended despite two 503s');
});

test('creating an invitation is never retried', function () {
    stub('/_test/set', ['fail_next' => 1]);
    // No libraries chosen: the invitation is the first call to Wizarr.
    throws(fn () => ext()->createServer(service(), settings(['library_ids' => '']), []), '503', 'surfaces the failure');

    $posts = array_filter(state()['requests'], fn ($r) => $r['method'] === 'POST' && $r['path'] === '/api/invitations');
    same(1, count($posts), 'exactly one attempt');
    same(0, count(state()['invitations']), 'nothing created');
});

test('a longer outage fails the suspension loudly instead of pretending it worked', function () {
    $service = subscribed(settings());
    stub('/_test/set', ['fail_next' => 10]);

    throws(fn () => ext()->suspendServer($service, settings(), $service->propertyArray()), '503', 'suspend throws');
    stub('/_test/set', ['fail_next' => 0]);
    same(false, state()['users'][0]['is_disabled'], 'user untouched');
});

/* ---------- Silo, directly, without Wizarr ---------- */

/** The extension on a Paymenter server set to Silo (stub_silo.py under /silo). */
function silo(array $config = []): Wizarr
{
    global $STUB;

    return ext($config + ['backend' => 'silo', 'host' => $STUB . '/silo', 'public_url' => 'https://watch.example.com', 'api_key' => 'sa_full']);
}

function siloSettings(array $overrides = []): array
{
    return $overrides + ['server_ids' => ['1'], 'library_ids' => ['1'], 'link_lifetime' => '7', 'access_days' => null, 'login_url' => ''];
}

function siloState(): array
{
    return state()['silo'];
}

/** The customer opens the link and creates their account. */
function siloAccept(string $link, string $username = 'mette'): string
{
    return (string) stub('/_test/silo-accept', ['link' => $link, 'username' => $username, 'email' => $username . '@example.com'])['account'];
}

function siloAccount(string $id): ?array
{
    foreach (siloState()['accounts'] as $account) {
        if ($account['id'] === $id) {
            return $account;
        }
    }

    return null;
}

/** Subscribed to a Silo product, account created. Returns [service, account ID]. */
function siloSubscribed(string $username = 'mette', int $id = 1): array
{
    $service = service($id);
    $data = silo()->createServer($service, siloSettings(), $service->propertyArray());

    return [$service, siloAccept($data['invite_url'], $username)];
}

test('Silo: testConfig checks the key, and explains a key with only admin:users', function () {
    same(true, silo()->testConfig(), 'full key');
    ok(str_contains((string) silo(['api_key' => 'sa_wrong'])->testConfig(), 'rejected the API key'), 'wrong key');
    ok(str_contains((string) silo(['api_key' => 'sa_users_only'])->testConfig(), 'full access'), 'scoped key: ' . silo(['api_key' => 'sa_users_only'])->testConfig());
});

test('Silo: the product page lists Silo and its libraries', function () {
    $fields = array_column(silo()->getProductConfig(), null, 'name');
    same([['value' => '1', 'label' => 'Silo (silo)']], $fields['server_ids']['options'], 'one server: Silo');
    same(['Silo: Movies', 'Silo: Shows'], array_column($fields['library_ids']['options'], 'label'), 'its libraries');
});

test('Silo: first payment makes one link invitation for an ordinary account, with the chosen libraries', function () {
    $service = service();
    $data = silo()->createServer($service, siloSettings(), $service->propertyArray());

    $create = array_values(array_filter(siloState()['requests'], fn ($r) => $r['method'] === 'POST'));
    same([['delivery' => 'link', 'role' => 'user', 'note' => 'Paymenter', 'library_ids' => ['1']]], array_column($create, 'body'), 'exactly what Silo accepts');
    ok(str_starts_with($data['invite_url'], 'https://silo.example.com/invite#'), 'Silo\'s own link: ' . $data['invite_url']);
    same($data['invite_url'], grantProp($service, 'wizarr_invite_url'), 'link stored, as Silo never shows it again');

    $actions = silo()->getActions($service, siloSettings(), $service->propertyArray());
    same(['text:Status=Waiting for you to create your user', 'button:Create your user', 'button:Problems? Get a new link'], labels($actions), 'waiting');
    same($data['invite_url'], acts($actions)[1]['url'], 'the button opens the stored link');
});

test('Silo: once the account exists the client area shows it, with reset and "Open"', function () {
    [$service, $account] = siloSubscribed();
    $actions = silo()->getActions($service, siloSettings(), $service->propertyArray());
    same(['text:Status=Active', 'text:Username=mette', 'button:Reset password', 'button:Open the media library'], labels($actions), 'active');
    same('https://watch.example.com', array_slice(acts($actions), -1)[0]['url'], 'Open goes to Silo\'s public address');
    same($account . ':mette:silo', grantProp($service, 'wizarr_users'), 'the account recorded');

    $url = silo()->resetPassword($service, siloSettings(), $service->propertyArray());
    ok(str_starts_with($url, 'https://silo.example.com/reset-password#'), 'Silo\'s reset link: ' . $url);
});

test('Silo: overdue disables the same account, paid again enables it; both with Silo\'s edit guard', function () {
    [$service, $account] = siloSubscribed();
    silo()->suspendServer($service, siloSettings(), $service->propertyArray());
    same(false, siloAccount($account)['enabled'], 'disabled, not deleted');

    silo()->unsuspendServer($service, siloSettings(), $service->propertyArray());
    same(true, siloAccount($account)['enabled'], 'the same account back');
    same(1, count(siloState()['invitations']), 'no new invitation');
    same([], NotificationHelper::$sent, 'no new mail');
    $writes = array_filter(siloState()['requests'], fn ($r) => $r['method'] === 'PUT');
    ok(count($writes) === 2 && !array_filter($writes, fn ($r) => !$r['if_match']), 'every change sent If-Match');
});

test('Silo: a change made by someone else in between is read again, not overwritten blindly', function () {
    [$service, $account] = siloSubscribed();
    // The account changes between Paywarr reading and writing it, once.
    stub('/_test/silo-stale', ['times' => 1]);
    silo()->suspendServer($service, siloSettings(), $service->propertyArray());
    same(false, siloAccount($account)['enabled'], 'disabled after reading it again');
    same(2, count(array_filter(siloState()['requests'], fn ($r) => $r['method'] === 'PUT')), 'the first write was refused (412), the second went through');

    // Changed again and again: Paywarr gives up instead of looping.
    stub('/_test/silo-stale', ['times' => 5]);
    $e = tryHook(fn () => silo()->unsuspendServer($service, siloSettings(), $service->propertyArray()));
    ok($e !== null && str_contains($e->getMessage(), '412'), 'reported: ' . ($e ? $e->getMessage() : 'nothing thrown'));
});

test('Silo: an unused link is revoked at suspension, and payment brings a new one by mail', function () {
    $service = service();
    $first = silo()->createServer($service, siloSettings(), $service->propertyArray());
    silo()->suspendServer($service, siloSettings(), $service->propertyArray());
    same('revoked', siloState()['invitations'][0]['status'], 'the old link no longer works');

    silo()->unsuspendServer($service, siloSettings(), $service->propertyArray());
    same(2, count(siloState()['invitations']), 'a new invitation');
    $fresh = grantProp($service, 'wizarr_invite_url');
    ok($fresh !== $first['invite_url'] && str_starts_with($fresh, 'https://silo.example.com/invite#'), 'a new link');
    same(1, count(NotificationHelper::$sent), 'mailed');
});

test('Silo: the watch address is Silo\'s own, also after Wizarr\'s servers were looked up (their IDs overlap)', function () {
    $wizarr = service(2, 'other@example.com');
    $data = ext()->createServer($wizarr, mediaSettings(), $wizarr->propertyArray());
    same('https://jellyfin.example.com', $data['login_url'], 'Wizarr\'s server 1 is Jellyfin');

    $other = service(3, 'third@example.com');
    same('https://watch.example.com', silo()->createServer($other, siloSettings(), [])['login_url'], 'Silo\'s server 1 is Silo (the welcome mail\'s "Watch in the browser")');
    [$service] = siloSubscribed();
    $actions = silo()->getActions($service, siloSettings(), $service->propertyArray());
    same('https://watch.example.com', array_slice(acts($actions), -1)[0]['url'], '"Open" goes to Silo, not to Jellyfin');
});

test('media: with one server on the product the choice is left alone, so it can\'t stop an order', function () {
    $service = chosen('jellyfin,plex');
    $data = ext()->createServer($service, settings(['server_ids' => ['1']]), props($service));
    ok(($data['invite_code'] ?? '') !== '', 'an invitation was made');
    $silo = service(2);
    $silo->configs = [Wizarr::MEDIA_OPTION => 'jellyfin,plex'];
    ok(str_contains(silo()->createServer($silo, siloSettings(), props($silo))['invite_url'], 'silo.example.com'), 'on Silo too');
});

test('client area: paid and no invitation yet says "being prepared", later offers a new link', function () {
    $service = service();
    $service->updated_at = new DateTimeImmutable('-20 seconds');
    same(['text:Status=Your access is being prepared'], labels(ext()->getActions($service, settings(), [])), 'while the queue works');
    $service->updated_at = new DateTimeImmutable('-16 minutes');
    same(['text:Status=Your access is not ready yet', 'button:Get a new invitation link'], labels(ext()->getActions($service, settings(), [])), 'when it never came');
});

test('Silo: an expired link gets a "new link" button that works', function () {
    $service = service();
    silo()->createServer($service, siloSettings(), $service->propertyArray());
    stub('/_test/silo-expire', ['id' => 1]);
    $labels = labels(silo()->getActions($service, siloSettings(), $service->propertyArray()));
    ok(in_array('text:Status=Your invitation link has expired', $labels, true), json_encode($labels));

    $url = silo()->renewInvitation($service, siloSettings(), $service->propertyArray());
    ok(str_starts_with($url, 'https://silo.example.com/invite#'), 'new link: ' . $url);
    siloAccept($url, 'late');
    same('late', siloState()['accounts'][1]['username'], 'it creates the account');
});

test('Silo: cancellation deletes the account; the accepted invitation is left as it is', function () {
    [$service, $account] = siloSubscribed();
    silo()->terminateServer($service, siloSettings(), $service->propertyArray());
    same(null, siloAccount($account), 'account deleted');
    same('accepted', siloState()['invitations'][0]['status'], 'nothing to revoke');
    ok(siloAccount('1') !== null, 'the owner untouched');
});

test('Silo: administrators are never changed, and other accounts never matched', function () {
    stub('/_test/silo-add', ['username' => 'anna', 'email' => 'mette.old@example.com']); // an older, unrelated account
    [$service, $account] = siloSubscribed('mette');
    silo()->suspendServer($service, siloSettings(), $service->propertyArray());
    same([true, false], [siloAccount('2')['enabled'], siloAccount($account)['enabled']], 'only the account the invitation made');

    $client = new \Paymenter\Extensions\Servers\Wizarr\SiloClient($GLOBALS['STUB'] . '/silo', 'sa_full');
    try {
        $client->disableUser(1);
        $refused = false;
    } catch (\Paymenter\Extensions\Servers\Wizarr\WizarrException $e) {
        $refused = true;
    }
    same([true, true], [$refused, siloAccount('1')['enabled']], 'the owner is refused, client side');
    same([], array_values(array_filter($client->users(), fn ($u) => $u['username'] === 'owner')), 'administrators are not listed as users');
});

test('Silo: long account and invitation lists are read page by page', function () {
    for ($i = 0; $i < 230; $i++) {
        stub('/_test/silo-add', ['username' => "user$i", 'email' => "user$i@example.com"]);
    }
    $client = new \Paymenter\Extensions\Servers\Wizarr\SiloClient($GLOBALS['STUB'] . '/silo', 'sa_full');
    same(230, count($client->users()), 'all of them, owner left out');
    $pages = array_filter(siloState()['requests'], fn ($r) => str_starts_with($r['path'], '/api/v2/admin/users'));
    same(2, count($pages), 'two pages of 200');
});

test('Silo: a product with "Access expires after" is refused rather than giving access for good', function () {
    $service = service();
    $e = tryHook(fn () => silo()->createServer($service, siloSettings(['access_days' => '30']), $service->propertyArray()));
    ok($e !== null && str_contains($e->getMessage(), 'cannot expire'), 'refused: ' . ($e ? $e->getMessage() : 'nothing thrown'));
    same([], siloState()['invitations'], 'no invitation made');
});

/* ---------- Silo's own requests, sold with the "seerr" option ---------- */

/** A Silo subscription with $option as the "seerr" choice, account created and noted. Returns [service, account ID]. */
function siloWithRequests(string $option, int $id = 1): array
{
    global $STUB;
    $service = service($id);
    $service->configs = $option === '' ? [] : [Wizarr::REQUESTS_OPTION => $option];
    $service->product = (object) ['server' => (object) ['id' => 9, 'extension' => 'Wizarr', 'settings' => ['backend' => 'silo', 'host' => $STUB . '/silo', 'public_url' => 'https://watch.example.com', 'api_key' => 'sa_full']], 'settings' => siloSettings()];
    $data = silo()->createServer($service, siloSettings(), props($service));
    $account = siloAccept($data['invite_url'], 'customer' . $id);
    silo()->getActions($service, siloSettings(), props($service)); // notes the account, as the client area does

    return [$service, $account];
}

function siloLimit(string $account): array
{
    $limit = siloAccount($account)['request_limit'] ?? ['limit_mode' => 'inherit', 'max_requests' => null, 'window_days' => null];

    return [siloAccount($account)['requests_allowed'] ?? null, $limit['limit_mode'], $limit['max_requests'], $limit['window_days']];
}

test('Silo requests: a package sets Silo\'s own requests and limit on the account, and the client area says so', function () {
    [$service, $account] = siloWithRequests('10/30');
    same([true, 'custom', 10, 30], siloLimit($account), 'allowed, 10 per 30 days');
    $labels = labels(silo()->getActions($service, siloSettings(), props($service)));
    ok(in_array('text:Requests=10 requests every 30 days, in Silo', $labels, true), json_encode($labels));
    $limitWrites = array_filter(siloState()['requests'], fn ($r) => $r['method'] === 'PUT' && str_contains($r['path'], '/request-users/'));
    ok($limitWrites && !array_filter($limitWrites, fn ($r) => !$r['if_match']), 'with Silo\'s edit guard');
    same([], array_filter(state()['requests'], fn ($r) => str_starts_with($r['path'], '/seerr')), 'no Seerr involved');
});

test('Silo requests: unlimited, Yes (Silo\'s default) and none', function () {
    [, $unlimited] = siloWithRequests('unlimited', 1);
    [, $yes] = siloWithRequests('yes', 2);
    [$none, $noneAccount] = siloWithRequests('', 3);
    same([true, 'unlimited', null, null], siloLimit($unlimited), 'unlimited');
    same([true, 'inherit', null, null], siloLimit($yes), 'Yes: Silo\'s own default limit');
    same([false, 'inherit', null, null], siloLimit($noneAccount), 'not bought: requests explicitly off, so a group default gives nothing for free');
    ok(!in_array('Requests', array_column(silo()->getActions($none, siloSettings(), props($none)), 'label'), true), 'no Requests line without the add-on');
});

test('requests: the option is called "requests" now; "seerr" from before still works, and "requests" wins', function () {
    $plan = fn (array $options) => ext()->seerrPlan(settings(), $options);
    same(['limit' => 10, 'days' => 30], $plan(['requests' => '10/30']), '"requests"');
    same(['limit' => 5, 'days' => 7], $plan(['seerr' => '5']), '"seerr", as set up before');
    same(['limit' => 0, 'days' => null], $plan(['requests' => 'unlimited', 'seerr' => 'no']), 'both: "requests"');
    same(null, $plan(['requests' => 'no']), 'no');
});

test('Silo requests: off while suspended, back with the package when paid', function () {
    [$service, $account] = siloWithRequests('5');
    silo()->suspendServer($service, siloSettings(), props($service));
    same([false, false], [siloAccount($account)['enabled'], siloAccount($account)['requests_allowed']], 'account and requests off');
    silo()->unsuspendServer($service, siloSettings(), props($service));
    same([true, true, 'custom', 5, 7], [siloAccount($account)['enabled'], ...siloLimit($account)], 'both back, 5 a week');
});

test('Silo requests: a new package changes the limit; the same one costs no call', function () {
    [$service, $account] = siloWithRequests('5');
    $service->configs = [Wizarr::REQUESTS_OPTION => '20/30'];
    silo()->syncSeerr($service, siloSettings(), props($service), 'active');
    same([true, 'custom', 20, 30], siloLimit($account), 'upgraded');
    $before = count(siloState()['requests']);
    silo()->syncSeerr($service, siloSettings(), props($service), 'active');
    same($before, count(siloState()['requests']), 'nothing to change: no calls');
});

test('Silo requests: always fit a Silo product, even with Seerr fields filled in on the Silo server', function () {
    silo(['seerr_url' => 'http://seerr.example.com', 'seerr_api_key' => 'k'])->checkSeerrServer(siloSettings(), [Wizarr::SEERR_OPTION => '10/30'], ['jellyfin']);
    ok(true, 'not refused');
});

test('hourly: a Silo trial ends through Silo, also when the first server is Wizarr', function () {
    [$service, $account] = siloWithRequests('');
    $service->product->settings = siloSettings(['trial_days' => '7']);
    $service->status = 'active';
    $service->properties()->updateOrCreate(['key' => 'trial_ends_at'], ['name' => 'Trial ends', 'value' => date(DATE_ATOM, time() - 60)]);
    $service->unsetRelation('properties');
    $service->loadedProperties = null;
    ext()->processTrials([$service]);
    same(false, siloAccount($account)['enabled'], 'the Silo account is disabled at the end of the trial');
});

test('hourly: each subscription is handled with its own server\'s settings, not the first server\'s', function () {
    [$service, $account] = siloWithRequests('10/30');
    // Reset what was sent, then let the hourly run's instance (Wizarr's settings) reconcile it.
    stub('/_test/silo-set-limit', ['id' => $account, 'requests_allowed' => false]);
    $service->propertyArray(); // keep the noted account
    $service->properties()->where('key', 'silo_requests')->delete();
    $service->unsetRelation('properties');
    $service->loadedProperties = null;
    $service->status = 'active';
    ext()->reconcileSeerr([$service]); // the hourly run's instance has the first server's settings: Wizarr
    same(true, siloAccount($account)['requests_allowed'], 'Silo\'s requests set, through the Silo server\'s settings');
    same([], array_values(array_filter(state()['requests'], fn ($r) => str_starts_with($r['path'], '/api/users'))), 'Wizarr was not asked about a Silo customer');
});

/* ---------- problem reports: a movie or show with a fault, as a ticket ---------- */

/** A Wizarr subscription with Seerr requests, for the reports. */
function reportCustomer(string $option = 'yes', int $id = 50, string $status = 'active'): Service
{
    global $STUB;
    $service = service($id);
    $service->status = $status;
    $service->configs = $option === '' ? [] : [Wizarr::REQUESTS_OPTION => $option];
    $service->product = (object) ['name' => 'Jellyfin Plus', 'server' => (object) ['id' => 1, 'extension' => 'Wizarr', 'settings' => [
        'host' => $STUB . '/', 'public_url' => 'https://join.example.com/', 'api_key' => 'stub-key', 'seerr_url' => $STUB . '/seerr', 'seerr_api_key' => 'seerr-key']], 'settings' => settings()];

    return $service;
}

function reportSources(array $services): array
{
    return ext()->reportSources((object) ['id' => 1], $services);
}

test('reports: every active subscription can report, on Silo or with Seerr; the Requests tab only with requests', function () {
    [$silo] = siloWithRequests('10/30', 1);
    $silo->status = 'active';
    [$noRequests] = siloWithRequests('', 2);
    $noRequests->status = 'active';
    $seerr = reportCustomer('yes', 3);
    $suspended = reportCustomer('yes', 4, 'suspended');
    $plain = reportCustomer('', 5);
    same([[1, 'silo'], [2, 'silo'], [3, 'seerr'], [5, 'seerr']], array_map(fn ($s) => [$s['service']->id, $s['kind']], reportSources([$silo, $noRequests, $seerr, $suspended, $plain])), 'with or without requests; not suspended');
    same([1, 3], array_map(fn ($s) => $s['service']->id, ext()->requestsSources((object) ['id' => 1], [$silo, $noRequests, $seerr, $suspended, $plain])), 'the Requests tab: only with requests');
    $noSeerr = reportCustomer('', 6);
    unset($noSeerr->product->server->settings['seerr_url'], $noSeerr->product->server->settings['seerr_api_key']);
    same([], reportSources([$noSeerr]), 'a Wizarr server without Seerr has nothing to search');
});

test('reports: search finds titles in the customer\'s libraries; Seerr only what is on the server', function () {
    [$silo] = siloWithRequests('yes', 1);
    $silo->status = 'active';
    $sources = reportSources([$silo, reportCustomer('yes', 3)]);
    $results = ext()->reportSearch($sources, 'heat', fn ($service, $item) => "/poster/$service/$item");
    same([
        ['1:silo:movie:heat-1995', 'Heat', 1995, 'movie', '/poster/1/movie:heat-1995', 'Silo'],
        ['1:silo:series:heat-house', 'Heat House', 2024, 'show', '/poster/1/series:heat-house', 'Silo'],
        ['3:movie:949', 'Heat', 1995, 'movie', 'https://image.tmdb.org/t/p/w185/heat.jpg', 'Seerr'],
        ['3:tv:1396', 'Breaking Heat', 2008, 'show', 'https://image.tmdb.org/t/p/w185/bh.jpg', 'Seerr'],
    ], array_map(fn ($r) => [$r['key'], $r['title'], $r['year'], $r['kind'], $r['poster'], $r['server']], $results),
        'Silo\'s movies and shows (no episodes); Seerr\'s available ones (not pending, not unknown, no people)');
    same([], ext()->reportSearch($sources, 'h'), 'at least two letters');
    same(['3:tv:1396'], array_column(ext()->reportSearch($sources, 'breaking heat'), 'key'), 'a search with a space, encoded as Seerr wants it (%20, not +)');
});

test('reports: the ticket names the title as the library knows it, for staff, on the customer\'s subscription', function () {
    [$silo] = siloWithRequests('yes', 1);
    $silo->status = 'active';
    $silo->product->name = 'Silo Standard';
    $sources = reportSources([$silo, reportCustomer('yes', 3)]);
    $ticket = ext()->reportTicket($sources, ['pick' => '1:silo:series:heat-house', 'problem' => 'subtitles', 'season' => '2', 'episode' => '5', 'description' => "Danish subtitles are\r\nout of sync."]);
    same(1, $ticket['service_id'], 'on the Silo subscription');
    same('Problem: Heat House (2024) S2E5 – Subtitles', $ticket['subject'], 'subject');
    foreach (['Title: Heat House (2024), show', 'Where: Silo, catalog item series:heat-house', 'Season 2, episode 5', 'Problem: Subtitles (missing, wrong, out of sync)', 'Subscription: #1 Silo Standard', 'Media server user: customer1 (silo)', "Danish subtitles are\nout of sync."] as $line) {
        ok(str_contains($ticket['message'], $line), "message has \"$line\"");
    }
    $movie = ext()->reportTicket($sources, ['pick' => '3:movie:949', 'problem' => 'audio', 'season' => '9', 'description' => '']);
    same(['Problem: Heat (1995) – Sound', 3], [$movie['subject'], $movie['service_id']], 'a movie from Seerr: no season');
    ok(str_contains($movie['message'], 'Where: Seerr, TMDB movie 949') && str_contains($movie['message'], '(nothing)'), 'Seerr\'s ID for staff');
});

test('reports: refused for a title that isn\'t in the customer\'s libraries, an unknown problem or too much text', function () {
    [$silo] = siloWithRequests('yes', 1);
    $silo->status = 'active';
    $sources = reportSources([$silo]);
    foreach ([
        ['pick' => '99:silo:movie:heat-1995', 'problem' => 'audio'],      // another customer's subscription
        ['pick' => '1:movie:949', 'problem' => 'audio'],                  // Seerr title on a Silo subscription
        ['pick' => '1:silo:episode:heat-1', 'problem' => 'audio'],        // not a movie or show
        ['pick' => '1:silo:movie:gone', 'problem' => 'audio'],            // no longer in the catalog
        ['pick' => '1:silo:../../admin', 'problem' => 'audio'],
    ] as $input) {
        tellsCustomer(fn () => ext()->reportTicket($sources, $input), 'can\'t be found', json_encode($input));
    }
    tellsCustomer(fn () => ext()->reportTicket($sources, ['pick' => '1:silo:movie:heat-1995', 'problem' => 'drm']), 'Choose what the problem is', 'unknown problem');
    tellsCustomer(fn () => ext()->reportTicket($sources, ['pick' => '1:silo:movie:heat-1995', 'problem' => 'other', 'description' => str_repeat('x', 2001)]), '2000', 'too long');
});

test('reports: Silo posters come through Paymenter, with the key only for Silo\'s own address', function () {
    $client = new \Paymenter\Extensions\Servers\Wizarr\SiloClient($GLOBALS['STUB'] . '/silo', 'sa_full');
    same('image/jpeg', $client->poster('movie:heat-1995')['type'] ?? null, 'a poster');
    same(null, $client->poster('episode:missing'), 'none for an unknown item');
    same(null, $client->poster('../../x'), 'nothing for a strange ID');
});

/* ---------- the Requests tab ---------- */

/** A Seerr customer whose Seerr account exists (imported as Paywarr does), with $option as requests. */
function seerrTabCustomer(string $option = '10/30', int $id = 60): array
{
    global $STUB;
    $service = subscribed(settings(), 'tab' . $id, service($id));
    $service->status = 'active';
    $service->configs = [Wizarr::REQUESTS_OPTION => $option];
    $service->product = (object) ['name' => 'Jellyfin Plus', 'server' => (object) ['id' => 1, 'extension' => 'Wizarr', 'settings' => seerrSettings([
        'host' => $STUB . '/', 'public_url' => 'https://join.example.com/', 'api_key' => 'stub-key'])], 'settings' => settings()];
    ext(seerrSettings())->syncSeerr($service, settings(), props($service), 'active');

    return [$service, (int) ($service->propertyArray()['seerr_user_id'] ?? 0)];
}

function tabSource(Service $service): array
{
    return ext()->requestsSources((object) ['id' => 1], [$service])[0];
}

test('Requests tab (Seerr): quota, discovery, search and a show\'s seasons, all as the customer', function () {
    [$service, $seerrUser] = seerrTabCustomer('10/30');
    ok($seerrUser > 1, 'the customer has a Seerr account: ' . $seerrUser);
    $data = ext()->requestsData(tabSource($service));
    same([['movies', 10, 10, 30], ['tv', 10, 10, 30]], array_map(fn ($p) => [$p['label'], $p['limit'], $p['remaining'], $p['days']], $data['quota']['parts']), 'movies and TV seasons, 10 per 30 days');
    same(['trending', 'popular_movies', 'popular_series', 'upcoming_movies'], array_column($data['rows'], 'key'), 'discovery rows');
    same([['Heat', 'available'], ['Breaking Heat', 'partial'], ['The Matrix', 'none'], ['Dragon Throne', 'none']],
        array_map(fn ($i) => [$i['title'], $i['state']], $data['rows'][0]['items']), 'with their state');
    same('https://image.tmdb.org/t/p/w300/heat.jpg', $data['rows'][0]['items'][0]['poster'], 'TMDB posters');
    $seerrCalls = array_filter(state()['requests'], fn ($r) => str_starts_with($r['path'], 'seerr:/discover') || str_starts_with($r['path'], 'seerr:/request'));
    ok($seerrCalls && !array_filter($seerrCalls, fn ($r) => ($r['as'] ?? 1) !== $seerrUser), 'every call as the customer (X-API-User)');

    $search = ext()->requestsData(tabSource($service), 'matrix');
    same([['The Matrix', 'movie', 603]], array_map(fn ($i) => [$i['title'], $i['type'], $i['tmdb']], $search['results']), 'search');
    $show = ext()->requestsData(tabSource($service), '', 1396)['show'];
    same(['Breaking Heat', 2008, 1396], [$show['title'], $show['year'], $show['tmdb']], 'the show');
    same([[1, 'available'], [2, 'none'], [3, 'none']], array_map(fn ($x) => [$x['number'], $x['state']], $show['seasons']), 'seasons, no specials, season 1 already there');
});

test('Requests tab (Seerr): a movie and seasons are requested as the customer, and show up under "My requests"', function () {
    [$service, $seerrUser] = seerrTabCustomer('10/30');
    $sources = [tabSource($service)];
    ext()->placeRequest($sources, $service->id, 'movie', 603);
    ext()->placeRequest($sources, $service->id, 'tv', 1396, ['2', '3', 'x']);
    $made = state()['seerr']['requests'];
    same([[$seerrUser, 'movie', 603, []], [$seerrUser, 'tv', 1396, [2, 3]]], array_map(fn ($r) => [$r['requestedBy'], $r['media']['mediaType'], $r['media']['tmdbId'], array_column($r['seasons'], 'seasonNumber')], $made), 'made by the customer');
    $data = ext()->requestsData(tabSource($service));
    same([['Breaking Heat', 'pending', [2, 3]], ['The Matrix', 'pending', []]], array_map(fn ($r) => [$r['title'], $r['status'], $r['seasons']], $data['mine']), 'my requests, newest first');
    same([9, 8], array_column($data['quota']['parts'], 'remaining'), 'the limit counts them: 1 movie, 2 seasons');
    tellsCustomer(fn () => ext()->placeRequest($sources, $service->id, 'movie', 603), 'already been requested', 'twice');
    tellsCustomer(fn () => ext()->placeRequest($sources, $service->id, 'tv', 1396, []), 'at least one season', 'no season');
    tellsCustomer(fn () => ext()->placeRequest($sources, 999, 'movie', 949), 'can\'t be requested', 'another customer\'s library');
});

test('Requests tab (Seerr): the customer\'s own limit decides', function () {
    [$service] = seerrTabCustomer('1/7');
    $sources = [tabSource($service)];
    ext()->placeRequest($sources, $service->id, 'movie', 603);
    tellsCustomer(fn () => ext()->placeRequest($sources, $service->id, 'tv', 1399), 'your limit is used', 'over the limit');
});

test('Paywarr Stats: requestQuotaOf() reads only the quota, on Silo and on Seerr', function () {
    [$service, $account] = siloWithRequests('10/30', 1);
    $service->status = 'active';
    stub('/_test/clear-requests');
    $quota = ext()->requestQuotaOf(ext()->requestsSources((object) ['id' => 1], [$service])[0]);
    same([['requests', 0, 10]], array_map(fn ($p) => [$p['label'], $p['used'], $p['limit']], $quota['parts']), 'Silo');
    ok(!array_filter(state()['requests'], fn ($r) => str_contains($r['path'], 'discover') || str_contains($r['path'], 'search')), 'no discovery or search');
    [$seerr] = seerrTabCustomer('10/30');
    same(['movies', 'tv'], array_column(ext()->requestQuotaOf(tabSource($seerr))['parts'], 'label'), 'Seerr');
    same(null, ext()->requestQuotaOf(['user' => null] + tabSource($seerr)), 'no user yet');
});

test('Requests tab (Silo): read with the key, and "Request in Silo" opens the title in Silo', function () {
    [$service, $account] = siloWithRequests('10/30', 1);
    $service->status = 'active';
    $source = ext()->requestsSources((object) ['id' => 1], [$service])[0];
    same(['silo', (int) $account], [$source['kind'], $source['user']], 'the Silo account');
    $data = ext()->requestsData($source);
    same([['requests', 10, 10, 30]], array_map(fn ($p) => [$p['label'], $p['limit'], $p['remaining'], $p['days']], $data['quota']['parts']), 'Silo\'s own limit');
    same(['trending_movies', 'trending_series', 'popular_movies', 'popular_series', 'upcoming_movies'], array_column($data['rows'], 'key'), 'Silo\'s rows');
    same([['Heat', 'available'], ['The Matrix', 'none']], array_map(fn ($i) => [$i['title'], $i['state']], $data['rows'][0]['items']), 'states');

    $html = ext()->requestsHtml($data + ['libraries' => []], '/wizarr/requests', 'TOKEN');
    ok(str_contains($html, 'href="https://watch.example.com/title/movie/603"') && str_contains($html, 'Request in Silo'), 'a movie opens in Silo');
    ok(!str_contains($html, 'href="https://watch.example.com/title/movie/949"'), 'not one that is available');
    ok(!str_contains($html, 'name="tmdb"'), 'no request form on Silo');
    same(null, ext()->requestsData($source, '', 1399)['show'], 'no season page on Silo: seasons are chosen in Silo');
    tellsCustomer(fn () => ext()->placeRequest([$source], $service->id, 'movie', 603), 'can\'t be requested', 'a request posted anyway is refused');
    same([], siloState()['media_requests'], 'nothing requested in Silo');
    same([], siloState()['sessions'], 'and no sign-in as the account');
    $series = ext()->requestsHtml(['rows' => [['key' => 'trending_series', 'items' => [['type' => 'tv', 'tmdb' => 1399, 'title' => 'Dragon Throne', 'year' => 2011, 'poster' => null, 'state' => 'partial']]]]] + $data + ['libraries' => []]);
    ok(str_contains($series, 'href="https://watch.example.com/title/series/1399"'), 'a partly available show opens in Silo too');
});

test('top bar: "Open Silo" once the customer has a user, not before; Danish too', function () {
    global $STUB;
    $pending = service(30);
    $pending->status = 'active';
    $pending->product = (object) ['server' => (object) ['id' => 9, 'extension' => 'Wizarr', 'settings' => ['backend' => 'silo', 'host' => $STUB . '/silo', 'public_url' => 'https://watch.example.com', 'api_key' => 'sa_full']], 'settings' => siloSettings()];
    silo()->createServer($pending, siloSettings(), props($pending));
    same(null, ext()->navWatchLink((object) ['id' => 1], [$pending]), 'invitation not used yet: no link (not "Create your user")');

    [$service] = siloWithRequests('', 31);
    $service->status = 'active';
    same(['label' => 'Open Silo', 'url' => 'https://watch.example.com'], ext()->navWatchLink((object) ['id' => 1], [$pending, $service]), 'the first subscription with a user');
    same('Åbn Silo', inLocale('da', fn () => ext()->navWatchLink((object) ['id' => 1], [$service]))['label'], 'Danish');
    $service->status = 'suspended';
    same(null, ext()->navWatchLink((object) ['id' => 1], [$service]), 'not while suspended');
});

test('Requests tab: not ready before the customer has a user, and "every active customer" gives everyone requests', function () {
    $service = reportCustomer('', 70);
    same([], ext()->requestsSources((object) ['id' => 1], [$service]), 'no requests bought: no tab');
    $service->product->server->settings['requests_page_for'] = 'all';
    $source = ext()->requestsSources((object) ['id' => 1], [$service]);
    same([1, null], [count($source), $source[0]['user']], 'everyone: the tab, but no Seerr account before a user exists');
    same(false, ext()->requestsData($source[0])['ready'], 'not ready yet');
    ok(str_contains(ext()->requestsHtml(ext()->requestsData($source[0]) + ['libraries' => []]), 'being set up'), 'says so');
});

test('Requests tab: the page shows it all, escapes what it prints, offers "Buy more" when used up, and speaks Danish', function () {
    [$service] = seerrTabCustomer('1/7');
    $service->product->server->settings['more_requests_url'] = 'https://shop.example.com/extra';
    ext()->placeRequest([tabSource($service)], $service->id, 'movie', 603);
    $data = ext()->requestsData(tabSource($service)) + ['libraries' => []];
    $html = ext()->requestsHtml($data, '/wizarr/requests', 'TOKEN');
    foreach (['Requests', '0</b> of 1 movies left', 'Buy more requests', 'https://shop.example.com/extra', 'Trending', 'My requests', 'The Matrix', 'Available'] as $needle) {
        ok(str_contains($html, $needle), "shows \"$needle\"");
    }
    $seasons = ext()->requestsHtml(ext()->requestsData(tabSource($service), '', 1399) + ['libraries' => []], '/wizarr/requests', 'TOKEN');
    ok(str_contains($seasons, 'name="_token" value="TOKEN"') && str_contains($seasons, 'name="seasons[]" value="2"'), 'the seasons form, with the CSRF token');
    $data['rows'][0]['items'][0]['title'] = '<script>x</script>';
    ok(!str_contains(ext()->requestsHtml($data), '<script>x'), 'escaped');
    ok(str_contains(inLocale('da', fn () => ext()->requestsHtml($data)), 'Mine anmodninger'), 'Danish');
});

/* ---------- extra requests: a top-up product ---------- */

function topUpService(int $id, int $quantity = 1, int $extra = 5): Service
{
    global $STUB;
    $topUp = service($id);
    $topUp->status = 'active';
    $topUp->quantity = $quantity;
    $topUp->product = (object) ['name' => 'Extra requests', 'server' => (object) ['id' => 9, 'extension' => 'Wizarr', 'settings' => ['backend' => 'silo', 'host' => $STUB . '/silo', 'public_url' => 'https://watch.example.com', 'api_key' => 'sa_full']],
        'settings' => siloSettings(['extra_requests' => (string) $extra])];

    return $topUp;
}

test('extra requests: a top-up adds to the package for its period, times the quantity, once', function () {
    [$service, $account] = siloWithRequests('10/30', 1);
    $service->status = 'active';
    $topUp = topUpService(80, 2);
    $now = time();
    same(10, silo()->applyTopUp($topUp, $topUp->product->settings, [$service, $topUp], $now), '2 x 5');
    same(['custom', 20, 30], array_slice(siloLimit($account), 1), 'the limit in Silo: 10 + 10 for 30 days');
    $until = strtotime((string) $topUp->propertyArray()['topup_until']);
    ok(abs($until - ($now + 30 * 86400)) < 5, 'until the package\'s period from now');
    ok(str_contains(json_encode(labels(silo()->getActions($topUp, $topUp->product->settings, props($topUp)))), 'Added to subscription #1'), 'the top-up\'s client area says where');
    \App\Models\Service::$all = [$service, $topUp];
    same(['invite_url' => '', 'invite_code' => '', 'invite_valid_for' => '', 'login_url' => '', 'extra_requests' => 10], silo()->createServer($topUp, $topUp->product->settings, props($topUp)), 'run again: nothing more, no invitation');
    same(20, siloLimit($account)[2], 'still 20');
    same(1, count(json_decode($service->propertyArray()['extra_requests'], true)), 'one entry');
});

test('extra requests: they expire after the period, and come off again when refunded', function () {
    [$service, $account] = siloWithRequests('10/30', 1);
    $service->status = 'active';
    $topUp = topUpService(81);
    silo()->applyTopUp($topUp, $topUp->product->settings, [$service, $topUp]);
    same(15, siloLimit($account)[2], 'added');
    // 31 days later the hourly run finds the entry expired.
    $list = json_decode($service->propertyArray()['extra_requests'], true);
    $list[0]['until'] = time() - 60;
    $service->properties()->updateOrCreate(['key' => 'extra_requests'], ['name' => 'Extra requests', 'value' => json_encode($list)]);
    $service->unsetRelation('properties');
    $service->loadedProperties = null;
    ext()->reconcileSeerr([$service]);
    same(10, siloLimit($account)[2], 'back to the package');

    $again = topUpService(82);
    silo()->applyTopUp($again, $again->product->settings, [$service, $again]);
    same(15, siloLimit($account)[2], 'a new one');
    \App\Models\Service::$all = [$service, $again];
    silo()->suspendServer($again, $again->product->settings, props($again)); // refunded
    same(10, siloLimit($account)[2], 'refunded: off again');
});

test('extra requests: only on top of a package with a limit, refused at checkout otherwise', function () {
    [$unlimited] = siloWithRequests('unlimited', 1);
    $unlimited->status = 'active';
    $topUp = topUpService(83);
    same(null, silo()->topUpTarget($topUp, [$unlimited, $topUp]), 'unlimited can\'t grow');
    tellsCustomer(fn () => silo()->applyTopUp($topUp, $topUp->product->settings, [$unlimited, $topUp]), 'You need one first', 'refused');
    [$limited] = siloWithRequests('5', 2);
    $limited->status = 'active';
    same(2, silo()->topUpTarget($topUp, [$unlimited, $topUp, $limited])->id, 'the one with a limit');
});

/* ---------- Discord leavers ---------- */

/** A Discord-only Wizarr subscription whose customer connected Discord. */
function discordCustomer(int $id, array $serverSettings = [], array $product = ['require_discord' => true], ?string $discordId = '4242'): Service
{
    $service = wizarrService($id, discordSettings($serverSettings + ['discord_suspend_leavers' => true, 'discord_grace_hours' => '24']));
    $service->product->settings = $product + settings();
    $service->product->name = 'Silo';
    if ($discordId) {
        $service->user->properties()->updateOrCreate(['key' => 'discord_id'], ['name' => 'Discord ID', 'value' => $discordId]);
    }

    return $service;
}

/** Runs the hourly membership check once, recording what it would do. */
function leavers(array $services, int $now, $member, array &$log): array
{
    $isMember = is_callable($member) ? $member : fn () => $member;

    return ext()->reconcileDiscordMembership(
        $services,
        $now,
        $isMember,
        function ($s) use (&$log) { $log[] = 'suspend ' . $s->id; $s->status = 'suspended'; },
        function ($s) use (&$log) { $log[] = 'unsuspend ' . $s->id; $s->status = 'active'; },
        function ($user, $kind, $replace) use (&$log) { $log[] = $kind . ' ' . $user->email . ' ' . $replace['hours']; },
    );
}

test('discord leavers: warned first, suspended when the hours are up, opened again on rejoin', function () {
    $service = discordCustomer(90);
    $log = [];
    $t = 1_800_000_000;
    same([90 => 'warned'], leavers([$service], $t, false, $log), 'left: warned');
    same(['warning customer90@example.com 24'], $log, 'told, with the hours');
    same([], leavers([$service], $t + 23 * 3600, false, $log), 'still within the hours: nothing');
    same([90 => 'suspended'], leavers([$service], $t + 24 * 3600, false, $log), 'hours up: suspended');
    same('suspended', $service->status, 'status');
    same([], leavers([$service], $t + 30 * 3600, false, $log), 'suspended once only');
    same([90 => 'reopened'], leavers([$service], $t + 40 * 3600, true, $log), 'back on the Discord');
    same('active', $service->status, 'open again');
    same(['warning customer90@example.com 24', 'suspend 90', 'suspended customer90@example.com 24', 'unsuspend 90', 'reopened customer90@example.com 24'], $log, 'in that order');
    same([], leavers([$service], $t + 41 * 3600, true, $log), 'nothing more to do');
});

test('discord leavers: rejoining within the hours clears the warning', function () {
    $service = discordCustomer(91);
    $log = [];
    leavers([$service], 1000, false, $log);
    same([91 => 'cleared'], leavers([$service], 2000, true, $log), 'cleared');
    same([91 => 'warned'], leavers([$service], 3000, false, $log), 'leaving again starts the hours again');
    same('active', $service->status, 'never suspended');
});

test('discord leavers: 0 hours suspends at once, without a warning', function () {
    $service = discordCustomer(92, ['discord_grace_hours' => '0']);
    $log = [];
    same([92 => 'suspended'], leavers([$service], 1000, false, $log), 'suspended');
    same(['suspend 92', 'suspended customer92@example.com 0'], $log, 'no warning first');
});

test('discord leavers: only Discord-only products, with the setting on, and customers who connected Discord', function () {
    $spy = function () { throw new RuntimeException('must not ask Discord'); };
    $log = [];
    same([], leavers([discordCustomer(93, ['discord_suspend_leavers' => false])], 1000, $spy, $log), 'setting off');
    same([], leavers([discordCustomer(94, [], ['require_discord' => false])], 1000, $spy, $log), 'product without the Discord requirement');
    same([], leavers([discordCustomer(95, [], ['require_discord' => true], null)], 1000, $spy, $log), 'never connected Discord');
    same([], leavers([discordCustomer(96, ['discord_bot_token' => ''])], 1000, $spy, $log), 'Discord not set up');
    $cancelled = discordCustomer(97);
    $cancelled->status = 'cancelled';
    same([], leavers([$cancelled], 1000, $spy, $log), 'cancelled');
    $other = discordCustomer(98);
    $other->product->server->extension = 'Pterodactyl';
    same([], leavers([$other], 1000, $spy, $log), 'another extension');
    same([], $log, 'nobody told');
});

test('discord leavers: Discord not answering changes nothing, and each customer is asked once', function () {
    $log = [];
    $down = fn () => throw new \Paymenter\Extensions\Servers\Wizarr\DiscordException('Discord is down');
    same([], leavers([discordCustomer(99)], 1000, $down, $log), 'nothing done');
    same([], $log, 'nobody told');
    $asked = 0;
    $count = function () use (&$asked) { $asked++; return true; };
    leavers([discordCustomer(100), discordCustomer(101)], 1000, $count, $log);
    same(1, $asked, 'two subscriptions, one question');
});

test('discord leavers: a suspension for another reason is not lifted on rejoin', function () {
    $service = discordCustomer(102);
    $service->status = 'suspended'; // e.g. an unpaid invoice
    $log = [];
    same([], leavers([$service], 1000, false, $log), 'not warned while suspended');
    same([], leavers([$service], 2000, true, $log), 'not opened');
    same('suspended', $service->status, 'still suspended');
});

test('discord leavers: the customer messages are translated', function () {
    inLocale('da', function () {
        same('Din adgang er sat på pause', \Paymenter\Extensions\Servers\Wizarr\Wizarr::t('Your access is paused'), 'title');
        ok(str_contains(\Paymenter\Extensions\Servers\Wizarr\Wizarr::t('Your subscription :service needs you on our Discord. Join again within :hours hours, or your access will be paused.', ['service' => 'Silo', 'hours' => 24]), 'inden for 24 timer'), 'body');
    });
});

/* ---------- new links and Paywarr → Customers ---------- */

test('new link: "Problems? Get a new link" replaces an unused link, at most once an hour', function () {
    $service = service();
    $old = ext()->createServer($service, settings(), []);
    $url = ext()->freshInvitation($service, settings(), $service->propertyArray());
    $invitations = state()['invitations'];
    same(1, count($invitations), 'the old one is gone, one new');
    ok($invitations[0]['code'] !== $old['invite_code'], 'a different code');
    same('https://join.example.com/j/' . $invitations[0]['code'], $url, 'sent to the new link');
    same(['text:Status=Waiting for you to create your user', 'button:Create your user'], labels(ext()->getActions($service, settings(), $service->propertyArray())), 'no button again within the hour');
    tellsCustomer(fn () => ext()->freshInvitation($service, settings(), $service->propertyArray()), 'once an hour', 'refused within the hour');
});

test('new link: a used link without a user (failed Plex share) is replaced by the customer; one with a user only when allowed in admin', function () {
    $service = service();
    $data = ext()->createServer($service, settings(), []);
    stub('/_test/fail-redeem', ['code' => $data['invite_code']]); // the Plex share failed: code spent, no user
    same(['text:Status=Your user was not created', 'button:Problems? Get a new link'], labels(ext()->getActions($service, settings(), $service->propertyArray())), 'told, with a way out');
    same('failed', ext()->liveBannerState($service, $service->propertyArray())['state'], 'the box says so too, without the used link');
    ext()->freshInvitation($service, settings(), $service->propertyArray());
    same(1, count(array_filter(state()['invitations'], fn ($i) => !$i['used'])), 'the customer got a new link themselves');
    same(['text:Status=Waiting for you to create your user', 'button:Create your user'], labels(ext()->getActions($service, settings(), $service->propertyArray())), 'and can use it');

    // A customer with a user only gets a new link when allowed in admin.
    redeem(array_values(array_filter(state()['invitations'], fn ($i) => !$i['used']))[0]['code'], 'mette');
    $labels = labels(ext()->getActions($service, settings(), $service->propertyArray()));
    ok(!in_array('button:Get a new invitation link', $labels, true), 'no new link by default: ' . json_encode($labels));
    $service->properties()->updateOrCreate(['key' => 'wizarr_relinked_at'], ['name' => 'x', 'value' => '0']);
    $service->unsetRelation('properties');
    ext()->freshInvitation($service, settings(), $service->propertyArray());
    same(0, count(array_filter(state()['invitations'], fn ($i) => !$i['used'])), 'a user exists: no second seat');

    ext()->adminAllowNewLink($service);
    same(null, ext()->bannerState($service, $service->propertyArray()), 'has a user: no box, the button is on the subscription');
    $labels = labels(ext()->getActions($service, settings(), $service->propertyArray()));
    ok(in_array('button:Get a new invitation link', $labels, true), 'allowed in admin: the button shows');
    $url = ext()->freshInvitation($service, settings(), $service->propertyArray());
    $pending = array_values(array_filter(state()['invitations'], fn ($i) => !$i['used']));
    same(1, count($pending), 'a new, unused invitation');
    same('https://join.example.com/j/' . $pending[0]['code'], $url, 'sent to it');
    ok(empty($service->propertyArray()['wizarr_new_link_allowed']), 'allowed once');
    redeem($pending[0]['code'], 'mette2');
    same('text:Status=Active', labels(ext()->getActions($service, settings(), $service->propertyArray()))[0], 'the customer gets in');
});

/** A Paywarr customer as the admin page finds them: product set, user created and seen. */
function adminCustomer(bool $withUser = true, array $productSettings = []): Service
{
    $service = wizarrService(1);
    $service->product->settings = $productSettings + settings();
    if (!$withUser) {
        ext()->createServer($service, $service->product->settings, []);

        return $service;
    }
    subscribed($service->product->settings, 'mette', $service);
    ext()->getActions($service, $service->product->settings, $service->propertyArray()); // a page view records the user

    return $service;
}

test('admin: a new invitation, mailed or not; never instead of an existing user', function () {
    $service = adminCustomer(false);
    $old = state()['invitations'][0]['code'];
    \App\Helpers\NotificationHelper::$sent = [];
    ext()->adminNewInvitation($service, true);
    $code = state()['invitations'][0]['code'];
    ok($code !== $old && count(state()['invitations']) === 1, 'replaced');
    same([['customer1@example.com', 'https://join.example.com/j/' . $code]], array_map(fn ($m) => [$m['email'], $m['data']['invite_url']], \App\Helpers\NotificationHelper::$sent), 'mailed with the new link');
    ext()->adminNewInvitation($service, false);
    same(1, count(\App\Helpers\NotificationHelper::$sent), 'not mailed when asked not to');
    redeem(state()['invitations'][0]['code'], 'mette');
    ext()->getActions($service, settings(), $service->propertyArray());
    tellsCustomer(fn () => ext()->adminNewInvitation($service), 'Reset user', 'has a user');
});

test('admin: reset user forgets the user and sends a new invitation; sync finds a user again', function () {
    $service = adminCustomer();
    same('1:mette:jellyfin', grantProp($service, 'wizarr_users'), 'has a user');
    ext()->adminResetUser($service, false);
    same(null, grantProp($service, 'wizarr_users'), 'forgotten');
    same(1, count(array_filter(state()['invitations'], fn ($i) => !$i['used'])), 'a new invitation');
    same(1, count(state()['users']), 'the user stays on the media server');
    redeem(array_values(array_filter(state()['invitations'], fn ($i) => !$i['used']))[0]['code'], 'mette2');
    same(['mette2'], ext()->adminSync($service), 'sync records the new user');
    same('2:mette2:jellyfin', grantProp($service, 'wizarr_users'), 'stored');
});

test('admin: sync tells when the link was used but no user was made (a failed Plex share)', function () {
    $service = adminCustomer(false);
    stub('/_test/fail-redeem', ['code' => state()['invitations'][0]['code']]);
    tellsCustomer(fn () => ext()->adminSync($service), 'no user was made', 'told');
    ext()->adminAllowNewLink($service);
    same(true, ext()->adminSummary($service)['new_link'], 'allowed');
});

test('admin: sync of a known user that Wizarr no longer has says so, and marks nothing', function () {
    $service = adminCustomer();
    $service->properties()->updateOrCreate(['key' => 'wizarr_link_failed'], ['name' => 'x', 'value' => '1']); // marked by 1.24.0
    $service->unsetRelation('properties');
    ext()->adminDeleteInvitation((int) state()['invitations'][0]['id']);   // the used link is cleaned up in Wizarr
    stub('/_test/set-user', ['id' => 1, 'username' => 'renamed']);       // and the user renamed there
    tellsCustomer(fn () => ext()->adminSync($service), 'mette on Jellyfin is no longer in Wizarr', 'told');
    same('1:mette:jellyfin', grantProp($service, 'wizarr_users'), 'the known user is kept');
    ok(empty($service->propertyArray()['wizarr_link_failed']), 'not a failed link: the old mark is cleared');
});

test('sync: a user Wizarr re-imported with a new id is found again by name and server', function () {
    $service = adminCustomer();
    ext()->adminDeleteInvitation((int) state()['invitations'][0]['id']);
    stub('/_test/set-user', ['id' => 1, 'username' => 'old-row']);                   // the old row is gone in effect
    $new = stub('/_test/add-user', ['username' => 'mette', 'server_id' => 1]);         // re-imported with a new id
    stub('/_test/add-user', ['username' => 'mette', 'server_id' => 2]);                // the same name on Plex is someone else
    same(['mette'], ext()->adminSync($service), 'found');
    same($new['id'] . ':mette:jellyfin', grantProp($service, 'wizarr_users'), 'the new id is stored');
});

test('admin: re-invite after a new media server: missing users get a new link, present ones are left alone', function () {
    $service = adminCustomer();
    same([], ext()->adminReinvite($service), 'the user exists: nothing to do');
    ext()->adminDeleteInvitation((int) state()['invitations'][0]['id']);
    ext()->adminUserAction(1, 'delete');                                   // the new Jellyfin has no users
    \App\Helpers\NotificationHelper::$sent = [];
    [$url] = ext()->adminReinvite($service);
    $pending = array_values(array_filter(state()['invitations'], fn ($i) => !$i['used']));
    same(['https://join.example.com/j/' . $pending[0]['code'], [1]], [$url, $pending[0]['server_ids']], 'a new link to Jellyfin');
    same(null, grantProp($service, 'wizarr_users'), 'the gone user is forgotten');
    same(1, count(\App\Helpers\NotificationHelper::$sent), 'mailed');
    same(['text:Status=Waiting for you to create your user', 'button:Create your user', 'button:Problems? Get a new link'], labels(ext()->getActions($service, settings(), $service->propertyArray())), 'the customer sees the new link');
});

test('admin: re-invite when the media server itself is gone from Wizarr: the product\'s server takes its place', function () {
    $service = adminCustomer(false);
    foreach (['wizarr_invitation_id:1', 'wizarr_invite_code:1', 'wizarr_invite_url:1', 'wizarr_invite_expires:1', 'wizarr_server:1', 'wizarr_server_url:1'] as $key) {
        $service->properties()->where('key', $key)->delete();
    }
    foreach (['wizarr_users:9' => '77:mette:jellyfin', 'wizarr_server:9' => 'Old Jellyfin', 'wizarr_servers' => '9'] as $key => $value) {
        $service->properties()->updateOrCreate(['key' => $key], ['name' => $key, 'value' => $value]);
    }
    $service->unsetRelation('properties');
    [$url] = ext()->adminReinvite($service, false);
    $props = $service->propertyArray();
    ok(!isset($props['wizarr_users:9']) && !isset($props['wizarr_server:9']), 'the gone server is dropped');
    same('1', $props['wizarr_servers'] ?? null, 'the product\'s Jellyfin instead');
    ok(str_contains($url, '/j/'), 'a new link: ' . $url);

    $stuck = adminCustomer(false);
    $stuck->product->settings = settings(['server_ids' => ['9']]);
    foreach (['wizarr_users:1' => '78:x:jellyfin'] as $key => $value) {
        $stuck->properties()->updateOrCreate(['key' => $key], ['name' => $key, 'value' => $value]);
    }
    $stuck->properties()->updateOrCreate(['key' => 'wizarr_servers'], ['name' => 'x', 'value' => '9']);
    $stuck->unsetRelation('properties');
    tellsCustomer(fn () => ext()->adminReinvite($stuck, false), 'Choose the new one on the product first', 'the product still points to the old server');
    same('78:x:jellyfin', grantProp($stuck, 'wizarr_users'), 'and nothing was changed');
});

test('admin: give trial days, extend, end now and reopen, restart; only on a trial', function () {
    $service = adminCustomer();
    $now = time();
    same('set', ext()->adminGiveTrialDays($service, 7, $now), 'a week for a friend');
    $summary = ext()->adminSummary($service);
    same([7, true], [$summary['trial']['days'], $summary['trial']['given']], 'a given trial');
    same($now + 7 * 86400, $summary['trial']['ends'], 'ends in 7 days');
    ext()->adminExtendTrial($service, 3, $now);
    same($now + 10 * 86400, ext()->adminSummary($service)['trial']['ends'], 'extended by 3 days');

    same('ended', ext()->adminEndTrial($service, $now), 'ended now');
    same(['cancelled', true], [$service->status, state()['users'][0]['is_disabled']], 'closed, user disabled');
    same('reopened', ext()->adminExtendTrial($service, 2, $now), 'given 2 more days');
    same(['active', false], [$service->status, state()['users'][0]['is_disabled']], 'open again, user enabled');
    same(null, ext()->adminSummary($service)['trial']['ended'], 'no longer ended');

    same('set', ext()->adminRestartTrial($service, $now), 'restarted');
    same($now + 7 * 86400, ext()->adminSummary($service)['trial']['ends'], 'the full 7 days again');

    $waiting = adminCustomer(false);
    same('waiting', ext()->adminGiveTrialDays($waiting, 5, $now), 'no user yet: the clock starts when they create it');
    $plain = adminCustomer(false);
    tellsCustomer(fn () => ext()->adminSetTrial($plain, null, $now + 86400), 'not a trial', 'only trials');
});

test('admin: a forgiven trial no longer counts at checkout', function () {
    $user = new User('mette@example.com');
    $old = new Service(1, $user);
    $old->product = (object) ['server_id' => 10, 'server' => (object) ['id' => 10, 'extension' => 'Wizarr'], 'settings' => trialSettings()];
    $new = new Service(2, $user);
    $new->product = $old->product;
    same(['trial'], ext()->trialHistory($new, [$old]), 'had a trial');
    ext()->adminAllowNewTrial($old);
    $old->loadedProperties = null;
    same([], ext()->trialHistory($new, [$old]), 'forgiven: may try again');
    same(true, ext()->adminSummary($old)['trial']['forgiven'], 'shown in admin');
});

test('admin: the summary shows users, trial and Discord from what is stored, without calls', function () {
    $service = adminCustomer();
    $service->user->properties()->updateOrCreate(['key' => 'discord_username'], ['name' => 'Discord', 'value' => 'waffle.fan']);
    $service->user->properties()->updateOrCreate(['key' => 'discord_id'], ['name' => 'Discord ID', 'value' => '4242']);
    stub('/_test/clear-requests');
    $summary = ext()->adminSummary($service);
    same([['mette']], array_column($summary['grants'], 'users'), 'the user');
    same(['waffle.fan', '4242', null, false], [$summary['discord'], $summary['discord_id'], $summary['trial'], $summary['new_link']], 'Discord; not a trial');
    same([], state()['requests'], 'no calls to Wizarr');
});

test('admin: the inventory shows each Wizarr with its servers, libraries, users and invitations, and who owns them', function () {
    $service = adminCustomer();                     // mette, with a user
    $waiting = wizarrService(2);                    // customer2, an unused invitation
    ext()->createServer($waiting, settings(), []);
    stub('/_test/add-user', ['username' => 'stranger', 'server_id' => 1]);
    $server = (object) ['id' => 7, 'name' => 'WaffleHub Jellyfin', 'settings' => ['host' => $GLOBALS['STUB'] . '/', 'api_key' => 'stub-key', 'public_url' => 'https://join.example.com/']];
    $twin = (object) ['id' => 8, 'name' => 'WaffleHub Plex', 'settings' => $server->settings];
    foreach ([$service, $waiting] as $s) {
        $s->product->server->id = 7;
        $s->product->server->settings = $server->settings;
    }
    [$backend] = ext()->adminInventory([$server, $twin], [$service, $waiting]);
    same([true, [7 => 'WaffleHub Jellyfin', 8 => 'WaffleHub Plex']], [$backend['ok'], $backend['paymenter']], 'one Wizarr behind two Paymenter servers');
    same(['Jellyfin' => [2, 2], 'Plex' => [0, 1]], array_intersect_key(array_combine(array_column($backend['servers'], 'name'), array_map(fn ($x) => [$x['users'], $x['libraries']], $backend['servers'])), ['Jellyfin' => 1, 'Plex' => 1]), 'users and libraries per media server');
    same([['mette', 1], ['stranger', null]], array_map(fn ($u) => [$u['username'], $u['owner']['service'] ?? null], $backend['users']), 'a user with and one without a subscription');
    same([[1, 'used'], [2, 'pending']], array_map(fn ($i) => [$i['owner']['service'] ?? null, $i['status']], $backend['invitations']), 'invitations with their subscription');

    [$down] = ext()->adminInventory([(object) ['id' => 9, 'name' => 'Old', 'settings' => ['host' => 'http://127.0.0.1:1/', 'api_key' => 'x']]], []);
    same(false, $down['ok'], 'an unreachable Wizarr is shown as such');
    ok($down['error'] !== '', 'with the reason');
});

test('admin: link an existing user to a subscription; never one that belongs to someone else', function () {
    $friend = stub('/_test/add-user', ['username' => 'old.friend', 'server_id' => 1]);
    $service = adminCustomer(false);
    same('old.friend (Jellyfin)', ext()->adminLinkUser($service, (int) $friend['id']), 'linked');
    same($friend['id'] . ':old.friend:jellyfin', grantProp($service, 'wizarr_users'), 'stored as the subscription\'s user');
    same([], array_values(array_filter(state()['invitations'], fn ($i) => !$i['used'])), 'the unused link is withdrawn');
    same('text:Status=Active', labels(ext()->getActions($service, settings(), $service->propertyArray()))[0], 'the customer is in');

    $other = adminCustomer(false);
    $other->id = 5;
    tellsCustomer(fn () => ext()->adminLinkUser($other, (int) $friend['id'], [$service]), 'belongs to subscription #1', 'someone else\'s');
    $plex = stub('/_test/add-user', ['username' => 'plexy', 'server_id' => 2]);
    tellsCustomer(fn () => ext()->adminLinkUser($other, (int) $plex['id']), 'does not include', 'a server the subscription does not have');
});

test('admin: reset can delete the user on the media server too; a failed link is marked for the Problems filter', function () {
    $service = adminCustomer();
    ext()->adminResetUser($service, false, true);
    same(0, count(state()['users']), 'deleted on the media server');

    $failed = adminCustomer(false);
    stub('/_test/fail-redeem', ['code' => grantProp($failed, 'wizarr_invite_code')]);
    ext()->getActions($failed, settings(), $failed->propertyArray());
    ok(!empty($failed->propertyArray()['wizarr_link_failed']), 'marked');
    ext()->adminNewInvitation($failed, false);
    ok(empty($failed->propertyArray()['wizarr_link_failed']), 'cleared by a new link');

    same('Deleted.', ext()->adminUserAction((int) stub('/_test/add-user', ['username' => 'x', 'server_id' => 1])['id'], 'delete'), 'Users page: delete');
});

test('admin: pause keeps the paid time and resumes by itself; no invoice or termination meanwhile', function () {
    $service = adminCustomer();
    $now = time();
    $service->expires_at = date('Y-m-d H:i:s', $now + 10 * 86400);   // 10 days paid
    ext()->adminPause($service, $now + 14 * 86400, $now);
    same('suspended', $service->status, 'suspended');
    same(true, state()['users'][0]['is_disabled'], 'the user is switched off');
    same($now + 24 * 86400, strtotime($service->expires_at), 'expires after the pause plus the paid days left, so Paymenter neither bills nor terminates');
    tellsCustomer(fn () => ext()->adminPause($service), 'Only an active', 'twice');
    same([], ext()->resumePausedServices([$service], $now + 13 * 86400), 'not yet');
    same([1], ext()->resumePausedServices([$service], $now + 14 * 86400), 'resumed when the pause ends');
    same(['active', false], [$service->status, state()['users'][0]['is_disabled']], 'open again');
    same($now + 24 * 86400, strtotime($service->expires_at), '10 paid days left from the end of the pause');
    tellsCustomer(fn () => ext()->adminResume($service), 'not paused', 'not paused any more');
});

test('admin: move a subscription to another media server; libraries for one customer', function () {
    $service = adminCustomer();
    \App\Helpers\NotificationHelper::$sent = [];
    ext()->adminChangeServers($service, [2]);  // Jellyfin -> Plex
    same('2', $service->propertyArray()['wizarr_servers'] ?? null, 'Plex chosen');
    $plex = array_values(array_filter(state()['invitations'], fn ($i) => $i['server_ids'] === [2]));
    same(1, count($plex), 'an invitation for Plex');
    same(1, count(\App\Helpers\NotificationHelper::$sent), 'mailed');
    tellsCustomer(fn () => ext()->adminChangeServers($service, [99]), 'no longer exists', 'unknown server');

    $other = adminCustomer(false);
    ext()->adminSetLibraries($other, [11]);
    ext()->adminNewInvitation($other, false);
    $last = end(state()['invitations']);
    same([11], $last['library_ids'], 'the next invitation has only the customer\'s libraries');
    ext()->adminSetLibraries($other, []);
    ext()->adminNewInvitation($other, false);
    same([10, 11], end(state()['invitations'])['library_ids'], 'back to the product\'s');
});

test('admin: a password link for Jellyfin users, never for Plex; the log keeps what was done', function () {
    $service = adminCustomer();
    $links = ext()->adminPasswordReset($service, false);
    same(['Jellyfin'], array_keys($links), 'per server');
    ok(str_contains($links['Jellyfin'], '/reset/'), 'a reset link: ' . $links['Jellyfin']);
    $plex = wizarrService(3);
    $plex->product->settings = settings(['server_ids' => ['2']]);
    subscribed($plex->product->settings, 'plexy', $plex);
    ext()->getActions($plex, $plex->product->settings, $plex->propertyArray());
    tellsCustomer(fn () => ext()->adminPasswordReset($plex, false), 'Plex', 'Plex has no password here');

    \Paymenter\Extensions\Servers\Wizarr\Wizarr::logAdmin($service->user, 1, 'Paused until Dec 1', 'admin@example.com');
    \Paymenter\Extensions\Servers\Wizarr\Wizarr::logAdmin($service->user, 1, 'Password link: Jellyfin https://join.example.com/reset/SECRET', 'admin@example.com');
    \Paymenter\Extensions\Servers\Wizarr\Wizarr::logAdmin($service->user, 1, 'Resumed', 'admin@example.com');
    $log = json_decode($service->user->properties()->where('key', 'paywarr_log')->value('value'), true);
    same(['Resumed', 'Password link: Jellyfin [link]', 'Paused until Dec 1'], array_column($log, 'what'), 'newest first, links left out');
});

test('admin: the health check finds what is wrong in the setup', function () {
    $service = adminCustomer();
    $server = (object) ['id' => 7, 'name' => 'WaffleHub Jellyfin', 'settings' => ['host' => $GLOBALS['STUB'] . '/', 'api_key' => 'stub-key', 'discord_guild_id' => '1', 'discord_bot_token' => 'x']];
    $service->product->server->id = 7;
    $service->product->server->settings = $server->settings;
    $inventory = ext()->adminInventory([$server], [$service]);
    $products = [
        (object) ['id' => 1, 'name' => 'No server', 'server_id' => 7, 'per_user_limit' => null, 'settings' => ['server_ids' => []]],
        (object) ['id' => 2, 'name' => 'Gone libraries', 'server_id' => 7, 'per_user_limit' => null, 'settings' => ['server_ids' => ['1'], 'library_ids' => ['10', '999']]],
        (object) ['id' => 3, 'name' => 'Trial', 'server_id' => 7, 'per_user_limit' => 0, 'settings' => ['server_ids' => ['1'], 'trial_days' => '7']],
        (object) ['id' => 4, 'name' => 'Gift card', 'server_id' => 7, 'per_user_limit' => 0, 'settings' => ['gift_months' => '1']],
    ];
    $rows = ext()->adminHealth($inventory, [$server], $products, [$service]);
    $whats = array_column($rows, 'what');
    foreach (['Product "No server" has no media server', 'Media server "Unverified" is not verified', 'Server "WaffleHub Jellyfin" has no public address',
        'Discord is half set up on "WaffleHub Jellyfin"', 'Product "Gone libraries" has libraries that are gone', 'Trial product "Trial" has no "Per user limit"'] as $needle) {
        ok(in_array($needle, $whats, true), 'finds: ' . $needle);
    }
    ok(!array_filter($whats, fn ($w) => str_contains($w, 'Gift card')), 'a gift card needs no media server');

    $option = (object) ['id' => 5, 'name' => 'Media server', 'env_variable' => 'media_server', 'children' => [
        (object) ['name' => 'Jellyfin', 'env_variable' => 'jellyfin'], (object) ['name' => 'Both', 'env_variable' => 'both'], (object) ['name' => 'Plex', 'env_variable' => 'plex']]];
    $withOption = [
        (object) ['id' => 5, 'name' => 'One server', 'server_id' => 7, 'per_user_limit' => 1, 'settings' => ['server_ids' => ['1']], 'configOptions' => [$option]],
        (object) ['id' => 6, 'name' => 'Two servers', 'server_id' => 7, 'per_user_limit' => 1, 'settings' => ['server_ids' => ['1', '4']], 'configOptions' => [$option]],
    ];
    $media = array_column(ext()->adminHealth($inventory, [], $withOption, []), null, 'what');
    ok(isset($media['Product "One server" asks for a media server it doesn\'t need']), 'one server: the option does nothing');
    ok(str_contains($media['Product "Two servers" offers a media server it doesn\'t have']['detail'] ?? '', 'Plex (plex)'), 'Plex is not on the product');
    same('option:5', $media['Product "Two servers" offers a media server it doesn\'t have']['where'] ?? '', 'links to the option');
    same([], array_filter(array_column(ext()->adminHealth($inventory, [(object) ['id' => 8, 'name' => 'Only the invite', 'settings' => ['host' => 'x', 'public_url' => 'y', 'discord_invite_url' => 'https://discord.gg/x']]], [], []), 'what'), fn ($w) => str_contains($w, 'Discord')), 'the invite link alone is fine');
    same('error', $rows[0]['level'], 'errors first');
    $down = ext()->adminInventory([(object) ['id' => 9, 'name' => 'Old', 'settings' => ['host' => 'http://127.0.0.1:1/', 'api_key' => 'x']]], []);
    ok(str_contains(ext()->adminHealth($down, [], [], [])[0]['what'], 'does not answer'), 'a Wizarr that does not answer');
});

/* ---------- welcome mail, gift cards, reminders, queue ---------- */

function mailsTo(string $email): array
{
    return array_values(array_filter(\Illuminate\Support\Facades\Mail::$sent, fn ($m) => $m['to'] === $email));
}

test('welcome mail: link, steps, apps for the media server, Discord and your own words; Paymenter\'s mail stays for re-invites only when off', function () {
    \Illuminate\Support\Facades\Mail::$sent = [];
    $service = wizarrService(60);
    $service->product->name = 'Jellyfin Plus';
    $welcome = ext(['welcome_mail' => '1', 'welcome_text' => "Velkommen til familien!\nHusk at hygge.", 'discord_invite_url' => 'https://discord.gg/waffle']);
    $data = $welcome->createServer($service, settings(), []);
    [$mail] = mailsTo('customer60@example.com');
    same('Welcome to Jellyfin Plus', $mail['subject'], 'subject');
    foreach ([$data['invite_url'], 'Create your user on Jellyfin', 'Velkommen til familien!<br />', 'jellyfin.org/downloads/clients', 'https://discord.gg/waffle', 'Getting started', 'jellyfin.example.com'] as $needle) {
        ok(str_contains($mail['html'], $needle), 'has ' . $needle);
    }
    \App\Helpers\NotificationHelper::$sent = [];
    $welcome->adminNewInvitation($service, true);
    same(['Your new link for Jellyfin Plus'], array_column(array_slice(mailsTo('customer60@example.com'), 1), 'subject'), 'a new link: Paywarr\'s mail');
    same([], \App\Helpers\NotificationHelper::$sent, 'not Paymenter\'s');

    \Illuminate\Support\Facades\Mail::$sent = [];
    ext()->createServer(wizarrService(61), settings(), []);
    same([], mailsTo('customer61@example.com'), 'off: no welcome mail (Paymenter sends its own)');
});

test('gift card: a one-time code worth what was paid, the client area, sending it to a friend', function () {
    \Illuminate\Support\Facades\Mail::$sent = [];
    $service = wizarrService(62);
    $service->price = 147.0;
    $service->product->settings = ['gift_months' => '3'];
    $made = [];
    $data = ext()->createGift($service, $service->product->settings, function (array $coupon, array $products) use (&$made) { $made[] = $coupon; return 501; });
    ok(preg_match('/^GIFT-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $data['gift_code']) === 1, 'a code: ' . $data['gift_code']);
    same(['fixed', 49.0, 3, 1, 1, 'price'], [$made[0]['type'], $made[0]['value'], $made[0]['recurring'], $made[0]['max_uses'], $made[0]['max_uses_per_user'], $made[0]['applies_to']], '49 off three periods, once');
    same([], state()['invitations'], 'no invitation');
    ok(str_contains(mailsTo('customer62@example.com')[0]['html'] ?? '', $data['gift_code']), 'the buyer gets the code');
    same($data, ext()->createGift($service, $service->product->settings, fn () => throw new RuntimeException('twice')), 'run again: the same code');

    same(['text:Gift code=' . $data['gift_code'], 'button:Send it to a friend'], array_values(array_filter(labels(ext()->getActions($service, $service->product->settings, $service->propertyArray())), fn ($l) => !str_starts_with($l, 'text:Status'))), 'client area');
    ext()->sendGift($service, 'friend@example.com', 'Ole', 'Glædelig jul!');
    [$friend] = mailsTo('friend@example.com');
    ok(str_contains($friend['subject'], 'gave you a gift') && str_contains($friend['html'], $data['gift_code']) && str_contains($friend['html'], 'Glædelig jul!') && str_contains($friend['html'], 'Hi Ole,'), 'the friend gets code, message and how');
    same('friend@example.com', $service->propertyArray()['gift_sent_to'] ?? null, 'noted');
    tellsCustomer(fn () => ext()->sendGift($service, 'not-an-email'), 'doesn\'t look right', 'a bad address');
    same(null, ext()->bannerState($service, $service->propertyArray()), 'no access box for a gift card');
});

test('reminders: trial ending, unused link, renewal with an unpaid invoice; each once, by mail and Discord, only when switched on', function () {
    \Illuminate\Support\Facades\Mail::$sent = [];
    $on = ['remind_trial' => '1', 'remind_link' => '1', 'remind_renewal' => '1'] + discordSettings();
    $now = time();
    $trial = adminCustomer(true, ['trial_days' => '7']);
    $trial->product->server->settings = $on;
    $trial->user->properties()->updateOrCreate(['key' => 'discord_id'], ['name' => 'Discord ID', 'value' => '4242']);
    $trial->properties()->updateOrCreate(['key' => 'trial_ends_at'], ['name' => 'x', 'value' => date(DATE_ATOM, $now + 86400)]);
    $trial->unsetRelation('properties');
    $none = fn () => null;
    same([1 => ['trial']], ext($on)->sendReminders([$trial], $now, $none), 'trial ends tomorrow');
    same('Your trial ends soon', mailsTo('customer1@example.com')[0]['subject'] ?? null, 'mailed');
    ok(str_contains(end(state()['discord']['dms'])['content'] ?? '', 'Your trial ends soon'), 'and a Discord message');
    same([], ext($on)->sendReminders([$trial], $now + 3600, $none), 'once');

    $link = wizarrService(63, $on);
    ext()->createServer($link, settings(), []);
    same([], ext($on)->sendReminders([$link], $now, $none), 'a new link: not yet');
    same([63 => ['link']], ext($on)->sendReminders([$link], $now + 2 * 86400 + 60, $none), 'unused after two days');
    ok(str_contains(mailsTo('customer63@example.com')[0]['html'] ?? '', grantProp($link, 'wizarr_invite_url')), 'with the link');

    $paid = adminCustomer();
    $paid->product->server->settings = $on;
    $paid->expires_at = date('Y-m-d H:i:s', $now + 2 * 86400);
    same([], ext($on)->sendReminders([$paid], $now, $none), 'nothing unpaid: no reminder');
    same([1 => ['renewal']], ext($on)->sendReminders([$paid], $now, fn () => (object) ['id' => 9]), 'renews in two days, invoice unpaid');
    $paid->subscription_id = 'sub_123';
    $paid->properties()->where('key', 'reminded_renewal')->delete();
    $paid->unsetRelation('properties');
    same([], ext($on)->sendReminders([$paid], $now, fn () => (object) ['id' => 9]), 'paid automatically: no reminder');

    $off = adminCustomer(true, ['trial_days' => '7']);
    $off->properties()->updateOrCreate(['key' => 'trial_ends_at'], ['name' => 'x', 'value' => date(DATE_ATOM, $now + 86400)]);
    $off->unsetRelation('properties');
    same([], ext()->sendReminders([$off], $now, $none), 'switched off: nothing');
});

test('queue watch: a job sent and not run for 15 minutes means Paymenter\'s queue stands still', function () {
    \Illuminate\Support\Facades\Cache::$store = [];
    $w = \Paymenter\Extensions\Servers\Wizarr\Wizarr::class;
    same(null, $w::queueStuckSince(), 'nothing sent yet');
    \Illuminate\Support\Facades\Cache::put('paywarr:queue:sent', time() - 1200, 3600);
    same(time() - 1200, $w::queueStuckSince(), 'sent 20 minutes ago, never ran');
    \Illuminate\Support\Facades\Cache::put('paywarr:queue:ran', time() - 1100, 3600);
    same(null, $w::queueStuckSince(), 'it ran');
    \Illuminate\Support\Facades\Cache::put('paywarr:queue:sent', time() - 300, 3600);
    same(null, $w::queueStuckSince(), 'sent 5 minutes ago: give it time');
    \Illuminate\Support\Facades\Cache::put('paywarr:queue:sent', time() - 1200, 3600);
    \Illuminate\Support\Facades\Cache::put('paywarr:queue:ran', 0, 3600);
    ok(str_contains(implode(' ', array_column(ext()->adminHealth([], [], [], []), 'what')), 'queue stands still'), 'Checks says so');
});

/* ---------- loyalty: pause, come back, invite a friend ---------- */

test('pause: the customer pauses themselves, within the limits, and resumes', function () {
    \Illuminate\Support\Facades\Mail::$sent = [];
    $now = time();
    $service = adminCustomer();
    $service->expires_at = date('Y-m-d H:i:s', $now + 10 * 86400);
    $on = ['pause_per_year' => '1', 'pause_max_days' => '30'];
    same(false, in_array('button:Pause subscription', labels(ext()->getActions($service, $service->product->settings, $service->propertyArray())), true), 'off: no button');
    ok(in_array('button:Pause subscription', labels(ext($on)->getActions($service, $service->product->settings, $service->propertyArray())), true), 'on: a button');
    tellsCustomer(fn () => ext($on)->customerPause($service, 45, $now), '1 to 30 days', 'too long');
    $until = ext($on)->customerPause($service, 14, $now);
    same([$now + 14 * 86400, 'suspended'], [$until, $service->status], 'paused 14 days');
    same(date('Y-m-d H:i:s', $now + 24 * 86400), $service->expires_at, 'renewal moved 14 days');
    same('Your subscription is paused', mailsTo('customer1@example.com')[0]['subject'] ?? null, 'told, with a link to resume');
    ext($on)->customerResume($service, $now + 86400);
    same(['active', date('Y-m-d H:i:s', $now + 86400 + 10 * 86400)], [$service->status, $service->expires_at], 'resumed early: the paid time left');
    tellsCustomer(fn () => ext($on)->customerPause($service, 7, $now + 2 * 86400), 'used your pauses', 'once a year');
    same(null, ext($on)->pauseRefusal($service, $service->product->settings, $now + 366 * 86400), 'again next year');
    $trial = adminCustomer(true, ['trial_days' => '7']);
    ok(ext($on)->pauseRefusal($trial, $trial->product->settings, $now) !== null, 'not a trial');
});

test('come back: a code some days after cancelling, once a year, not while another subscription runs', function () {
    \Illuminate\Support\Facades\Mail::$sent = [];
    $now = time();
    $gone = wizarrService(70);
    $gone->status = 'cancelled';
    $gone->updated_at = new DateTimeImmutable('@' . ($now - 15 * 86400));
    $still = wizarrService(71);
    $still->status = 'cancelled';
    $still->updated_at = new DateTimeImmutable('@' . ($now - 15 * 86400));
    $other = service(72, 'customer71@example.com');
    $other->user = $still->user;
    $other->user_id = $still->user_id = $still->user->id = 71;
    $gone->user->id = $gone->user_id = 70;
    $other->product = $still->product;
    $other->status = 'active';
    $on = ['winback_days' => '14', 'winback_percent' => '50', 'winback_valid_days' => '30'];
    $gone->user->properties()->updateOrCreate(['key' => 'paywarr_mail_list'], ['name' => 'List', 'value' => '1']);
    $made = [];
    $make = function (array $coupon) use (&$made) { $made[] = $coupon; return 900 + count($made); };
    same([], ext()->sendWinbacks([$gone], $now, $make), 'off');
    same([70], ext($on)->sendWinbacks([$gone, $still, $other], $now, $make), 'only who has nothing running');
    same(['percentage', 50, 1, 1], [$made[0]['type'], $made[0]['value'], $made[0]['recurring'], $made[0]['max_uses']], '50% off the first period, once');
    ok(str_contains(mailsTo('customer70@example.com')[0]['html'] ?? '', $made[0]['code']), 'the code mailed');
    same([], ext($on)->sendWinbacks([$gone], $now + 86400, $make), 'not twice');
    $fresh = wizarrService(73);
    $fresh->status = 'cancelled';
    $fresh->user->id = $fresh->user_id = 73;
    $fresh->updated_at = new DateTimeImmutable('@' . ($now - 3 * 86400));
    same([], ext($on)->sendWinbacks([$fresh], $now, $make), 'too soon');
});

test('invite a friend: a code per customer, not for oneself, and free months once the friend has paid', function () {
    \Illuminate\Support\Facades\Mail::$sent = [];
    $now = time();
    $on = ['referral_percent' => '50', 'referral_reward_months' => '1'];
    $mette = adminCustomer();
    $mette->user->first_name = 'Mette';
    $mette->expires_at = date('Y-m-d H:i:s', $now + 5 * 86400);
    $made = [];
    $code = ext($on)->referralCode($mette->user, function (array $coupon) use (&$made) { $made[] = $coupon; return 77; });
    ok(preg_match('/^METTE-[A-Z0-9]{4}$/', $code) === 1, 'a code with their name: ' . $code);
    same(['percentage', 50, null, 1], [$made[0]['type'], $made[0]['value'], $made[0]['max_uses'], $made[0]['max_uses_per_user']], 'half off, once per friend, any number of friends');
    same($code, ext($on)->referralCode($mette->user, fn () => throw new RuntimeException('twice')), 'the same code again');
    \App\Models\Property::$rows = [['key' => 'paywarr_referral_coupon', 'value' => '77', 'model_type' => \App\Models\User::class, 'model_id' => $mette->user->id]];

    $friend = wizarrService(80);
    $friend->user->id = $friend->user_id = 80;
    $friend->coupon_id = 77;
    \App\Models\Service::$all = [$mette, $friend];
    $invoice = (object) ['id' => 5, 'items' => [(object) ['reference_type' => \App\Models\Service::class, 'reference_id' => 80]]];
    same([$mette->user->id], ext($on)->rewardReferrals($invoice), 'Mette rewarded');
    same(date('Y-m-d H:i:s', strtotime('+1 months', $now + 5 * 86400)), $mette->expires_at, 'a month added');
    same([], ext($on)->rewardReferrals($invoice), 'once per friend');
    ok(str_contains(mailsTo('customer1@example.com')[0]['subject'] ?? '', 'A friend joined'), 'Mette told');

    $self = wizarrService(81);
    $self->user_id = $mette->user->id;
    $self->coupon_id = 77;
    tellsCustomer(fn () => ext($on)->guardReferral($self), 'your own code', 'not one\'s own code');
    ext($on)->sendReferral($mette->user, 'ven@example.com', 'Ole');
    ok(str_contains(mailsTo('ven@example.com')[0]['html'] ?? '', $code), 'the friend gets the code');
    \App\Models\Service::$all = [];
    \App\Models\Property::$rows = [];
});

/* ---------- Tracearr: max streams at once, sharing ---------- */

/** A Tracearr that records what it was asked to stop. */
function fakeTracearr(): \Paymenter\Extensions\Servers\Wizarr\TracearrClient
{
    return new class('http://x', 'k') extends \Paymenter\Extensions\Servers\Wizarr\TracearrClient
    {
        public array $stopped = [];

        public function terminate(string $sessionId, string $reason): void
        {
            $this->stopped[] = [$sessionId, $reason];
        }
    };
}

test('streams: over "Max streams at once" the newest are stopped, the customer warned once a day, and it is noted', function () {
    \Illuminate\Support\Facades\Mail::$sent = [];
    $now = time();
    $service = adminCustomer(true, ['max_streams' => '2']);
    $stream = fn (string $id, int $ago, string $user = 'mette') => ['id' => $id, 'server_type' => 'jellyfin', 'username' => $user, 'media_title' => 'Film ' . $id, 'started_at' => gmdate('Y-m-d\TH:i:s\Z', $now - $ago), 'device' => 'TV ' . $id, 'product' => 'Jellyfin'];
    $tracearr = fakeTracearr();
    same([], ext()->enforceStreamLimits([$stream('a', 60), $stream('b', 30)], [$service], $now, $tracearr), 'two is fine');
    same([1 => 1], ext()->enforceStreamLimits([$stream('a', 60), $stream('b', 30), $stream('c', 5), $stream('x', 1, 'someoneelse')], [$service], $now, $tracearr), 'three: one over');
    same('c', $tracearr->stopped[0][0] ?? null, 'the newest stopped');
    ok(str_contains($tracearr->stopped[0][1], '2 stream(s) at once'), 'with a message');
    same('Too many streams at once', mailsTo('customer1@example.com')[0]['subject'] ?? null, 'the customer warned');
    ext()->enforceStreamLimits([$stream('a', 60), $stream('b', 30), $stream('d', 2)], [$service], $now + 60, $tracearr);
    same(1, count(mailsTo('customer1@example.com')), 'warned once a day');
    $log = json_decode($service->propertyArray()['streams_over'], true);
    same([2, 3, true], [count($log), $log[0]['streams'], $log[0]['stopped']], 'noted on the subscription');
    $warn = fakeTracearr();
    ext(['streams_over_limit' => 'warn'])->enforceStreamLimits([$stream('a', 60), $stream('b', 30), $stream('e', 2)], [$service], $now + 120, $warn);
    same([], $warn->stopped, '"Only warn": nothing stopped');
    same([], ext()->enforceStreamLimits([$stream('a', 1), $stream('b', 1), $stream('c', 1)], [adminCustomer()], $now, fakeTracearr()), 'no limit on the product: nothing');
});

/* ---------- Discord commands, New on the server ---------- */

test('Discord commands: signed requests only, and answers for the customer who asks', function () {
    $keys = sodium_crypto_sign_keypair();
    $public = bin2hex(sodium_crypto_sign_publickey($keys));
    $body = '{"type":1}';
    $ts = (string) time();
    $signature = bin2hex(sodium_crypto_sign_detached($ts . $body, sodium_crypto_sign_secretkey($keys)));
    same(true, \Paymenter\Extensions\Servers\Wizarr\Wizarr::discordSignatureValid($public, $signature, $ts, $body), 'signed');
    same(false, \Paymenter\Extensions\Servers\Wizarr\Wizarr::discordSignatureValid($public, $signature, $ts, '{"type":2}'), 'changed body');
    same(false, \Paymenter\Extensions\Servers\Wizarr\Wizarr::discordSignatureValid($public, $signature, (string) (time() - 3600), $body), 'old');
    same(['status', 'newlink', 'stats', 'password'], array_column(\Paymenter\Extensions\Servers\Wizarr\Wizarr::commandDefinitions(), 'name'), 'four commands');
    same('nytlink', \Paymenter\Extensions\Servers\Wizarr\Wizarr::commandDefinitions()[1]['name_localizations']['da'], 'in Danish too');

    ok(str_contains(ext()->answerCommand('status', '999'), 'Connect Discord'), 'unknown: connect first');
    $service = adminCustomer(false);
    $service->expires_at = date('Y-m-d H:i:s', time() + 9 * 86400);
    \App\Models\User::$all = [$service->user->id => $service->user];
    \App\Models\Property::$rows = [['key' => 'discord_id', 'value' => '4242', 'model_type' => \App\Models\User::class, 'model_id' => $service->user->id]];
    \App\Models\Service::$all = [$service];
    $status = ext()->answerCommand('status', '4242');
    ok(str_contains($status, 'renews') && str_contains($status, '/newlink'), 'status: renewal, and no user yet: ' . $status);
    $link = ext()->answerCommand('newlink', '4242');
    ok(str_contains($link, 'https://join.example.com/j/'), 'newlink: the pending link: ' . $link);
    ok(str_contains(ext()->answerCommand('status', '4242', 'da'), 'fornyes'), 'in Danish for a Danish Discord');
    \App\Models\Service::$all = [];
    \App\Models\Property::$rows = [];
    \App\Models\User::$all = [];
});

test('new on the server: weekly on the chosen day from 17:00, movies and shows each once, posted and mailed', function () {
    \Illuminate\Support\Facades\Mail::$sent = [];
    $sunday = strtotime('next sunday 18:00');
    $items = [['media_type' => 'movie', 'title' => 'Arrival', 'year' => 2016], ['media_type' => 'movie', 'title' => 'Arrival', 'year' => 2016],
        ['media_type' => 'show', 'title' => 'Glass Harbor', 'year' => 2024], ['media_type' => 'episode', 'title' => 'Pilot']];
    $config = ['news_day' => '7', 'news_mail' => '1', 'announce_channel_id' => '555'] + discordSettings();
    $customer = new \App\Models\User('viewer@example.com');
    $offList = new \App\Models\User('nolist@example.com');
    $customer->properties()->updateOrCreate(['key' => 'paywarr_mail_list'], ['name' => 'List', 'value' => '1']);
    same(null, ext($config)->sendNewsIfDue($sunday - 86400, $items, [$customer]), 'not the day');
    same(null, ext($config)->sendNewsIfDue($sunday - 3 * 3600, $items, [$customer]), 'before 17:00');
    same(['discord' => true, 'mails' => 1], ext($config)->sendNewsIfDue($sunday, $items, [$customer, $offList]), 'sent, only to the one on the mail list');
    ok(str_contains(mailsTo('viewer@example.com')[0]['html'] ?? '', 'Leave the mail list'), 'with a link to leave the list');
    same(null, ext($config)->sendNewsIfDue($sunday + 3600, $items, [$customer]), 'once that day');
    $html = mailsTo('viewer@example.com')[0]['html'] ?? '';
    ok(substr_count($html, 'Arrival (2016)') === 1 && str_contains($html, 'Glass Harbor (2024)') && !str_contains($html, 'Pilot'), 'each once, no single episodes');
    $post = end(state()['discord']['messages']);
    ok(str_contains($post['content'] ?? '', 'Glass Harbor') && ($post['channel'] ?? '') === '555', 'posted in the channel');
});

/* ---------- messages, status, anniversary, monthly report, clean-up, extra users ---------- */

test('messages: to everyone active, a status, or one customer; by mail and the bell; logged', function () {
    \Illuminate\Support\Facades\Mail::$sent = [];
    \App\Models\Property::$rows = [];
    $a = wizarrService(90); $a->user->id = $a->user_id = 90; $a->product->id = 5;
    $b = wizarrService(91); $b->user->id = $b->user_id = 91; $b->status = 'suspended';
    $c = wizarrService(92); $c->user->id = $c->user_id = 92; $c->status = 'cancelled';
    $all = [$a, $b, $c];
    same([90], array_keys(ext()->broadcastRecipients('all', $all)), 'all active');
    same([91], array_keys(ext()->broadcastRecipients('status:suspended', $all)), 'suspended');
    same([92], array_keys(ext()->broadcastRecipients('status:cancelled', $all)), 'cancelled with nothing running');
    same([90], array_keys(ext()->broadcastRecipients('product:5', $all)), 'by product');
    same([91], array_keys(ext()->broadcastRecipients('user:91', $all)), 'one customer');
    $result = ext()->broadcast('all', 'Black Friday', "Half price this weekend.\n\nOnly until Monday.", ['See subscriptions', 'https://pay.example.com'], ['mail'], 'admin@example.com', $all);
    same([1, 0, 1], [$result['recipients'], $result['mail'], $result['not_on_list']], 'not on the mail list: no mail');
    $a->user->properties()->updateOrCreate(['key' => 'paywarr_mail_list'], ['name' => 'List', 'value' => '1']);
    \Illuminate\Support\Facades\Mail::$sent = [];
    $result = ext()->broadcast('all', 'Black Friday', "Half price this weekend.\n\nOnly until Monday.", ['See subscriptions', 'https://pay.example.com'], ['mail'], 'admin@example.com', $all);
    same([1, 1, 0], [$result['recipients'], $result['mail'], $result['discord']], 'one mail');
    ok(str_contains(mailsTo('customer90@example.com')[0]['html'] ?? '', 'Only until Monday.'), 'the message in the mail');
    same('Black Friday', \Paymenter\Extensions\Servers\Wizarr\Wizarr::storeGet('broadcasts')[0]['subject'] ?? null, 'logged');
    \App\Models\Property::$rows = [];
});

test('status: a media server down twice in a row gets a notice by itself, ended when it is back; your own notices too', function () {
    \App\Models\Property::$rows = [];
    $now = time();
    $down = [['server_name' => 'Wafflehub Jellyfin', 'server_type' => 'jellyfin', 'status' => 'down', 'historical' => false]];
    $up = [['server_name' => 'Wafflehub Jellyfin', 'server_type' => 'jellyfin', 'status' => 'up', 'historical' => false]];
    ext()->monitorStatus($now, $down, []);
    same([], \Paymenter\Extensions\Servers\Wizarr\Wizarr::activeNotices($now), 'once down: could be a blip');
    $state = ext()->monitorStatus($now + 60, $down, [7 => ['Silo', true]]);
    $active = \Paymenter\Extensions\Servers\Wizarr\Wizarr::activeNotices($now + 60);
    same(['Wafflehub Jellyfin is down', 'outage', ['jellyfin']], [$active[0]['title'] ?? null, $active[0]['kind'] ?? null, $active[0]['types'] ?? null], 'twice: a notice for Jellyfin users');
    same([false, true], [$state['tracearr:Wafflehub Jellyfin']['up'], $state['silo:7']['up']], 'the state per server');
    ext()->monitorStatus($now + 120, $up, []);
    same([], \Paymenter\Extensions\Servers\Wizarr\Wizarr::activeNotices($now + 121), 'back up: ended');
    $notice = ext()->addNotice('Maintenance Sunday', 'From 2 to 4 at night.', 'maintenance', [], $now + 3600, $now + 7200);
    same([], array_column(\Paymenter\Extensions\Servers\Wizarr\Wizarr::activeNotices($now), 'title'), 'planned: not yet');
    same(['Maintenance Sunday'], array_column(\Paymenter\Extensions\Servers\Wizarr\Wizarr::activeNotices($now + 4000), 'title'), 'while it lasts');
    $viewer = new \App\Models\User('viewer@example.com');
    \Illuminate\Support\Facades\Auth::$user = $viewer;
    \Illuminate\Support\Facades\Cache::$store = [];
    same([], ext()->renderStatusBar(), 'the footer hook: nothing while nothing is on');
    ext()->addNotice('Something for everyone', 'Hello.', 'info', [], $now - 10, null);
    \Illuminate\Support\Facades\Cache::$store = ['wizarr:has-subscription:' . $viewer->id => false];
    same([], ext()->renderStatusBar(), 'no subscription: no bar');
    \Illuminate\Support\Facades\Cache::$store = ['wizarr:has-subscription:' . $viewer->id => true];
    $bar = ext()->renderStatusBar();
    ok(isset($bar['view']) && str_contains((string) $bar['view'], 'Something for everyone'), 'the footer hook wants ["view" => …], as Paymenter merges it');
    \Illuminate\Support\Facades\Auth::$user = null;
    ext()->endNotice($notice['id'], $now + 4100);
    same(['Something for everyone'], array_column(\Paymenter\Extensions\Servers\Wizarr\Wizarr::activeNotices($now + 4200), 'title'), 'the maintenance ended early');
    \App\Models\Property::$rows = [];
});

test('anniversary: every N months as a paying customer, the gift once; not backwards when switched on later', function () {
    \Illuminate\Support\Facades\Mail::$sent = [];
    $now = time();
    $on = ['anniversary_months' => '12', 'anniversary_reward' => 'days', 'anniversary_amount' => '7'];
    $service = wizarrService(93); $service->user->id = $service->user_id = 93;
    $service->created_at = date('Y-m-d H:i:s', strtotime('-12 months -2 days', $now));
    $service->expires_at = date('Y-m-d H:i:s', $now + 10 * 86400);
    same([93], ext($on)->sendAnniversaries([$service], $now), 'a year');
    same(date('Y-m-d H:i:s', $now + 17 * 86400), $service->expires_at, '7 days added');
    same('Thank you for a year with us', mailsTo('customer93@example.com')[0]['subject'] ?? null, 'thanked');
    same([], ext($on)->sendAnniversaries([$service], $now + 86400), 'once');
    $old = wizarrService(94); $old->user->id = $old->user_id = 94;
    $old->created_at = date('Y-m-d H:i:s', strtotime('-14 months', $now));
    same([], ext($on)->sendAnniversaries([$old], $now), 'passed two months ago: not rewarded backwards');
    $made = [];
    $coupon = wizarrService(95); $coupon->user->id = $coupon->user_id = 95;
    $coupon->created_at = date('Y-m-d H:i:s', strtotime('-6 months -1 day', $now));
    same([95], ext(['anniversary_months' => '6', 'anniversary_reward' => 'coupon', 'anniversary_amount' => '20'])->sendAnniversaries([$coupon], $now, function ($c) use (&$made) { $made[] = $c; return 1; }), 'half a year');
    same(['percentage', 20], [$made[0]['type'], $made[0]['value']], 'a 20% code');
});

test('monthly report: money, subscriptions, trials, watching and streams for a month', function () {
    $now = strtotime('2026-11-20 12:00');
    $paid = wizarrService(96); $paid->user->id = $paid->user_id = 96; $paid->created_at = '2026-11-03 10:00:00';
    $gone = wizarrService(97); $gone->user->id = $gone->user_id = 97; $gone->created_at = '2026-01-03 10:00:00'; $gone->status = 'cancelled'; $gone->updated_at = new DateTimeImmutable('2026-11-10 10:00');
    $trial = wizarrService(98); $trial->user->id = $trial->user_id = 98; $trial->created_at = '2026-11-15 10:00:00'; $trial->product->settings = settings(['trial_days' => '7']);
    $paid->properties()->updateOrCreate(['key' => 'streams_over'], ['name' => 'x', 'value' => json_encode([['at' => strtotime('2026-11-12'), 'streams' => 3, 'max' => 2, 'stopped' => true, 'which' => ['Film']]])]);
    $invoice = (object) ['currency_code' => 'DKK', 'items' => [(object) ['reference_type' => \App\Models\Service::class, 'reference_id' => 96, 'price' => 69.0, 'quantity' => 1]]];
    $plays = [['server_type' => 'jellyfin', 'user' => ['username' => 'a'], 'media_type' => 'movie', 'media_title' => 'Arrival', 'duration_ms' => 7200000, 'watched' => true, 'started_at' => '2026-11-05T20:00:00Z'],
        ['server_type' => 'jellyfin', 'user' => ['username' => 'b'], 'media_type' => 'episode', 'show_title' => 'Glass Harbor', 'media_title' => 'Ep 1', 'duration_ms' => 2700000, 'watched' => true, 'started_at' => '2026-11-06T20:00:00Z'],
        ['server_type' => 'jellyfin', 'user' => ['username' => 'b'], 'media_type' => 'movie', 'media_title' => 'Clip', 'duration_ms' => 30000, 'watched' => false, 'started_at' => '2026-11-06T21:00:00Z']];
    $r = ext()->monthlyReport('2026-11', $now, [$paid, $gone, $trial], [$invoice], $plays, []);
    same(['DKK' => 69.0], $r['money'], 'paid for subscriptions');
    same([1, 1, 1, 1], [$r['subscriptions']['new'], $r['subscriptions']['trials'], $r['subscriptions']['cancelled'], $r['subscriptions']['paying']], 'new, trial, cancelled, paying');
    same([2, 3, 2], [$r['watching']['plays'], $r['watching']['hours'], $r['watching']['viewers']], 'plays (the clip left out), hours, viewers');
    same([1, 1, 0], [$r['streams']['over'], $r['streams']['stopped'], $r['streams']['rule_breaks']], 'streams');
    same(true, $r['partial'], 'the month is not over yet');
});

test('clean-up: users without a subscription switched off, later deleted; kept ones and owned ones left alone', function () {
    \App\Models\Property::$rows = [];
    $now = time();
    $backend = ['key' => 'wizarr|x', 'ok' => true, 'server_id' => 1, 'users' => [
        ['id' => 1, 'username' => 'leftover', 'server' => 'Jellyfin', 'type' => 'jellyfin', 'disabled' => false, 'owner' => null],
        ['id' => 2, 'username' => 'mette', 'server' => 'Jellyfin', 'type' => 'jellyfin', 'disabled' => false, 'owner' => ['service' => 1]],
        ['id' => 3, 'username' => 'friend', 'server' => 'Jellyfin', 'type' => 'jellyfin', 'disabled' => false, 'owner' => null],
    ], 'invitations' => [['id' => 9, 'code' => 'OLD', 'status' => 'expired', 'expires' => date(DATE_ATOM, $now - 10 * 86400), 'owner' => null]]];
    $did = [];
    $act = function ($b, $action, $id) use (&$did) { $did[] = $action . ' ' . $id; };
    \Paymenter\Extensions\Servers\Wizarr\Wizarr::setCleanupKeep('wizarr|x|3', true);
    $on = ['cleanup_disable_days' => '14', 'cleanup_delete_days' => '30'];
    ext($on)->runCleanup([$backend], $now, $act);
    same(['invitation 9'], $did, 'day one: only the old invitation');
    ext($on)->runCleanup([$backend], $now + 15 * 86400, $act);
    same(['invitation 9', 'disable 1', 'invitation 9'], $did, 'after 14 days: switched off; the friend is kept');
    ext($on)->runCleanup([$backend], $now + 46 * 86400, $act);
    same('delete 1', $did[3] ?? null, '30 days later: deleted');
    ok(str_starts_with(\Paymenter\Extensions\Servers\Wizarr\Wizarr::cleanupLog()[0]['what'] ?? '', 'Removed expired invitation') || str_starts_with(\Paymenter\Extensions\Servers\Wizarr\Wizarr::cleanupLog()[1]['what'] ?? '', 'Deleted leftover'), 'logged');
    same([], ext()->runCleanup([$backend], $now + 100 * 86400, fn () => throw new RuntimeException('off')), 'off without the setting');
    \App\Models\Property::$rows = [];
});

test('extra users: an invitation each on every server; fewer bought, the extra user is removed; each with the stream limit', function () {
    $service = chosen('');
    $service->configs = [Wizarr::EXTRA_USERS_OPTION => '2'];
    $product = settings(['server_ids' => ['1'], 'extra_users_max' => '3', 'max_streams' => '1']);
    $data = ext()->createServer($service, $product, props($service));
    same(['Jellyfin', 'Jellyfin · user 2', 'Jellyfin · user 3'], array_column($data['invites'], 'label'), 'three invitations, labelled');
    same(3, count(state()['invitations']), 'made in Wizarr');
    same(['1', '1.2', '1.3'], ext()->grantKeys($service->propertyArray()), 'grants per user');
    $service->configs = [Wizarr::EXTRA_USERS_OPTION => '1'];
    ext()->upgradeServer($service, $product, props($service));
    same(['1', '1.2'], ext()->grantKeys($service->propertyArray()), 'one fewer: the third removed');
    same(2, count(state()['invitations']), 'its invitation withdrawn');
    $service->configs = [Wizarr::EXTRA_USERS_OPTION => '9'];
    same(3, ext()->extraUsers($product, props($service)), 'never more than the product allows');
    same(0, ext()->extraUsers(settings(), props($service)), 'none without "extra_users_max"');
});

test('extra users: each their own stream limit, or one shared', function () {
    $now = time();
    $service = wizarrService(99);
    $service->properties()->updateOrCreate(['key' => 'wizarr_users:1'], ['name' => 'x', 'value' => '5:mette:jellyfin']);
    $service->properties()->updateOrCreate(['key' => 'wizarr_users:1.2'], ['name' => 'x', 'value' => '6:ole:jellyfin']);
    $stream = fn ($id, $user, $ago) => ['id' => $id, 'server_type' => 'jellyfin', 'username' => $user, 'media_title' => 'F', 'started_at' => gmdate('Y-m-d\TH:i:s\Z', $now - $ago)];
    $streams = [$stream('a', 'mette', 60), $stream('b', 'ole', 30)];
    $service->product->settings = settings(['max_streams' => '1']);
    $own = fakeTracearr();
    same([], ext()->enforceStreamLimits($streams, [$service], $now, $own), 'one each: fine');
    $service->product->settings = settings(['max_streams' => '1', 'extra_users_share_streams' => '1']);
    $shared = fakeTracearr();
    same([99 => 1], ext()->enforceStreamLimits($streams, [$service], $now, $shared), 'shared: one too many');
    same('b', $shared->stopped[0][0] ?? null, 'the newest stopped');
});

/* ---------- free access ---------- */

test('free access: who is free, the rules, warned, then removed; watching again or exempt keeps them', function () {
    \App\Models\Property::$rows = [];
    $now = time();
    $day = 86400;
    $free = wizarrService(110); $free->user->id = $free->user_id = 110; $free->price = 0; $free->created_at = date('Y-m-d H:i:s', $now - 100 * $day);
    $free->properties()->updateOrCreate(['key' => 'wizarr_users:1'], ['name' => 'x', 'value' => '50:freja:plex']);
    $paid = wizarrService(111); $paid->user->id = $paid->user_id = 111; $paid->price = 9.0;
    $trial = wizarrService(112); $trial->price = 0; $trial->product->settings = settings(['trial_days' => '7']);
    $product = wizarrService(113); $product->price = 5.0; $product->product->settings = settings(['free_access' => '1']); $product->user->id = $product->user_id = 113;
    $backend = ['key' => 'wizarr|x', 'ok' => true, 'server_id' => 1, 'kind' => 'wizarr', 'users' => [
        ['id' => 7, 'username' => 'oldfriend', 'email' => 'old@example.com', 'server' => 'Jellyfin', 'type' => 'jellyfin', 'created' => date(DATE_ATOM, $now - 200 * $day), 'disabled' => false, 'owner' => null],
        ['id' => 8, 'username' => 'newbie', 'email' => '', 'server' => 'Jellyfin', 'type' => 'jellyfin', 'created' => date(DATE_ATOM, $now - 3 * $day), 'disabled' => false, 'owner' => null],
        ['id' => 9, 'username' => 'mette', 'email' => '', 'server' => 'Jellyfin', 'type' => 'jellyfin', 'created' => '', 'disabled' => false, 'owner' => ['service' => 1]],
    ]];
    $members = ext()->freeMembers([$backend], [$free, $paid, $trial, $product]);
    same(['service:110', 'service:113', 'user:wizarr|x|7', 'user:wizarr|x|8'], array_keys($members), 'free subscriptions (price 0, or "Free access"), and users without one; not trials, paid or owned users');

    \Paymenter\Extensions\Servers\Wizarr\Wizarr::setFreeRules(['enabled' => true, 'days' => 30, 'warn_days' => 7, 'grace_days' => 14, 'delete_days' => 30]);
    $plays = ['plex|freja' => $now - 40 * $day, 'jellyfin|oldfriend' => $now - 5 * $day];
    $rows = ext()->freeRows($members, $plays, $now);
    same(['idle', 'active', 'new'], [$rows['service:110']['status'], $rows['user:wizarr|x|7']['status'], $rows['user:wizarr|x|8']['status']], 'idle 40 days; watched 5 days ago; new');

    $did = [];
    $act = function ($action, $row) use (&$did) { $did[] = $action . ' ' . $row['name']; return true; };
    \Paymenter\Extensions\Servers\Wizarr\Wizarr::setFreeExempt('service:113', true);
    ext()->runFreeAccess($members, $plays, $now, $act);
    same(['warn customer110@example.com'], array_values(array_filter($did, fn ($d) => str_contains($d, '110'))), 'warned first');
    ok(!array_filter($did, fn ($d) => str_contains($d, '113')), 'the exempt one is left alone');
    ext()->runFreeAccess($members, $plays, $now + 3 * $day, $act);
    same(1, count(array_filter($did, fn ($d) => str_contains($d, '110'))), 'not removed before the warning days are over');
    ext()->runFreeAccess($members, $plays, $now + 8 * $day, $act);
    same('remove customer110@example.com', end($did), 'removed after them');

    $did = [];
    \Paymenter\Extensions\Servers\Wizarr\Wizarr::storePut('free_state', []);
    $members2 = ['user:wizarr|x|7' => $members['user:wizarr|x|7']];
    $old = ['jellyfin|oldfriend' => $now - 60 * $day];
    ext()->runFreeAccess($members2, $old, $now, $act);
    ext()->runFreeAccess($members2, ['jellyfin|oldfriend' => $now + $day], $now + 2 * $day, $act);
    same([], \Paymenter\Extensions\Servers\Wizarr\Wizarr::storeGet('free_state'), 'watched after the warning: back to normal');
    ext()->runFreeAccess($members2, $old, $now + 3 * $day, $act);
    ext()->runFreeAccess($members2, $old, $now + 11 * $day, $act);
    same('Switched off', substr(\Paymenter\Extensions\Servers\Wizarr\Wizarr::freeLog()[0]['what'], 0, 12), 'a Jellyfin user switched off');
    ext()->runFreeAccess($members2, $old, $now + 42 * $day, $act);
    same('delete oldfriend', end($did), 'deleted 30 days later');

    \Paymenter\Extensions\Servers\Wizarr\Wizarr::setFreeRules(['enabled' => false]);
    same([], ext()->runFreeAccess($members, $plays, $now, fn () => throw new RuntimeException('off')), 'off: nothing');
    \App\Models\Property::$rows = [];
});

test('move to paid: the filter, the plan and price, and users without an account lose the access at the deadline', function () {
    $W = \Paymenter\Extensions\Servers\Wizarr\Wizarr::class;
    $rows = [
        'service:1' => ['kind' => 'subscription', 'status' => 'active', 'product' => 'Free Plex', 'name' => 'a'],
        'service:2' => ['kind' => 'subscription', 'status' => 'idle', 'product' => 'Free Plex', 'name' => 'b'],
        'user:x|3' => ['kind' => 'user', 'status' => 'new', 'product' => 'Jellyfin', 'name' => 'c'],
        'user:x|4' => ['kind' => 'user', 'status' => 'exempt', 'product' => 'Jellyfin', 'name' => 'd'],
    ];
    same(['service:1', 'user:x|3'], array_keys($W::paidFilter($rows, [])), 'by default: those watching and new, not idle or exempt');
    same(['user:x|3'], array_keys($W::paidFilter($rows, ['kinds' => ['user']])), 'only users without a subscription');
    same(['service:1', 'service:2'], array_keys($W::paidFilter($rows, ['statuses' => ['active', 'idle', 'new'], 'products' => ['Free Plex']])), 'only those now on Free Plex');
    same([], array_keys($W::paidFilter($rows, ['statuses' => []])), 'nothing ticked: nobody');

    $plans = [
        ['id' => 1, 'type' => 'free', 'period' => 1, 'unit' => 'month', 'prices' => []],
        ['id' => 2, 'type' => 'recurring', 'period' => 1, 'unit' => 'month', 'prices' => ['DKK' => 49.0, 'EUR' => 7.0]],
        ['id' => 3, 'type' => 'recurring', 'period' => 1, 'unit' => 'year', 'prices' => ['DKK' => 490.0]],
    ];
    same([2, 49.0, 'DKK'], $W::paidPlanChoice($plans, null, 'DKK'), 'the first paid plan, in the currency');
    same([2, 7.0, 'EUR'], $W::paidPlanChoice($plans, null, 'EUR'), 'another currency it has');
    same([3, 490.0, 'DKK'], $W::paidPlanChoice($plans, ['period' => 1, 'unit' => 'year'], 'DKK'), 'the same period as now');
    same([3, 490.0, 'DKK'], $W::paidPlanChoice($plans, ['period' => 1, 'unit' => 'year'], 'EUR'), 'a currency the plan lacks: its own');
    same(null, $W::paidPlanChoice([$plans[0]], null, 'DKK'), 'no paid plan: nothing');

    \App\Models\Property::$rows = [];
    $now = time();
    $W::storePut('paid_offers', ['user:x|3' => ['until' => $now + 86400, 'at' => $now], 'user:x|9' => ['until' => $now - 1, 'at' => $now]]);
    $members = ['user:x|3' => ['kind' => 'user', 'name' => 'c', 'accounts' => [], 'since' => $now - 400 * 86400], 'user:x|4' => ['kind' => 'user', 'name' => 'd', 'accounts' => [], 'since' => null]];
    $free = ext()->freeRows($members, [], $now);
    same(['offered', $now + 86400], [$free['user:x|3']['status'], $free['user:x|3']['goes_at']], 'on the page: offered, and when the access goes');
    $did = [];
    $act = function ($action, $row) use (&$did) { $did[] = $action . ' ' . $row['name']; return true; };
    same([], ext()->runPaidOffers($members, $now, $act), 'before the deadline: nothing');
    same(['user:x|3'], array_keys($W::storeGet('paid_offers')), 'one that bought (no longer free) is forgotten');
    ext()->runPaidOffers($members, $now + 2 * 86400, $act);
    same(['remove c'], $did, 'after the deadline: removed');
    same([], $W::storeGet('paid_offers'), 'and forgotten');
    \App\Models\Property::$rows = [];
});

test('move to paid: paid, waiting or not paid; reminded once before the deadline', function () {
    $W = \Paymenter\Extensions\Servers\Wizarr\Wizarr::class;
    $now = time();
    $move = ['due' => $now + 3 * 86400, 'invoice' => 5];
    same('paid', $W::paidMoveStatus($move, 'paid', 'active', $now), 'paid');
    same('waiting', $W::paidMoveStatus($move, 'pending', 'active', $now), 'not due yet');
    same('unpaid', $W::paidMoveStatus($move, 'pending', 'active', $now + 4 * 86400), 'past the deadline');
    same('unpaid', $W::paidMoveStatus($move, 'pending', 'suspended', $now), 'suspended');
    same('cancelled', $W::paidMoveStatus($move, 'cancelled', 'active', $now), 'invoice cancelled');
    same('cancelled', $W::paidMoveStatus($move, 'pending', null, $now), 'subscription gone');

    $moves = [
        10 => ['due' => $now + 1 * 86400, 'invoice' => 1, 'reminded' => null],
        11 => ['due' => $now + 5 * 86400, 'invoice' => 2, 'reminded' => null],
        12 => ['due' => $now + 1 * 86400, 'invoice' => 3, 'reminded' => null],
        13 => ['due' => $now + 1 * 86400, 'invoice' => 4, 'reminded' => $now - 3600],
        14 => ['due' => $now - 86400, 'invoice' => 6, 'reminded' => null],
    ];
    same([10], $W::paidRemindersDue($moves, [1 => 'pending', 2 => 'pending', 3 => 'paid', 4 => 'pending', 6 => 'pending'], $now, 2),
        'within 2 days and unpaid; not too early, not paid, not twice, not past the deadline');
});

test('events: campaigns run and end; movie nights are for the right products and reminded once; challenges count; friend passes once a month', function () {
    $W = \Paymenter\Extensions\Servers\Wizarr\Wizarr::class;
    $now = time();
    $c = ['id' => 'a', 'starts' => $now - 10, 'ends' => $now + 100, 'stopped' => null];
    same(['running', 'planned', 'ended', 'ended'], [$W::campaignStatus($c, $now), $W::campaignStatus($c, $now - 20), $W::campaignStatus($c, $now + 100), $W::campaignStatus(['stopped' => $now] + $c, $now)], 'campaign: running, planned, ended, stopped');
    same('a', $W::activeCampaign($now, [$c])['id'] ?? null, 'the running one');
    same(null, $W::activeCampaign($now + 200, [$c]), 'none after');
    same('BLACKFRIDAY25', $W::campaignCode('Black Friday!', 25), 'a code from the title');
    same(['show_visitors' => true, 'show_customers' => true, 'announce_mail' => true, 'announce_channel' => true], array_intersect_key($W::campaignWith(['announce' => true]), $W::campaignPlaces([])), 'an older campaign: everywhere');
    same(['show_visitors' => false, 'show_customers' => true, 'announce_mail' => false, 'announce_channel' => true], $W::campaignPlaces(['show_customers' => '1', 'announce_channel' => true]), 'where it shows, as chosen');
    same([true, true, false, false], [$W::mailListValue('1'), $W::mailListValue('true'), $W::mailListValue('0'), $W::mailListValue(null)], 'on the mail list');
    same('JULETILBUD30', $W::campaignCode('Juletilbud', 30), 'Danish');

    $parties = ['p1' => ['id' => 'p1', 'at' => $now + 1800, 'products' => [], 'reminded' => null], 'p2' => ['id' => 'p2', 'at' => $now + 86400, 'products' => [7], 'reminded' => null],
        'p3' => ['id' => 'p3', 'at' => $now - 5 * 3600, 'products' => [], 'reminded' => $now - 9000]];
    same(['p1', 'p2'], array_keys($W::watchPartiesFor([7], $now, $parties)), 'movie nights: for everyone and for their product; not long over');
    same(['p1'], array_keys($W::watchPartiesFor([3], $now, $parties)), 'another product: only the one for everyone');
    same(['p1'], $W::partiesToRemind($parties, $now), 'reminded within the hour, once');

    $ch = ['starts' => $now - 10 * 86400, 'ends' => $now + 86400, 'metric' => 'movies'];
    $plays = [
        ['plex|anna', 'movie', 'Dune', 7200000, $now - 86400], ['plex|anna', 'movie', 'Dune', 7200000, $now - 3600], ['plex|anna', 'movie', 'Up', 5400000, $now - 7200],
        ['jellyfin|bo', 'movie', 'Heat', 60000, $now - 3600], ['jellyfin|bo', 'episode', 'Show|Pilot', 2700000, $now - 3600], ['silo|5', 'movie', 'Alien', 6000000, $now - 3600],
        ['plex|anna', 'movie', 'Old', 6000000, $now - 20 * 86400], ['plex|nobody', 'movie', 'X', 6000000, $now - 3600],
    ];
    $users = ['plex|anna' => 1, 'jellyfin|bo' => 2, 'silo|5' => 3];
    same([1 => 2.0, 3 => 1.0], $W::challengeProgress($ch, $plays, $users), 'movies: each once, after 2 minutes, in the period, customers only');
    same([2 => 1.0], $W::challengeProgress(['metric' => 'episodes'] + $ch, $plays, $users), 'episodes');
    same([1 => 5.5, 3 => 1.7, 2 => 0.8], $W::challengeProgress(['metric' => 'hours'] + $ch, $plays, $users), 'hours, every play');

    $passes = ['x' => ['by' => 4, 'created' => $now - 10 * 86400], 'y' => ['by' => 5, 'created' => $now - 40 * 86400]];
    same($now + 20 * 86400, $W::nextPassAt(4, $now, $passes), 'a pass 10 days ago: the next in 20 days');
    same(null, $W::nextPassAt(5, $now, $passes), '40 days ago: now');
    same(null, $W::nextPassAt(6, $now, $passes), 'never: now');
});

test('posters: a path for each media server, popular by how many watched, continue watching half-watched ones', function () {
    $W = \Paymenter\Extensions\Servers\Wizarr\Wizarr::class;
    same('/api/v1/images/proxy?server=s1&url=%2Flibrary%2Fmetadata%2F42%2Fthumb&width=300&height=450&fallback=poster', $W::addedPosterPath(['server_id' => 's1', 'server_type' => 'plex', 'rating_key' => '42']), 'Plex');
    same('/api/v1/images/proxy?server=s2&url=%2FItems%2Fabc%2FImages%2FPrimary&width=300&height=450&fallback=poster', $W::addedPosterPath(['server_id' => 's2', 'server_type' => 'jellyfin', 'rating_key' => 'abc']), 'Jellyfin');
    same(null, $W::addedPosterPath(['server_id' => 's2', 'server_type' => 'emby']), 'no ID: none');
    $p = fn ($user, $type, $title, $show = null) => ['user' => ['username' => $user], 'server_type' => 'plex', 'media_type' => $type, 'media_title' => $title, 'show_title' => $show, 'poster_url' => '/api/v1/images/proxy?x=' . md5($show ?? $title)];
    $popular = $W::popularTitles([$p('a', 'movie', 'Dune'), $p('b', 'episode', 'Pilot', 'Lost'), $p('c', 'episode', 'Two', 'Lost'), $p('a', 'episode', 'Three', 'Lost'), $p('a', 'movie', 'Dune')]);
    same([['Lost', 3, 'show'], ['Dune', 1, 'movie']], array_map(fn ($t) => [$t['title'], $t['count'], $t['type']], $popular), 'a show by its episodes, by people, not plays');
    $plays = [['title' => 'Dune', 'percent' => 45, 'watched' => false, 'path' => 'p1'], ['title' => 'Dune', 'percent' => 10, 'watched' => false, 'path' => 'p1'],
        ['title' => 'Up', 'percent' => 95, 'watched' => false, 'path' => 'p2'], ['title' => 'Heat', 'percent' => 1, 'watched' => false, 'path' => 'p3'],
        ['title' => 'Jaws', 'percent' => 50, 'watched' => true, 'path' => 'p4'], ['title' => 'Alien', 'percent' => 30, 'watched' => false, 'path' => 'p5']];
    same(['Dune', 'Alien'], array_column($W::continueTitles($plays), 'title'), 'the latest play per title, 3-90%, not finished');
    $items = [['key' => 'a', 'type' => 'movie', 'title' => 'Dune', 'year' => 2021], ['key' => 'b', 'type' => 'movie', 'title' => 'Dune', 'year' => 2021],
        ['key' => 'c', 'type' => 'show', 'title' => 'Lost', 'year' => 2004], ['key' => 'd', 'type' => 'movie', 'title' => 'Up', 'year' => 2009]];
    same(['a', 'c', 'd'], array_column($W::usablePosters($items, [], 10), 'key'), 'the same movie on two servers: once');
    same(['b', 'c'], array_column($W::usablePosters($items, ['a' => true], 2), 'key'), 'a poster without an image: the other server\'s copy instead');
});

/* ---------- help, checklist, switching, unpaid orders, uptime, satisfaction, CSV ---------- */

test('help: Paywarr\'s guides and questions until edited; the edit wins', function () {
    \App\Models\Property::$rows = [];
    $help = \Paymenter\Extensions\Servers\Wizarr\Wizarr::helpContent();
    ok(count($help['guides']) >= 5 && count($help['faq']) >= 5, 'built-in guides and questions');
    ok((bool) array_filter($help['guides'], fn ($g) => in_array('plex', $g['types'], true)), 'a guide for Plex');
    \Paymenter\Extensions\Servers\Wizarr\Wizarr::storePut('help', ['intro' => 'Hej', 'guides' => [['title' => 'Mit TV', 'types' => ['jellyfin'], 'text' => 'Trin 1']], 'faq' => []]);
    same(['Mit TV', [], 'Hej'], [\Paymenter\Extensions\Servers\Wizarr\Wizarr::helpContent()['guides'][0]['title'], \Paymenter\Extensions\Servers\Wizarr\Wizarr::helpContent()['faq'], \Paymenter\Extensions\Servers\Wizarr\Wizarr::helpContent()['intro']], 'your own texts');
    \App\Models\Property::$rows = [];
});

test('switching: between the product\'s servers once a month, the old user kept until the new one exists', function () {
    $now = time();
    $service = chosen('');
    $product = settings(['server_ids' => ['1', '4']]);
    $service->product->settings = $product;
    $service->properties()->updateOrCreate(['key' => 'wizarr_servers'], ['name' => 'x', 'value' => '1']);
    ext()->createServer($service, $product, props($service));
    same(['1'], ext()->grantKeys($service->propertyArray()), 'on Jellyfin only');
    ok(ext()->canSwitch($service, $product, props($service)), 'the button shows (without asking Wizarr)');
    same(['server:1', 'server:4'], array_keys(ext()->switchChoices($service)), 'Jellyfin and Emby to choose from');
    tellsCustomer(fn () => ext()->switchServer($service, 'server:1', $now), 'another media server', 'not the one it has');
    ext()->switchServer($service, 'server:4', $now);
    same(['1', '4'], ext()->grantKeys($service->propertyArray()), 'both while moving: the old user stays');
    tellsCustomer(fn () => ext()->switchServer($service, 'server:1', $now + 60), 'moving already', 'one move at a time');
    same([], ext()->finishSwitches([$service], $now + 86400), 'the new user isn\'t made yet');
    same([1], ext()->finishSwitches([$service], $now + 8 * 86400), 'a week later the move ends anyway');
    same(['4'], ext()->grantKeys($service->propertyArray()), 'only Emby now');
    ok(ext()->switchRefusal($service, $now + 9 * 86400) !== null, 'once a month');
});

test('unpaid order: reminded after the hours set, once (or twice); old orders left alone', function () {
    \Illuminate\Support\Facades\Mail::$sent = [];
    $now = time();
    $order = wizarrService(120); $order->status = 'pending'; $order->created_at = date('Y-m-d H:i:s', $now - 25 * 3600);
    $invoice = fn () => (object) ['id' => 7];
    same([], ext()->sendCartReminders([$order], $now, $invoice), 'off');
    same([120], ext(['cart_reminder_hours' => '24'])->sendCartReminders([$order], $now, $invoice), 'after 24 hours');
    same('Forgot something?', mailsTo('customer120@example.com')[0]['subject'] ?? null, 'mailed');
    same([], ext(['cart_reminder_hours' => '24'])->sendCartReminders([$order], $now + 3600, $invoice), 'once');
    same([120], ext(['cart_reminder_hours' => '24', 'cart_reminder_again_hours' => '72'])->sendCartReminders([$order], $now + 48 * 3600, $invoice), 'again at 72 hours');
    $old = wizarrService(121); $old->status = 'pending'; $old->created_at = date('Y-m-d H:i:s', $now - 9 * 86400);
    same([], ext(['cart_reminder_hours' => '24'])->sendCartReminders([$old], $now, $invoice), 'older than a week: left alone');
});

test('uptime: checks per server per day, as percent, and day bars', function () {
    \App\Models\Property::$rows = [];
    $now = strtotime('today 12:00');
    $up = [['server_name' => 'Plex', 'server_type' => 'plex', 'status' => 'up', 'historical' => false]];
    $down = [['server_name' => 'Plex', 'server_type' => 'plex', 'status' => 'down', 'historical' => false]];
    for ($i = 0; $i < 9; $i++) { ext()->monitorStatus($now + $i * 60, $up, []); }
    ext()->monitorStatus($now + 600, $down, []);
    $u = \Paymenter\Extensions\Servers\Wizarr\Wizarr::uptime(30, $now + 700)['tracearr:Plex'];
    same([90.0, 90.0], [$u['percent'], end($u['days'])], '9 of 10 checks up');
    same(30, count($u['days']), '30 days');
    ok(str_contains(\Paymenter\Extensions\Servers\Wizarr\Wizarr::uptimeBars($u['days']), '#ef4444'), 'a red day (below 95%)');
    \App\Models\Property::$rows = [];
});

test('satisfaction: asked after a month and every half year; NPS counted; why they cancelled', function () {
    \Illuminate\Support\Facades\Mail::$sent = [];
    \App\Models\Property::$rows = [];
    $now = time();
    $paying = wizarrService(130); $paying->user->id = $paying->user_id = 130; $paying->price = 9.0; $paying->created_at = date('Y-m-d H:i:s', $now - 40 * 86400);
    $fresh = wizarrService(131); $fresh->user->id = $fresh->user_id = 131; $fresh->price = 9.0; $fresh->created_at = date('Y-m-d H:i:s', $now - 5 * 86400);
    $gone = wizarrService(132); $gone->user->id = $gone->user_id = 132;
    $cancellation = (object) ['id' => 55, 'service' => $gone];
    same(['nps' => [], 'why' => []], ext()->sendSurveys([$paying], [$cancellation], $now), 'off');
    same(['nps' => [130], 'why' => [55]], ext(['survey' => '1'])->sendSurveys([$paying, $fresh], [$cancellation], $now), 'a month in; and the cancellation');
    ok(str_contains(mailsTo('customer130@example.com')[0]['html'] ?? '', 'wizarr/nps/130/10'), 'one-click scores in the mail');
    ok(str_contains(mailsTo('customer132@example.com')[0]['html'] ?? '', 'wizarr/why/55/price'), 'one-click reasons');
    same(['nps' => [], 'why' => []], ext(['survey' => '1'])->sendSurveys([$paying], [$cancellation], $now + 86400), 'not again');
    same(['nps' => [130], 'why' => []], ext(['survey' => '1'])->sendSurveys([$paying], [], $now + 183 * 86400), 'half a year later');
    $answers = [['score' => 10, 'at' => $now], ['score' => 9, 'at' => $now], ['score' => 7, 'at' => $now], ['score' => 3, 'at' => $now], ['score' => 0, 'at' => $now - 400 * 86400]];
    same(['score' => 25, 'promoters' => 2, 'passives' => 1, 'detractors' => 1, 'count' => 4], \Paymenter\Extensions\Servers\Wizarr\Wizarr::npsScore($answers, $now - 365 * 86400), 'NPS: 50% - 25%');
    \App\Models\Property::$rows = [];
});

test('CSV: a header and rows Excel opens (UTF-8, semicolons, quotes)', function () {
    require_once __DIR__ . '/../extensions/Servers/Wizarr/Admin/Csv.php';
    $text = \Paymenter\Extensions\Servers\Wizarr\Admin\Csv::text(['Name', 'Note'], [['Ølgod', 'a; b'], ['x', ['y', 'z']]]);
    same("\xEF\xBB\xBFName;Note\nØlgod;\"a; b\"\nx;\"y, z\"\n", $text, 'as Excel reads it');
});

test('admin pages: a page with a form shows it, and a searchable select without options can name its choice', function () {
    foreach (glob(__DIR__ . '/../extensions/Servers/Wizarr/Admin/Pages/*.php') as $file) {
        $code = file_get_contents($file);
        if (str_contains($code, 'public function form(Schema')) {
            ok(str_contains($code, "EmbeddedSchema::make('form')"), basename($file) . ': the form is in content()');
        }
        if (str_contains($code, 'getSearchResultsUsing(')) {
            ok(str_contains($code, 'getOptionLabelUsing('), basename($file) . ': getOptionLabelUsing next to getSearchResultsUsing');
        }
    }
});

/* ---------- summary ---------- */

echo "\n" . $results['pass'] . ' passed, ' . count($results['fail']) . " failed\n";
exit($results['fail'] ? 1 : 0);
