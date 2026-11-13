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
    same(['1', '2'], array_column($fields['server_ids']['options'], 'value'), 'server options');
    same(true, $fields['server_ids']['multiple'], 'multi select');
    same(['10', '11', '20'], array_column($fields['library_ids']['options'], 'value'), 'library options');
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
    same((string) $inv['id'], $props['wizarr_invitation_id'], 'invitation id stored');
    same($data['invite_url'], $props['wizarr_invite_url'], 'link stored');
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
    foreach (state()['invitations'] as $inv) {
        same([1, 2], $inv['server_ids'], 'server ids');
        same([], $inv['library_ids'], 'no libraries');
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
    same('1:mette:jellyfin', $service->propertyArray()['wizarr_users'], 'user recorded with its server type');

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
    same($url, $service->propertyArray()['wizarr_invite_url'], 'new link stored');
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
    $firstCode = $service->propertyArray()['wizarr_invite_code'];

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
    ok(!isset($service->propertyArray()['wizarr_users']), 'deleted user is not remembered');

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
    same([], $service->propertyArray(), 'nothing left on the service');
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
    ok(isset($service->propertyArray()['wizarr_invite_url']), 'link is in the client area');
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

test('an invitation for two servers removes the user from both', function () {
    $both = settings(['server_ids' => ['1', '2'], 'library_ids' => '']);
    $service = subscribed($both);
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
    $service = subscribed(settings());
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
    same(['retried' => 1, 'failed' => 0], $result, 'retried once');
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
    same(['retried' => 0, 'failed' => 1], $result, 'counted as failed');
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
    $expires = $service->propertyArray()['wizarr_invite_expires'] ?? null;
    ok($expires !== null, 'expiry stored');

    same('expired', bannerFor($service, strtotime($expires) + 1)['state'], 'after expiry');
    same('ready', bannerFor($service, strtotime($expires) - 60)['state'], 'before expiry');
});

test('banner: a link that never expires stays "ready"', function () {
    $service = service();
    ext()->createServer($service, settings(['link_lifetime' => 'never']), []);
    ok(!isset($service->propertyArray()['wizarr_invite_expires']), 'nothing stored');
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
    ok(!isset($service->propertyArray()['wizarr_invite_expires']), 'cleared');
});

test('live check: notices that the link was used, records the user and hides the banner', function () {
    $service = service();
    $data = ext()->createServer($service, settings(), []);
    same('ready', ext()->liveBannerState($service, $service->propertyArray())['state'], 'before');

    redeem($data['invite_code'], 'mette');
    same('done', ext()->liveBannerState($service, $service->propertyArray())['state'], 'after signing up');
    same('1:mette:jellyfin', $service->propertyArray()['wizarr_users'] ?? null, 'user recorded');
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

    redeem($service->propertyArray()['wizarr_invite_code'], 'mette');
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
    stub('/_test/set', ['seerr' => ['users' => [['id' => 1, 'jellyfinUserId' => 'jf-owner', 'permissions' => 2], ['id' => 7, 'jellyfinUserId' => 'jf-mette', 'permissions' => 32]], 'next_id' => 8]]);
    seerrSync($service, 'active');
    ok(!isset($service->propertyArray()['seerr_user_id']), 'not taken over');
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
    ok(isset($service->propertyArray()['wizarr_users']), 'user kept for a purchase');
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
    same('1:mette:jellyfin', $paid->propertyArray()['wizarr_users'] ?? null, 'user now on the paid subscription');
    same('99', $trial->propertyArray()['trial_converted_to'] ?? null, 'trial marked as bought');
    ok(!isset($trial->propertyArray()['wizarr_users']), 'the trial lets go of the user');

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
    redeem($service->propertyArray()['wizarr_invite_code'], 'mette');
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
    redeem($service->propertyArray()['wizarr_invite_code'], 'mette');
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
    throws(fn () => ext()->createServer(service(), settings(), []), '503', 'surfaces the failure');

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
