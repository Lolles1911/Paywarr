<?php

/**
 * Drives the extension through a subscription's whole life against
 * stub_wizarr.py. Start it with ./run.sh, which also starts the stub.
 */

declare(strict_types=1);

require __DIR__ . '/shims.php';
require __DIR__ . '/../extensions/Servers/Wizarr/WizarrException.php';
require __DIR__ . '/../extensions/Servers/Wizarr/WizarrClient.php';
require __DIR__ . '/../extensions/Servers/Wizarr/DiscordException.php';
require __DIR__ . '/../extensions/Servers/Wizarr/DiscordClient.php';
require __DIR__ . '/../extensions/Servers/Wizarr/ShopGate.php';
require __DIR__ . '/../extensions/Servers/Wizarr/SeerrException.php';
require __DIR__ . '/../extensions/Servers/Wizarr/SeerrClient.php';
require __DIR__ . '/../extensions/Servers/Wizarr/Wizarr.php';
require __DIR__ . '/../extensions/Others/PaywarrStats/TracearrException.php';
require __DIR__ . '/../extensions/Others/PaywarrStats/TracearrClient.php';
require __DIR__ . '/../extensions/Others/PaywarrStats/PaywarrStats.php';

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

function labels(array $actions): array
{
    return array_map(fn ($a) => $a['type'] . ':' . ($a['label'] ?? '') . (isset($a['text']) ? '=' . $a['text'] : ''), $actions);
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

    same(['text:Status=Waiting for you to create your user', 'button:Create your user'], labels($actions), 'actions');
    same($data['invite_url'], $actions[1]['url'], 'button target');
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

    same($data['invite_url'], $actions[1]['url'], 'stored link');
});

test('an expired link can be renewed by the customer', function () {
    $service = service();
    $old = ext()->createServer($service, settings(), []);
    stub('/_test/expire', ['code' => $old['invite_code']]);

    $actions = ext()->getActions($service, settings(), $service->propertyArray());
    same(['text:Status=Your invitation link has expired', 'button:Get a new invitation link'], labels($actions), 'actions');
    same('renewInvitation', $actions[1]['function'], 'button calls the extension');

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
    ], $routes, 'routes');
    $byName = array_column(array_map(fn ($r) => ['n' => $r->name, 'm' => $r->middleware], \Illuminate\Support\Facades\Route::$routes), 'm', 'n');
    same(['web', 'guest'], $byName['extensions.wizarr.discord.login'], 'login only for guests');
    same(['web'], $byName['extensions.wizarr.discord.callback'], 'callback serves guests and logged-in customers');
    same(['web', 'auth', 'throttle:40,1'], end(\Illuminate\Support\Facades\Route::$routes)->middleware, 'status check is throttled');
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
    same(1, count($schedule->events), 'one scheduled task');
    same('wizarr-retry-pending', $schedule->events[0]->description, 'named');
    same('0 * * * *', $schedule->events[0]->expression, 'hourly');
    same(true, $schedule->events[0]->withoutOverlapping, 'never two at once');
});

