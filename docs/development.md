# For theme authors and developers

## For theme authors

The extension works with Paymenter's default theme. A custom theme can use
two extra things:

- **Sign-up with Discord on the register page.** Paymenter only has a hook on
  the login page. Add this to your theme's `views/auth/register.blade.php`
  (a copy of the default theme's), where the button should go:

  ```blade
  {!! hook('auth.register') !!}
  ```

- **Hide the subscriptions while the shop is closed.** When "Only Discord
  members can see the shop" is on, `wizarr.shopLocked` says whether the
  current visitor is kept out:

  ```blade
  @php($shopLocked = app()->bound('wizarr.shopLocked') && app('wizarr.shopLocked'))
  @if ($shopLocked)
      <a href="{{ route('extensions.wizarr.discord') }}">Log in / connect Discord to see the subscriptions</a>
  @else
      {{-- the product list --}}
  @endif
  ```

  The binding only exists while the gate is on, hence the `bound()` check.

- **Where "See subscriptions" lands.** The trial buttons link to the home page
  at `#subscriptions`. Give your product list `id="subscriptions"` and the
  page scrolls straight to it; without it, the visitor just lands on the home
  page.

## Security choices in the code

- **Always one-time invitations.** Wizarr's API sets `unlimited` to `true`
  unless told otherwise, which would turn one subscription into a link anyone
  can use.
- **One subscription, one seat.** If "create" runs twice, the link is reused.
  "Get a new link" only works if the old one was never used.
- **Never guesses who the user is.** The extension follows the invitation's own
  `used_by` and the users it has recorded itself, not the email address. If the
  customer already has an account, it isn't touched.
- **Doesn't trust a user ID alone.** IDs can be reused in Wizarr's database, so
  both ID and username must match.
- **Invitations are never created twice on network errors.** Everything else is
  retried up to three times on short outages.
- **Fails loudly.** If Wizarr can't be reached, the job fails instead of
  pretending the access is closed.

## Limitations

- **If a suspension fails,** the safety net fixes it within an hour once Wizarr
  answers again. Until then the user still has access.
- **Refunds made directly at PayPal or Stripe, and chargebacks,** are not seen;
  suspend those yourself (see *Refunds*).
- **The customer needs an account in Paymenter** to buy, created either with
  email and password, or with one click through "Log in with Discord".
- **Up to four media servers** per subscription get their own buttons in the
  client area.
- Wizarr returns `used_by` as `<User 12>`, although its documentation says
  username. The extension understands both.

## Tests

```bash
tests/run.sh      # requires php 8.3+, python3, curl
```

273 scenarios run the extension through the whole lifecycle against
`tests/stub_wizarr.py` (which also plays Discord, including role permissions,
Seerr, and Silo through `tests/stub_silo.py`): first payment, the Seerr add-on through purchase, suspension,
upgrade and cancellation, the shop gate, Discord roles through the whole
subscription, the "your access is ready" box, the client area, an expired link,
password reset, suspension and reopening on both Jellyfin and Plex,
cancellation, the safety net, the Discord check at checkout, outages at Wizarr
and Discord, that other users are not touched, refunds, the alerts, Jellyfin,
Emby or both with one invitation each (including switching later), Seerr
request packages, up to three Seerr (including Plex), the Requests tab and
extra requests, problem reports, Silo without Wizarr
(its edit guard, paging, its own requests, and that administrators are never
touched), and the
languages (English, Danish, and that every text in the code has a Danish
translation).

`tests/banner/run.sh` runs the box's script in a real browser (Chromium):
"being prepared" → "ready" without reloading, disappears when the user is
created, can be closed, and keeps going after failed lookups. Requires Node and
Playwright.

The tests are in the repository, not in the downloads on the Releases page;
clone the repository to run them. GitHub runs both on every push and pull request
([Actions](https://github.com/Lolles1911/paywarr/actions)). A release gets a
tag such as `v1.10.0`, pushed or made with **Draft a new release** on GitHub:
the tag must match the version in `Wizarr.php`, and the tests must pass.

Booting (routes, menu item, checkout check and the hourly run) is tested
against small stand-ins whose methods are copied from Laravel 13.40, the
version Paymenter v1.5.9 uses. If the code calls a method that doesn't exist
there, the test fails. Not covered: what the Discord page looks like, and the
Discord login itself.

After installation you can check that everything is registered:

```bash
su -s /bin/bash www-data -c "php artisan route:list --path=wizarr"   # five routes
su -s /bin/bash www-data -c "php artisan schedule:list"              # a line with wizarr-retry-pending
```

**What the tests prove, and what they don't.** The stub is written from
Wizarr's source code (v2026.10), and Paymenter is replaced by small stand-ins
in `tests/shims.php`, written from Paymenter's source code. The tests alone
don't prove it works against a real Paymenter and Wizarr; step 9 above (*Try
it*) does. Run it before you take on paying customers.

## Files

| File | Contents |
|---|---|
| `extensions/Servers/Wizarr/Wizarr.php` | The extension: settings, boot, Paymenter's hooks, small helpers |
| `extensions/Servers/Wizarr/Concerns/` | The rest, one file per topic: client area, trials, gift cards, Seerr, requests, reports, Discord, grants, admin, mails, reminders, pausing and codes (Loyalty), stream limits (Streams), Discord commands, "New on the server", messages (Broadcast), service status, anniversaries, monthly reports, clean-up, free access, moving free users to paid (MoveToPaid), campaigns, movie nights (WatchParty), challenges, friend passes (FriendPass), the My page and the Overview tab (Hub), posters and the selection for visitors (Posters), help and getting started, changing media server, unpaid orders, satisfaction, and a small lasting store (Store) |
| `extensions/Servers/Wizarr/Jobs/QueueHeartbeat.php` | The hourly test job that shows the queue moves |
| `extensions/Servers/Wizarr/WizarrClient.php` | The calls to Wizarr's API |
| `extensions/Servers/Wizarr/WizarrException.php` | Error type |
| `extensions/Servers/Wizarr/DiscordClient.php` | Membership and role calls to Discord's API |
| `extensions/Servers/Wizarr/DiscordException.php` | Error type |
| `extensions/Servers/Wizarr/ShopGate.php` | The gatekeeper in front of the shop |
| `extensions/Servers/Wizarr/SiloClient.php` | The calls to Silo, answering like the Wizarr client |
| `extensions/Servers/Wizarr/SeerrClient.php` | The calls to Seerr (import, permissions, deletion) |
| `extensions/Servers/Wizarr/TracearrClient.php` | The calls to Tracearr (streams, stopping one, rule breaks, recently added) |
| `extensions/Servers/Wizarr/SeerrException.php` | Error type |
| `extensions/Servers/Wizarr/Admin/Pages/` | The Paywarr pages in Paymenter's admin |
| `extensions/Servers/Wizarr/Admin/PaywarrInventory.php` | What those pages read from Wizarr and Silo, kept a minute |
| `tools/discord-check.php` | Checks the Discord setup step by step |
| `tools/seerr-check.php` | Checks the Seerr add-on for one customer |
| `tools/backup-paymenter.sh` | Backs up Paymenter's database, `.env` and uploads |
| `extensions/Servers/Wizarr/routes.php` | The pages under `/wizarr/discord` and the status lookup `/wizarr/status` |
| `extensions/Servers/Wizarr/resources/views/discord.blade.php` | The customer's Discord page |
| `extensions/Servers/Wizarr/resources/views/report.blade.php` | The customer's "Report a problem" page |
| `extensions/Servers/Wizarr/lang/da.json` | Danish translation of everything customers see |
| `install.sh` | Copies the extension into Paymenter and reloads it |
| `tests/` | Stubs (Wizarr, Discord, Seerr, Silo), stand-ins and scenarios |
| `.github/workflows/` | Tests on every push, releases from tags |
| `assets/` | Logo, banner and the picture GitHub shows when the project is shared |

[← Paywarr](../README.md)
