# Setting up Paywarr

From an empty Paymenter to the first customer, and what to do when something doesn't work.

## Installation

The order matters: Paymenter must be up and have run once before the extension
is added. Paymenter only copies its built-in extensions (Stripe, PayPal …) into
the `extensions` folder while it is empty.

### 1. Paymenter

Install Paymenter with its own guide under *Installation* at
<https://paymenter.org/docs>, on a server, in a container (LXC) or with Docker.
Paywarr works with all of them.

**Regular install or LXC** (Paymenter in e.g. `/opt/paymenter`): check two
things afterwards that otherwise cause `500` errors:

```bash
crontab -u www-data -l                    # should show: * * * * * php /opt/paymenter/artisan schedule:run ...
ps -eo user,cmd | grep "queue:work"       # should run as www-data
```

The scheduler (`schedule:run`) must run as the **web server's user** (usually
`www-data`), not as root. If it is in root's crontab, move it:

```bash
(crontab -u www-data -l 2>/dev/null; echo '* * * * * php /opt/paymenter/artisan schedule:run >> /dev/null 2>&1') | crontab -u www-data -
crontab -l | grep -v 'artisan schedule:run' | crontab -
chown -R www-data:www-data /opt/paymenter/storage /opt/paymenter/bootstrap/cache
```

**Docker:** use Paymenter's own `docker-compose.yml`. It keeps the extensions
in an `extensions` folder next to the compose file, and that is the folder
Paywarr goes into.

### 2. Get the repo

```bash
git clone https://github.com/Lolles1911/paywarr.git /opt/paywarr
```

### 3. Install the extension

Give the script the folder that holds Paymenter's `extensions` folder:

```bash
bash /opt/paywarr/install.sh /opt/paymenter          # regular install or LXC
bash /opt/paywarr/install.sh /path/to/compose-folder  # Docker
```

The script copies the extension to `<folder>/extensions/Servers/Wizarr/` and
replaces any earlier version.

- **Regular install or LXC:** it also reloads Paymenter (clears the caches and
  restarts the queue workers) as the owner of `storage/`, usually `www-data`.
- **Docker:** restart Paymenter afterwards, from the compose folder:
  `docker compose restart paymenter`.

Never run `php artisan` as root: the log and cache then end up owned by root,
and the web server can't write to them.