test('boot also works when the scheduler already exists', function () {
    $app = freshBoot(scheduleAlreadyResolved: true);
    ext(discordSettings())->boot();
    same(1, count($app->make(\Illuminate\Console\Scheduling\Schedule::class)->events), 'registered right away');
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
    same(['mette', null], ext()->namesFromDiscord('mette'), 'single name');
    same(['Discord', null], ext()->namesFromDiscord(''), 'never empty');
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

/* ---------- Paywarr Stats: each customer's own numbers from Tracearr ---------- */

use Paymenter\Extensions\Others\PaywarrStats\PaywarrStats;
use Paymenter\Extensions\Others\PaywarrStats\TracearrException;

function stats(array $config = []): PaywarrStats
{
    global $STUB;

    return new PaywarrStats($config + ['tracearr_url' => $STUB . '/tracearr', 'tracearr_api_key' => 'trr_pub_test']);
}

/** Tracearr identity with one account per [type, username]. */
function identity(string $id, array $accounts, array $removed = []): array
{
    return ['id' => $id, 'username' => $accounts[0][1], 'accounts' => array_map(fn ($a) => [
        'server_id' => 'srv-' . $a[0], 'server_type' => $a[0], 'server_user_id' => $id . '-' . $a[0], 'external_user_id' => 'x', 'username' => $a[1],
        'removed_at' => in_array($a[1], $removed, true) ? '2026-01-01T00:00:00Z' : null,
    ], $accounts)];
}

function windows(int $p7, int $h7, int $p30, int $h30, int $pAll, int $hAll): array
{
    $ms = fn ($hours) => $hours * 3600000;

    return ['last_7' => ['plays' => $p7, 'watch_time_ms' => $ms($h7)], 'last_30' => ['plays' => $p30, 'watch_time_ms' => $ms($h30)], 'all_time' => ['plays' => $pAll, 'watch_time_ms' => $ms($hAll)]];
}

/** Two customers in Tracearr: mette (Jellyfin and Emby identities) and ole. */
function tracearrWithCustomers(): void
{
    stub('/_test/set', ['tracearr' => [
        'identities' => [identity('u-mette', [['jellyfin', 'mette']]), identity('u-mette-emby', [['emby', 'Mette']]), identity('u-ole', [['jellyfin', 'ole']])],
        'stats' => [
            'u-mette' => ['windows' => windows(2, 3, 10, 12, 40, 50), 'top_genres' => [['genre' => 'Drama', 'plays' => 20], ['genre' => 'Comedy', 'plays' => 5]]],
            'u-mette-emby' => ['windows' => windows(1, 1, 2, 2, 3, 4), 'top_genres' => [['genre' => 'Comedy', 'plays' => 30]]],
            'u-ole' => ['windows' => windows(99, 99, 99, 99, 99, 99), 'top_genres' => [['genre' => 'Horror', 'plays' => 99]]],
        ],
        'history' => [
            'u-mette' => [['media_title' => 'Pilot', 'show_title' => 'The Show', 'season_number' => 1, 'episode_number' => 2, 'media_type' => 'episode', 'server_name' => 'Jellyfin', 'started_at' => '2026-11-10T20:00:00Z', 'percent_complete' => 100,
                'poster_url' => '/api/v1/images/proxy?server=s1&url=%2FItems%2Fshow%2FImages%2FPrimary&width=300&height=450&fallback=poster']],
            'u-mette-emby' => [['media_title' => 'A Film', 'show_title' => null, 'year' => 2024, 'media_type' => 'movie', 'server_name' => 'Emby', 'started_at' => '2026-11-12T20:00:00Z', 'percent_complete' => 55.4,
                'poster_url' => 'http://evil.example.com/steal']],
            'u-ole' => [['media_title' => 'Ole\'s secret', 'media_type' => 'movie', 'server_name' => 'Jellyfin', 'started_at' => '2026-11-13T20:00:00Z']],
        ],
    ]]);
}

test('stats: the customer\'s media users are read from Paywarr, old and new style', function () {
    same([
        ['type' => 'jellyfin', 'username' => 'mette'],
        ['type' => 'emby', 'username' => 'Mette'],
        ['type' => '', 'username' => 'old'],
    ], stats()->mediaAccounts([
        ['wizarr_users:1' => '1:mette:jellyfin', 'wizarr_users:4' => '2:Mette:emby', 'seerr_user_id' => '5'],
        ['wizarr_users' => '3:old', 'wizarr_users:1' => '1:mette:jellyfin'],
    ]), 'parsed, duplicates once');
});

test('stats: only identities with the customer\'s own accounts are matched', function () {
    $identities = [identity('a', [['jellyfin', 'Mette']]), identity('b', [['emby', 'mette']]), identity('c', [['jellyfin', 'ole']]), identity('d', [['jellyfin', 'mette2']]), identity('e', [['plex', 'gone']], ['gone'])];
    same(['a'], stats()->matchIdentities([['type' => 'jellyfin', 'username' => 'mette']], $identities), 'same type and username, any case');
    same(['a', 'b'], stats()->matchIdentities([['type' => '', 'username' => 'mette']], $identities), 'no type recorded: any server');
    same([], stats()->matchIdentities([['type' => 'plex', 'username' => 'gone']], $identities), 'a removed account is not theirs any more');
});

test('stats: numbers from all the customer\'s identities, and never anyone else\'s', function () {
    tracearrWithCustomers();
    $stats = stats()->statsFor([['type' => 'jellyfin', 'username' => 'mette'], ['type' => 'emby', 'username' => 'mette']]);
    same('ok', $stats['state'], 'found');
    same(['plays' => 3, 'watch_time_ms' => 4 * 3600000], $stats['windows']['last_7'], 'last 7 days summed');
    same(['Comedy' => 35, 'Drama' => 20], $stats['genres'], 'genres summed, most first');
    same(['A Film', 'Pilot'], array_column($stats['history'], 'title'), 'history merged, newest first');
    $asked = array_column(array_filter(state()['requests'], fn ($r) => str_starts_with($r['path'], 'tracearr:/users/')), 'path');
    ok(!array_filter($asked, fn ($p) => str_contains($p, 'u-ole')), 'Tracearr is never asked about another customer: ' . json_encode($asked));
});

test('stats: no media user yet, or nothing in Tracearr yet', function () {
    tracearrWithCustomers();
    same(['state' => 'no_access'], stats()->statsFor([]), 'no user');
    same(['state' => 'no_data'], stats()->statsFor([['type' => 'jellyfin', 'username' => 'newbie']]), 'not in Tracearr');
});

test('stats: Tracearr\'s users are read page by page', function () {
    $many = [];
    for ($i = 0; $i < 230; $i++) {
        $many[] = identity("u$i", [['jellyfin', "user$i"]]);
    }
    stub('/_test/set', ['tracearr' => ['identities' => $many]]);
    same(['u229'], stats()->matchIdentities([['type' => 'jellyfin', 'username' => 'user229']], (new \Paymenter\Extensions\Others\PaywarrStats\TracearrClient($GLOBALS['STUB'] . '/tracearr', 'trr_pub_test'))->users()), 'found on the third page');
});

test('stats: a wrong key or Tracearr down is reported plainly', function () {
    try {
        stats(['tracearr_api_key' => 'trr_pub_wrong'])->statsFor([['type' => 'jellyfin', 'username' => 'mette']]);
        $message = null;
    } catch (TracearrException $e) {
        $message = $e->getMessage();
    }
    ok(str_contains((string) $message, 'rejected the API key'), 'wrong key: ' . $message);
    try {
        stats(['tracearr_url' => 'http://127.0.0.1:1'])->statsFor([['type' => 'jellyfin', 'username' => 'mette']]);
        $message = null;
    } catch (TracearrException $e) {
        $message = $e->getMessage();
    }
    ok(str_contains((string) $message, 'Could not reach Tracearr'), 'down: ' . $message);
});

test('stats: the page shows the numbers, escapes what it prints, and speaks Danish', function () {
    tracearrWithCustomers();
    $html = stats()->pageHtml(stats()->statsFor([['type' => 'jellyfin', 'username' => 'mette'], ['type' => 'emby', 'username' => 'mette']]));
    foreach (['My stats', 'Last 7 days', '4 h 0 min', '3 plays', '54 h 0 min', 'Comedy', 'The Show S01E02 – Pilot', 'A Film (2024)', 'Emby · Nov 12, 2026', '55%'] as $needle) {
        ok(str_contains($html, $needle), "shows \"$needle\"");
    }
    same('Min statistik', inLocale('da', fn () => PaywarrStats::t('My stats')), 'Danish');
    ok(str_contains(inLocale('da', fn () => stats()->pageHtml(['state' => 'no_data'])), 'Intet set endnu'), 'Danish message');
    $evil = stats()->pageHtml(['state' => 'ok', 'windows' => windows(0, 0, 0, 0, 0, 0), 'genres' => ['<b>x</b>' => 1], 'history' => [['title' => '<script>', 'show' => '', 'season' => null, 'episode' => null, 'year' => null, 'type' => 'movie', 'server' => '', 'started_at' => '', 'percent' => null]]]);
    ok(!str_contains($evil, '<script>') && !str_contains($evil, '<b>x</b>'), 'escaped');
    ok(str_contains(stats()->pageHtml(null), 'be loaded right now'), 'Tracearr down: a message, not an error page');
});

test('stats: posters come through Paymenter, only from Tracearr\'s image proxy and only the customer\'s own', function () {
    tracearrWithCustomers();
    $stats = stats()->statsFor([['type' => 'jellyfin', 'username' => 'mette'], ['type' => 'emby', 'username' => 'mette']]);
    same(['', '/api/v1/images/proxy?server=s1&url=%2FItems%2Fshow%2FImages%2FPrimary&width=300&height=450&fallback=poster'], array_column($stats['history'], 'poster'), 'only proxy paths are kept');

    $image = stats()->posterFor($stats, 2);
    same('image/jpeg', $image['type'] ?? null, 'the image, as an image');
    ok(str_contains($image['body'] ?? '', '/Items/show/Images/Primary'), 'from Tracearr\'s proxy');
    same([null, null, null], [stats()->posterFor($stats, 1), stats()->posterFor($stats, 0), stats()->posterFor($stats, 99)], 'no poster, number 0, a number past the list');

    $html = stats()->pageHtml($stats, '/stats/poster/');
    ok(str_contains($html, 'src="/stats/poster/2"') && !str_contains($html, 'src="/stats/poster/1"'), 'an <img> only where there is a poster');
    ok(!str_contains($html, 'evil.example.com') && !str_contains($html, 'images/proxy'), 'no outside or Tracearr address in the page');
});

test('stats: the poster fetch refuses anything but an image from the proxy', function () {
    global $STUB;
    $client = new \Paymenter\Extensions\Others\PaywarrStats\TracearrClient($STUB . '/tracearr', 'trr_pub_test');
    stub('/_test/clear-requests');
    same(null, $client->poster('/api/v2/public/users'), 'another Tracearr path');
    same(null, $client->poster('http://evil.example.com/x'), 'another host');
    same([], state()['requests'], 'neither was even asked for');
    same(null, $client->poster('/api/v1/images/proxy?url=notimage'), 'not an image (e.g. a login page)');
    same(null, $client->poster('/api/v1/images/proxy?url=svg'), 'SVG, which can carry scripts');
    same('image/jpeg', $client->poster('/api/v1/images/proxy?url=ok')['type'] ?? null, 'a real image');
});

test('stats: boot adds "My stats" to the client area menu', function () {
    freshBoot(scheduleAlreadyResolved: false);
    stats()->boot();
    $item = null;
    foreach (\Illuminate\Support\Facades\Event::$listeners as [$event, $listener]) {
        if ($event === 'navigation.dashboard') {
            $item = $listener();
        }
    }
    same(['My stats', 'extensions.paywarr-stats', 'ri-bar-chart-2'], [$item['name'] ?? null, $item['route'] ?? null, $item['icon'] ?? null], 'menu item');
    $routes = \Illuminate\Support\Facades\Route::$routes;
    same(['GET stats extensions.paywarr-stats', 'GET stats/poster/{number} extensions.paywarr-stats.poster'], array_map(fn ($r) => $r->method . ' ' . $r->uri . ' ' . $r->name, $routes), 'routes');
    same(['web', 'auth'], $routes[0]->middleware, 'logged-in customers only');
    same([['web', 'auth', 'throttle:120,1'], ['number' => '[0-9]+']], [$routes[1]->middleware, $routes[1]->wheres], 'posters: logged in, throttled, by number only');
});

test('stats: every text has a Danish translation', function () {
    $code = file_get_contents(__DIR__ . '/../extensions/Others/PaywarrStats/PaywarrStats.php');
    preg_match_all('/self::t\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/', $code, $m);
    $used = array_unique(array_map(fn ($t) => strtr($t, ['\\\\' => '\\', "\\'" => "'"]), $m[1]));
    $danish = json_decode(file_get_contents(__DIR__ . '/../extensions/Others/PaywarrStats/lang/da.json'), true);
    same([], array_values(array_diff($used, array_keys($danish))), 'missing in da.json');
    same([], array_values(array_diff(array_keys($danish), $used)), 'not used');
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
        'text:Jellyfin=Waiting for you to create your user', 'button:Create your user on Jellyfin',
        'text:Emby=Waiting for you to create your user', 'button:Create your user on Emby',
    ], labels(ext()->getActions($service, mediaSettings(), props($service))), 'both waiting');

    redeem($data['invites'][0]['code'], 'mette');
    same([
        'text:Status=Active', 'text:Emby=Waiting for you to create your user', 'button:Create your user on Emby',
        'text:Username=mette', 'button:Reset password on Jellyfin', 'button:Open Jellyfin',
    ], labels(ext()->getActions($service, mediaSettings(), props($service))), 'Jellyfin done, Emby waiting');

    redeem($data['invites'][1]['code'], 'mette');
    $actions = ext()->getActions($service, mediaSettings(), props($service));
    same([
        'text:Status=Active', 'text:Username=mette', 'button:Reset password on Jellyfin', 'button:Reset password on Emby', 'button:Open Jellyfin', 'button:Open Emby',
    ], labels($actions), 'both done');
    same(['https://jellyfin.example.com', 'https://emby.example.com'], array_column(array_filter($actions, fn ($a) => str_starts_with($a['label'] ?? '', 'Open ')), 'url'), 'each to its own server');
    same(['resetPassword1', 'resetPassword2'], array_values(array_filter(array_column($actions, 'function'))), 'numbered buttons');

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
    foreach (['Wizarr.php', 'ShopGate.php', 'resources/views/discord.blade.php'] as $file) {
        $code .= file_get_contents($dir . '/' . $file);
    }
    preg_match_all('/(?:self::t|Wizarr::t|\$t)\(\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")/', $code, $m);
    // PHP's own rules: in '...' only \\ and \' are escapes.
    $unquote = fn ($literal) => $literal[0] === "'"
        ? strtr(substr($literal, 1, -1), ['\\\\' => '\\', "\\'" => "'"])
        : stripcslashes(substr($literal, 1, -1));
    $used = array_unique(array_map($unquote, $m[1]));
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

/* ---------- summary ---------- */

echo "\n" . $results['pass'] . ' passed, ' . count($results['fail']) . " failed\n";
exit($results['fail'] ? 1 : 0);