Check that Paymenter has found it (on Docker, replace `su -s /bin/bash www-data -c`
with `docker compose exec -u nginx paymenter`, the web server's user in
Paymenter's image):

```bash
su -s /bin/bash www-data -c "php artisan route:list --path=wizarr"   # the routes under wizarr/
su -s /bin/bash www-data -c "php artisan schedule:list"              # a line with wizarr-retry-pending
```

### 4. Theme (optional)

The extension works with Paymenter's default theme. A custom theme can add
two things; see [For theme authors](development.md#for-theme-authors).

### 5. Reverse proxy

Customers must be able to reach Paymenter over HTTPS. Add the proxy's IP under
**Admin → Settings → Security** (trusted proxies); otherwise Paymenter builds
wrong links. `APP_URL` must be the public `https://` address.

### 6. Connect Paymenter to Wizarr

Create a key in Wizarr: **Settings → API Keys**. Wizarr must have a
**verified** media server (Settings → Servers → test and save).

In Paymenter: **Admin → Servers → New server**, extension **Wizarr**:

| Field | Value |
|---|---|
| Connects to | **Wizarr** (for Silo, see [Silo](media-servers.md#silo-without-wizarr)) |
| Wizarr or Silo URL (internal) | `http://<ip>:<wizarr-port>`, the address Paymenter itself uses |
| Wizarr or Silo URL (public) | The address customers open, e.g. `https://join.yourdomain.com` |
| API key | The key from Wizarr |

Press **Test Connection**. Use IP and port as the internal address, not
container names. That's all the server needs: everything else (Discord,
Seerr, Tracearr, Sonarr/Radarr, reminders, events, clean-up) is for the whole
shop and lives under **Admin → Paywarr: Operations → Settings**, in tabs. Those
settings are optional and described further down. (Before 1.37 they were on
the server; updating moves them there by itself.)

### 7. Create the product

**Admin → Products → New product**

1. Create a price plan of type **Recurring**, e.g. 1 month. (A "Free" or
   "One-time" plan never renews, and the customer gets no cancel button.)
2. Select your Wizarr server under **Server**. The extension's fields then
   appear:

| Field | What |
|---|---|
| Media server | Which server(s) (Plex/Jellyfin/Emby) the product gives access to. Each gets its own invitation. To let the customer choose, see *Jellyfin, Emby or both* |
| Libraries | Empty = the server's default |
| Invitation link is valid for | How long the customer has to create their user |
| Access expires after (days) | **Leave empty for subscriptions.** Only for one-time-paid products |
| Where customers watch | Optional. Becomes a button in the client area. With several media servers, each server's *External URL* in Wizarr is used instead (see *Jellyfin, Emby or both*) |
| Trial: days | Only on a trial product; see *Trials* |
| Requests included | See *Seerr as a paid add-on*, and for Silo *Silo, without Wizarr* |
| Discord role while active | See *Discord roles* |
| Require Discord membership | See *Discord membership at checkout* |

3. Put the link in the email. The product's **Email template** field is merged
   into Paymenter's "Service activated" email. Write it in your customers'
   language:

```blade
@foreach(($invites ?? []) as $invite)
Create your user{{ $invite['server'] !== '' ? ' on ' . $invite['server'] : '' }}: [{{ $invite['url'] }}]({{ $invite['url'] }})

@endforeach
@if(!empty($invite_valid_for))
Each link works for {{ $invite_valid_for }} and can only be used once.
@endif
```

   `$invites` has one link per media server. `$invite_url` is the first one,
   which is all a product with one server needs: a template written for an
   older version keeps working there, but only shows one link when there are
   two.

   `$invite_valid_for` ("7 days") is in the site's default language
   (**Admin → Settings → General → Default Language**), because the email is sent
   from the queue, outside the customer's visit.

Without the template the customer still gets the link, in the box at the bottom
of the page and on the subscription in the client area, but not in the email.
Email requires SMTP under **Admin → Settings → Mail**.

### 8. Payment

Enable a gateway under **Admin → Gateways**.

- **PayPal:** Client ID and Secret from <https://developer.paypal.com/dashboard>
  (*Apps & Credentials*), a webhook to
  `https://<your-paymenter-address>/extensions/paypal/webhook` with the events
  `PAYMENT.CAPTURE.COMPLETED`, `PAYMENT.SALE.COMPLETED`,
  `BILLING.SUBSCRIPTION.CREATED` and `VAULT.PAYMENT-TOKEN.DELETED`, and its
  Webhook ID. For testing: the **Sandbox** toggle on the developer site and
  **Test Mode** in Paymenter; pay with the sandbox "Personal" account or a card
  from the *Credit Card Generator*. Remember to switch to Live keys and turn
  Test Mode off before going live.
- **Stripe:** <https://paymenter.org/docs/extensions/stripe>. Paymenter sets up
  the webhook itself.

### 9. Try it

1. Buy the product as a regular customer (a private window).
2. The box at the bottom changes from "Your access is being prepared" to
   "Your access is ready". Create the user with the button.
3. Under **Admin → Services** you can press Suspend / Unsuspend / Terminate and
   watch the user get disabled, re-enabled and deleted.

How long it takes from due date to suspension and deletion is set under
**Admin → Settings → Cronjob** (default 2 and 14 days).

## Troubleshooting

| Not working | Run |
|---|---|
| Anything Discord (login, membership, roles) | `su -s /bin/bash www-data -c "php /opt/paywarr/tools/discord-check.php /opt/paymenter customer@example.com"` |
| The Seerr add-on | `su -s /bin/bash www-data -c "php /opt/paywarr/tools/seerr-check.php /opt/paymenter customer@example.com"` |
| Everything else | `grep -h "Wizarr" /opt/paymenter/storage/logs/laravel-$(date +%F).log \| tail -20` |

On Docker, copy the scripts into the container and run them there (Paymenter
is in `/app` inside it), from the compose folder:

```bash
docker compose cp /opt/paywarr/tools paymenter:/tmp/paywarr-tools
docker compose exec -u nginx paymenter php /tmp/paywarr-tools/discord-check.php /app customer@example.com
```

Both scripts walk through the setup step by step with `OK`/`FAIL` and
never print keys or tokens. Typical findings: a digit missing from the Discord
server ID, the bot lacks "Manage Roles", or the option value doesn't have the
environment variable `ja`/`yes`.

[← Paywarr](../README.md)
