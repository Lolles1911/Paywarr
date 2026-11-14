# Paywarr

[![Tests](https://github.com/Lolles1911/paywarr/actions/workflows/tests.yml/badge.svg)](https://github.com/Lolles1911/paywarr/actions/workflows/tests.yml)

**Sell access to your media library with Paymenter and Wizarr.**

Paywarr is a server extension for [Paymenter](https://paymenter.org) that turns a paid
subscription into access through [Wizarr](https://github.com/wizarrrr/wizarr)
automatically, and takes the access away again when payments stop.

```
Customer buys a subscription in Paymenter ──pays via PayPal/Stripe──▶ invoice paid
        │
        ▼
Paymenter calls the extension ──POST /api/invitations──▶ Wizarr
        │                                                  │
        │◀─────────── one-time invitation /j/<code> ───────┘
        ▼
"Service activated" email with the link  +  a button in the customer's client area
```

Everything customers see (the box at the bottom of the page, buttons,
notices, the Discord page) follows the language Paymenter shows; see
[Languages](#languages).

## Who does what

Paymenter decides **when**; the extension translates that into Wizarr:

| Happens in Paymenter | The extension |
|---|---|
| First invoice paid | Creates a one-time invitation per media server (Jellyfin, Emby or both, as the customer chose); the links go into the email and the client area |
| Renewal paid on time | Nothing. Paymenter just moves the due date and the user carries on |
| Invoice overdue (default: 2 days) | Disables the user. If the link was never used, it is revoked |
| Paid while suspended | Re-enables the same user, or sends a new invitation if that isn't possible |
| Cancelled, or unpaid for too long (default: 14 days) | Deletes the user and the invitation |
| The customer let the link expire | Button in the client area: "Get a new invitation link" |
| Right after payment | Box at the bottom of every page: "Your access is ready – Create your user" (see below) |
| The customer forgot their password | Button in the client area: "Reset password" (see below) |
| Suspension/cancellation failed (Wizarr down) | Retried every hour until it goes through (see below) |
| The product requires Discord | Only members of your Discord server can buy (see below) |
| The shop is Discord-only | Only members can see categories, products and the cart (see below) |
| Subscription active / ended | The customer gets / loses a Discord role, e.g. "Paid user" or "Trial" |
| The "Seerr" add-on bought / ended | The customer gets / loses access to Seerr (requests) |
| Trial ended | The user is disabled; if the customer buys, the same user is re-enabled (see below) |
| You delete the customer in Paymenter | Access is removed everywhere first; otherwise the customer isn't deleted |
| You refund an invoice in full | The access it paid for is suspended (see below) |

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
two things; see [For theme authors](#for-theme-authors).

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
| Wizarr URL (internal) | `http://<ip>:<wizarr-port>`, the address Paymenter itself uses |
| Wizarr URL (public) | The address customers open, e.g. `https://join.yourdomain.com` |
| API key | The key from Wizarr |

Press **Test Connection**. Use IP and port as the internal address, not
container names. The Discord and Seerr fields on the same page are optional and
described further down.

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
| Seerr included | See *Seerr as a paid add-on* |
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

## Updating

What changed in each version is on the
[Releases](https://github.com/Lolles1911/paywarr/releases) page.

```bash
cd /opt/paywarr && git pull
bash /opt/paywarr/install.sh /opt/paymenter          # or your compose folder
```

On Docker, then `docker compose restart paymenter`.

The version is in `extensions/Servers/Wizarr/Wizarr.php` (`version:`).

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

## Running it for real

### Backups

`tools/backup-paymenter.sh` makes one archive with Paymenter's database, its
`.env` and the uploaded files. Keep `.env` safe: its `APP_KEY` decrypts the
API keys and passwords Paymenter stores, so a database without it is of
little use. The archive is readable by root only.

Nightly at 03:17, keeping 14 days, as root on the Paymenter machine:

```bash
install -m 700 /opt/paywarr/tools/backup-paymenter.sh /usr/local/sbin/backup-paymenter
(crontab -l 2>/dev/null; echo '17 3 * * * /usr/local/sbin/backup-paymenter /opt/paymenter /var/backups/paymenter 14 >> /var/log/backup-paymenter.log 2>&1') | crontab -
```

Run it once by hand to see that it works: `/usr/local/sbin/backup-paymenter`.
It supports MariaDB/MySQL and SQLite.

On Docker, back up the compose folder (it holds `.env`, `extensions` and
`themes`) and dump the database from its container, from the compose folder:

```bash
docker compose exec -T database sh -c 'mariadb-dump --single-transaction -u "$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' > paymenter.sql
```

- **Copy the backups off the machine.** A backup on the same disk is gone when
  the disk is. Copy `/var/backups/paymenter` to a NAS or another server, e.g.
  with `rsync` in the same crontab.
- **Back up Wizarr too.** Its database (which invitation became which user) is
  in Wizarr's data folder, `/data/database` inside its container. Include that
  folder in your container backups.
- **Restore:** unpack the archive, load `database.sql` with
  `mariadb <database> < database.sql`, and put `env` back as `.env`.

### Alerts

Paywarr can tell you on Discord when something needs you. Once an hour it
checks for:

- **Suspensions or cancellations that keep failing.** Those customers still
  have access (see *Safety net* below).
- **Wizarr not answering.**
- **Paid subscriptions with no invitation after 15 minutes.** That almost
  always means Paymenter's queue worker isn't running, so nobody gets an
  invitation until it is fixed.

A problem is posted once, again when it changes or after a day if it is still
there, and you get an "all good again" when it's gone. The messages never
mention anyone.

1. In Discord, open the settings of a private channel → **Integrations** →
   **Webhooks** → **New Webhook** → **Copy Webhook URL**.
2. Paste it into **Alerts: Discord webhook** on the Wizarr server in Paymenter.

**The hourly run itself can stop** (the scheduler's cron line is gone, or the
machine is down), and then nothing above is checked. To hear about that, use
an uptime monitor such as [Uptime Kuma](https://github.com/louislam/uptime-kuma):

1. Add a monitor of type **Push**, with a heartbeat interval of 7200 seconds,
   and copy its push URL.
2. Paste it into **Alerts: heartbeat URL**. Paywarr opens it after every hourly
   run; when it stops, Uptime Kuma warns you.

While you are in Uptime Kuma, add **HTTP** monitors for the public addresses of
Paymenter, Wizarr and Jellyfin, with a Discord notification.

### Email that arrives

Invitations go out by email, and mail from a domain without SPF and DKIM
often lands in spam. Check the address under **Admin → Settings → Mail**
(the *From* address) and set up its domain with your mail provider:

| Record | Where | What |
|---|---|---|
| SPF | `TXT` on your domain | Which servers may send for it, e.g. `v=spf1 include:<your provider> ~all`. Only one SPF record per domain: add to the existing one |
| DKIM | `TXT` or `CNAME`, name given by the provider | A signature key. Your mail provider gives you the exact record |
| DMARC | `TXT` on `_dmarc.<your domain>` | What to do with mail that fails. Start with `v=DMARC1; p=none` |

Check what is published (replace the domain):

```bash
dig +short TXT example.com            # should include v=spf1 ...
dig +short TXT _dmarc.example.com     # should start with v=DMARC1
```

Then buy a test subscription with an address at Gmail or Outlook and see that
the "Service activated" mail lands in the inbox. Sending through a personal
Gmail account works too, but the *From* address must then be that Gmail
address.

## "Your access is ready"

![The box in the bottom right corner](screenshots/banner.png)

As long as a customer has a paid subscription with an invitation that hasn't
been used yet, every page in the client area shows a small box at the bottom:

- **Right after payment:** "Your access is being prepared". Paymenter creates the invitation in the background a few seconds
  after payment. The box checks every 3 seconds and moves to the next step on
  its own, without reloading the page.
- **Ready:** "Your access is ready" with the button **Create your user**,
  which opens the invitation link directly.
- **Expired:** "Your invitation link has expired" with a button to the
  subscription, where the customer can get a new link.
- **User created:** the box disappears by itself.

The customer can close it with ✕; it comes back if the message changes (e.g.
from "ready" to "expired").

Pages don't get slower: the box is drawn from what is stored on the
subscription and asks Wizarr in the background (at most 40 times a minute per
customer). It uses the theme's colours, so it fits the default theme and
custom themes alike. If the queue gives up on creating the invitation, the box stops
waiting after 15 minutes.

## Forgotten login

The customer has two logins:

- **The Paymenter account** (where they pay): Paymenter's own "Forgot password"
  on the login page. It just needs Paymenter to be able to send email.
- **The Jellyfin/Emby user** (where they watch): on an active subscription the
  client area shows the **username** and a button **"Reset password"**. The
  button asks Wizarr for a reset link and sends the
  customer straight to Wizarr's page, where they choose a new password. The link
  works for 24 hours, and a new click invalidates the previous one.

The button only exists while the subscription is active, and only for the
subscription's own user. Plex has no button, because Wizarr can't change a Plex
password. If Wizarr can't be reached, the customer gets a short message to try
again, and the actual error is written to Paymenter's log.

## Safety net: failed suspensions

Paymenter marks a subscription as suspended or cancelled even if the call to
Wizarr failed, and doesn't try again. The customer would then keep watching
without paying.

So the extension puts a marker on the subscription while it suspends or
cancels, and removes it once that has succeeded. **Every hour** it retries the
subscriptions that still have the marker. If the customer pays in the meantime,
the attempt is dropped. Subscriptions where everything went fine are not
touched.

This runs through Paymenter's scheduler, which also has to run for Paymenter to
suspend at all. It must be in the server's crontab:

```bash
crontab -l -u www-data    # the user may have another name on your system
# expected line:  * * * * * php /opt/paymenter/artisan schedule:run
```

You can see the attempts in `storage/logs/laravel.log` ("Wizarr: finished the
suspend ..." or "... still fails, will retry").

## Discord membership at checkout

Tick **Require Discord membership** on a product, and only members of your
Discord server can buy it.

**How it works for the customer:**

1. Under **Account → Discord** the customer presses "Connect Discord" and logs
   in with Discord. Paymenter only gets the Discord name and
   ID, not the email or server list.
2. The page shows whether they are a member. If not, they get a button to join.
3. At checkout Paymenter checks the membership. If they aren't a member (or not
   connected), the purchase stops with a message and nothing is created.

**Built-in rules:**

- One Discord account can only be connected to one customer, so one member
  can't buy through several accounts.
- If Discord can't answer, the purchase waits ("try again in a moment") instead
  of slipping through. The reason is in the log.
- Also applies to free products, so a trial also requires membership.
- The check only happens at purchase. Leaving Discord afterwards doesn't stop
  the subscription.
- A service you create for a customer yourself in admin isn't blocked.

**Setup (once):**

1. Go to <https://discord.com/developers/applications> → **New Application**,
   and give it a name (e.g. the name of your shop).
2. **OAuth2**: copy the **Client ID** and **Client Secret**. Under **Redirects**
   add `https://<your-paymenter-address>/wizarr/discord/callback`.
3. **Bot**: press **Reset Token** and copy it. Turn on **Server Members Intent**
   in the same place. Discord doesn't say clearly whether the lookup needs it,
   so turn it on to be safe.
4. **OAuth2 → URL Generator**: choose the scope `bot`, no permissions. Open the
   link and add the bot to your server.
5. Find the server ID: Discord → Settings → Advanced → turn on
   **Developer Mode**. Right-click the server → **Copy Server ID**.
6. Create an invite link to the server that never expires.
7. In Paymenter: **Admin → Servers → your Wizarr server**. Fill in the five
   Discord fields and press **Test Connection**. It now also checks that the
   bot is in your server.
8. Tick **Require Discord membership** on the product.

The menu item **Account → Discord** appears once all the Discord fields are
filled in.

## Log in with Discord

When the Discord fields are filled in, the login page shows the button
**"Log in or create an account with Discord"** below the regular form. The
register page shows **"Create an account with Discord"** only with a theme that
offers the `auth.register` hook (see [For theme authors](#for-theme-authors)):
Paymenter's default theme has no place on the register page where an extension
can insert it.

| The customer | What happens |
|---|---|
| Has connected Discord before | Logged in (with two-factor if they have turned it on) |
| New, Discord email verified and not used in Paymenter | The account is created and logged in, with Discord already connected |
| The Discord email already exists in Paymenter | Refused: "log in with your password and connect Discord" |
| No verified email on Discord | Refused |
| "Disable User Registration" is on in Paymenter | Only existing connected accounts can log in |

- **Never links on email.** Otherwise whoever took over a Discord account could
  get into someone else's Paymenter account with the same email.
- New accounts get their name from the Discord display name, a random password
  ("Forgot password" sets a real one) and the email marked as verified.
  Address, phone and other custom fields aren't asked for: Paymenter doesn't
  require them at checkout, and the customer can fill them in under Account.
- If you have set a link to terms of service in Paymenter, the text below the
  button says that creating an account means accepting them.
- Nothing needs to change in Discord: login and connecting use the same
  redirect address (`/wizarr/discord/callback`).
- If you have also turned on Paymenter's own **Discord login** (Settings →
  Social Login), turn it off. It doesn't create accounts and doesn't know about
  the connection, so there would be two different Discord buttons.

## Shop for Discord members only

Tick **Only Discord members can see the shop** on the Wizarr server. Categories,
products and the cart then require the customer to be logged in, have
connected Discord, and be a member of your server:

| The customer is | Sent to |
|---|---|
| Not logged in | Login (and back afterwards) |
| Logged in, no Discord | The Discord page: "Connect your Discord account" |
| Connected, not a member | The Discord page with the button "Join our Discord" |
| A member | The shop |
| Admin | Always let in |

- The home page, login, the account and the Discord page stay open.
- Membership is remembered for 10 minutes. "I have joined – check again" on the
  Discord page checks right away.
- If Discord can't answer, the shop is closed ("try again in a moment") instead
  of opened.
- The **default theme** still shows the category names on the home page and
  in the "Shop" menu; customers just can't open them. A custom theme can hide
  them (see [For theme authors](#for-theme-authors)).

## Discord roles

Choose a role under **Discord role while active** on each product, e.g. "Paid
user" on the paid one and "Trial" on the trial product. The list is fetched
from your server.

- The role is given at first payment, taken at suspension, given again at
  payment, and taken at cancellation.
- If the customer connects Discord later, they get the role right away. If they
  remove the connection, the role is taken.
- **Roles you have given yourself are not touched.** The extension remembers
  which roles it has given and only takes those back.
- If a call to Discord fails, the hourly run fixes it.
- A role error never stops a payment or an invitation; it is written to the
  log.

If you run the trial as a coupon on the paid product, the customer gets "Paid
user" from the start. If "Trial" should be a role of its own, make the trial a
separate free product.

**The bot needs permission:** Server Settings → Roles:

1. Give the bot's role the **Manage Roles** permission.
2. Drag the bot's role **above** "Paid user" and "Trial" in the list. A bot can
   only give roles that are below its own.

If either is missing, the log says: *"The bot may not give or take that
role"*. Discord answers 50001 ("Missing Access") when the bot lacks Manage
Roles, and 50013 when its role is too low.

If you invited the bot "without permissions" as in the setup above, **Manage
Roles** must be turned on for the **bot's own role** (the one with the same
name as the bot), not on another role the bot also has.

**If the roles don't work**, run the check script. It walks through the setup
step by step, shows Discord's own replies, and runs the role update for one
customer:

```bash
su -s /bin/bash www-data -c "php /opt/paywarr/tools/discord-check.php /opt/paymenter customer@example.com"
```

## Refunds

When you refund an invoice **in full** with the **Refund** button on the
invoice in Paymenter, the subscription it paid for is suspended, just as if
it were overdue: the user is disabled and keeps their history. The customer
gets no "suspended" email, because Paymenter's template for it talks about
unpaid invoices.

- **Partial refunds change nothing**, for example a discount, or one of two
  payments when someone was charged twice.
- **Upgrade invoices** (e.g. adding Seerr later) are left alone.
- To undo it, press **Unsuspend** on the service in admin.
- Paymenter cancels the subscription later in the usual way, a number of days
  after its due date (**Admin → Settings → Cronjob**).
- To turn it off: **When an invoice is refunded in full** → **Do nothing** on the
  Wizarr server.

**Not covered:** refunds you make directly at PayPal or Stripe, and
chargebacks. Paymenter never hears about those, so neither does Paywarr. Press
**Suspend** on the service yourself.

## Deleting a customer

Paymenter's **Delete** on a user deletes the subscriptions directly in the
database without telling the extension. So the extension listens for the
deletion and removes the customer's access first: the Jellyfin/Wizarr user,
unused invitations, the Seerr account and Discord roles.

If the access can't be removed (e.g. because Wizarr is down), **the customer
isn't deleted**, and admin shows "The user was not deleted" with the reason. Try again when Wizarr answers. That way there is
never a Jellyfin user left behind without a customer in Paymenter.

## Trials

A free trial product that the extension manages from start to finish:

| Happens | The extension |
|---|---|
| The customer creates their Jellyfin user | The trial starts (the time is fetched from Wizarr) |
| During the trial | The client area shows "5 days left – ends …" and "Continue with a subscription"; the box at the bottom of the page shows the time left and "See subscriptions" (can be closed) |
| The trial ends | The user is disabled (not deleted), the trial is closed, the "Trial" role is removed |
| The customer buys a subscription, before or after the end | **The same user** is re-enabled and moved over: history and "continue watching" are kept. No new invitation |
| Not bought 30 days after the end | The user is deleted |

- The box at the bottom of the page says "Your trial has ended – See
  subscriptions" for 14 days after the end, if the customer hasn't bought.
- **New customers only:** checkout refuses a trial if the customer has had a
  trial or a subscription before (whatever its status). Together with "one
  Discord account per customer" this makes it awkward to take several trials.
- On Plex a disabled user can't be turned back on; if the customer buys, they
  get a new invitation instead.

**Setup:**

1. Create a product, e.g. "Try for free", with a **Free** plan and your Wizarr
   server.
2. Under the product's Wizarr fields: **Trial: days** = number of days (e.g. 7).
   Leave **Access expires after** empty; it is ignored on trials anyway.
3. Choose **Discord role while active** = "Trial" if you use roles, and
   preferably tick **Require Discord membership**.
4. Set **Per user limit** = 1 on the product (extra safety).
5. The paid product needs nothing new: the takeover happens by itself.

## Seerr as a paid add-on

Seerr (Overseerr/Jellyseerr combined) can be sold as an add-on. Only customers
who have bought it can log in to Seerr.

| Happens | Seerr |
|---|---|
| The add-on is bought and the customer has created their Jellyfin user | The customer is imported into Seerr with Seerr's default permissions |
| The subscription is suspended | The account is kept but loses all permissions (Seerr keeps people logged in for 30 days) |
| Paid again | The permissions come back |
| Cancelled, or the add-on deselected | The Seerr account is deleted, and with it the customer's requests in Seerr |

The import happens as soon as the customer has created their user and opens a
page in the client area, otherwise at the next full hour at the latest. Only
works with Jellyfin/Emby, not Plex.

**Customers who already had a Seerr account are not touched**, not even
deleted when the add-on ends. Clean them up once in Seerr before you start.

**Setup in Seerr:**

1. Settings → Users: turn **Enable New Jellyfin Sign-In** off. Then only
   imported users can log in.
2. Same place: set **Default Permissions** to what buyers should be able to do
   (e.g. Request).
3. Settings → Network: **CSRF Protection** must be off (that is the default).
   Otherwise Seerr rejects the extension's calls.
4. Settings → General: copy the **API Key**.

**Setup in Paymenter:**

5. Admin → Servers → your Wizarr server: fill in **Seerr URL (internal)**,
   **Seerr URL (public)** and **Seerr API key**, and press Test Connection.
6. Admin → Configurable Options → New:
   - Type: **Select** (a checkbox can't be added later in Paymenter)
   - **Upgradable**: on, so the customer can add or remove it later
   - Environment Variable: **`seerr`**
   - Products: your subscription
   - Options: **No** (env `no`, price 0) and **Yes** (env `yes`, your price per
     period). The Danish `nej`/`ja` work too. To sell request packages instead
     of a plain Yes, see *Request packages* below.
7. If a product should always include Seerr (e.g. a premium plan), tick
   **Seerr included** on the product instead.

Customers with the add-on get the button **"Request movies and shows"** in the
client area.

### More than one Seerr

A Seerr is connected to one media server (Jellyfin, Emby or Plex). To offer
Requests to customers on several, run a Seerr for each (they can all send
requests to the same Radarr and Sonarr) and fill in **Seerr 2** and **Seerr 3**
on the Wizarr server: URL (internal), URL (public) and API key, like the first.
Paywarr asks each Seerr which media server it uses.

- A customer gets **one** Seerr account: on the Seerr of their first server
  that has one, in the order Jellyfin, Emby, Plex. With "Both" (Jellyfin and
  Emby) that is the Jellyfin Seerr.
- The **Request movies and shows** button goes to that Seerr.
- If the customer changes media server so that another Seerr serves them,
  the account moves: deleted on the old Seerr (with its requests there),
  created on the new one.
- Plex users are imported once they have accepted the share in Plex; until
  then Paywarr tries again every hour. A Plex user who already has a Seerr
  account is left alone, like everyone else.

### Requests need Seerr's media server

Each Seerr can only give accounts to its own media server's users. So a
customer who picks Requests must also have a server that one of your Seerr
is connected to: with Seerr on Jellyfin, "Emby" plus Requests is
refused at checkout ("Requests need Jellyfin. Choose a media server with
Jellyfin, or remove Requests."), while "Jellyfin" or "Both" is fine. The same
check runs when a customer changes their choice later. Nothing is created or
charged when it refuses.

With several Seerr, the message names them all ("Requests need Jellyfin or
Emby"). Paywarr asks each Seerr which server it is connected to (and remembers
it for an hour). If Seerr can't be reached, the purchase goes ahead and the log says
why, so an outage never blocks sales.

### Request packages

Instead of a plain Yes, the `seerr` option can sell packages with a limit on
how much the customer may request, e.g. a cheap one and an unlimited one:

| Option | Environment variable | The customer may request |
|---|---|---|
| No | `no` | (no Seerr) |
| 5 a week | `5` | 5 movies and 5 TV seasons every 7 days |
| 10 a month | `10/30` | 10 movies and 10 TV seasons every 30 days |
| Unlimited | `unlimited` | as much as they like |
| Yes | `yes` | what Seerr's own default limits allow |

The number before `/` is the limit, the one after it the days (7 when left
out); write a single request a week as `1/7`, since `1` means Yes. Movies and
TV get the same limit, and Seerr counts each TV season as one request.

- The limit is set on the customer's own Seerr account and shown in the
  client area ("Requests: 5 movies and 5 TV seasons every 7 days").
- Changing package (upgrade/downgrade) changes the limit at once; nothing else
  about the account changes.
- The customer's own Seerr settings (language, region, watchlist sync) are
  kept.
- Seerr's default limits, for **Yes**, are under Seerr → Settings → Users.

If it doesn't work, run `tools/seerr-check.php` (see *Troubleshooting*).
Typically `http://` or the whole internal address is missing, or the "Yes"
option doesn't have the environment variable `yes`/`ja`.

## Customer statistics: Paywarr Stats

Want each customer to see their own watch statistics in the client area?
[**Paywarr Stats**](https://github.com/Lolles1911/Paywarr-stats) is a separate
extension that does that with Tracearr, using the media server users Paywarr
records.

## Languages

Customer texts follow the language Paymenter shows the visitor: the language
switch in the menu if you allow several languages (**Admin → Settings →
General → Allowed Languages**), otherwise the site's default language.

| Language | File |
|---|---|
| English | Built in (the texts in the code) |
| Danish | `extensions/Servers/Wizarr/lang/da.json` |

Any other language shows English. To add one, copy `da.json` to the language's
code, e.g. `de.json`, and translate the values on the right; leave the keys on
the left as they are. Words starting with `:` (`:count`, `:name`) are filled in
by the extension, so keep them. The test suite checks that `da.json` has every
text the code uses, which is a quick way to see what a new language is missing.

Messages for you (the log, Test Connection, the product fields in admin) are in
English.

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

## Jellyfin, Emby or both

The customer can pick the media server at checkout, and change it later.
Every server gets **its own invitation** (one invitation for several servers
does not work well in Wizarr), so with both they create two users, each from
its own link.

**Setup:**

1. Add and verify both servers in Wizarr (**Settings → Servers**), each with
   its **External URL**: the address customers open it on. It becomes the
   "Open Jellyfin" / "Open Emby" button in the client area.
2. On the product, select both under **Media server**. Choose libraries of
   both under **Libraries** if you like: each invitation only takes its own
   server's.
3. **Admin → Configurable Options → New:**
   - Type: **Select**
   - **Upgradable**: on, so the customer can change it later
   - Environment Variable: **`media_server`**
   - Products: your subscription
   - Options, e.g. **Jellyfin** (env `jellyfin`), **Emby** (env `emby`) and
     **Both** (env `jellyfin,emby`), each with its own price
4. Use the email template from step 7, which lists every link.

An option's environment variable can be server types (`jellyfin`, `emby`,
`plex`), server names or Wizarr server IDs, separated by commas; `both` or
`all` means every server on the product. Without the option, the customer
gets every server on the product.

**What the customer sees with both:**

- The email and the client area have a link per server ("Create your user on
  Emby"), and the box at the bottom of the page shows the next one still
  waiting.
- Once the users exist: an **Open …** and a **Reset password on …** button
  per server. A server without an External URL in Wizarr gets no Open button
  (on a product with only that server, *Where customers watch* is used).
- An expired link is renewed for that server only.

**Changing later** (upgrade/downgrade in the client area):

| Change | Happens |
|---|---|
| A server added | Its invitation is created and emailed |
| A server removed | The user on that server is deleted, with its history there |

Suspension, paying again and cancelling apply to every server. **Seerr** is
connected to one media server: the customer's user on that one gets the
Seerr account (see *Requests need Seerr's media server*).

**Plex** works the same way: add it in Wizarr, select it on the product, and
add an option with env `plex` (or `all` for every server on the product).
Plex shares your library with the customer's own Plex account instead of
creating a user; see *What you need to know about Plex, Jellyfin and Emby*.

**Subscriptions from before version 1.11** keep their single invitation as it
is. If such a customer adds Emby later, they get an invitation for Emby, and
their existing user is not touched.

## What you need to know about Plex, Jellyfin and Emby

The two behave differently, and that is up to Wizarr, not the extension:

- **Jellyfin and Emby** can disable a user. Suspension is a pause: same user, same
  history when payment resumes.
- **Plex** has no "disabled" state. Wizarr removes the share, and it can't be
  turned back on. If the customer pays after a suspension, the extension
  deletes the old record and **emails a new invitation**.
- **If the media server is unreachable when Wizarr needs to disable, Wizarr
  deletes the user instead.** On Jellyfin that means lost history. The customer
  gets a new invitation at the next payment, so the access repairs itself.

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

181 scenarios run the extension through the whole lifecycle against
`tests/stub_wizarr.py` (which also plays Discord, including role permissions,
and Seerr): first payment, the Seerr add-on through purchase, suspension,
upgrade and cancellation, the shop gate, Discord roles through the whole
subscription, the "your access is ready" box, the client area, an expired link,
password reset, suspension and reopening on both Jellyfin and Plex,
cancellation, the safety net, the Discord check at checkout, outages at Wizarr
and Discord, that other users are not touched, refunds, the alerts, Jellyfin,
Emby or both with one invitation each (including switching later), Seerr
request packages, up to three Seerr (including Plex), and the
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
| `extensions/Servers/Wizarr/Wizarr.php` | The extension: configuration, the hooks, the client area |
| `extensions/Servers/Wizarr/WizarrClient.php` | The calls to Wizarr's API |
| `extensions/Servers/Wizarr/WizarrException.php` | Error type |
| `extensions/Servers/Wizarr/DiscordClient.php` | Membership and role calls to Discord's API |
| `extensions/Servers/Wizarr/DiscordException.php` | Error type |
| `extensions/Servers/Wizarr/ShopGate.php` | The gatekeeper in front of the shop |
| `extensions/Servers/Wizarr/SeerrClient.php` | The calls to Seerr (import, permissions, deletion) |
| `extensions/Servers/Wizarr/SeerrException.php` | Error type |
| `tools/discord-check.php` | Checks the Discord setup step by step |
| `tools/seerr-check.php` | Checks the Seerr add-on for one customer |
| `tools/backup-paymenter.sh` | Backs up Paymenter's database, `.env` and uploads |
| `extensions/Servers/Wizarr/routes.php` | The pages under `/wizarr/discord` and the status lookup `/wizarr/status` |
| `extensions/Servers/Wizarr/resources/views/discord.blade.php` | The customer's Discord page |
| `extensions/Servers/Wizarr/lang/da.json` | Danish translation of everything customers see |
| `install.sh` | Copies the extension into Paymenter and reloads it |
| `tests/` | Stub, stand-ins and scenarios |
| `.github/workflows/` | Tests on every push, releases from tags |

## License

[MIT](LICENSE). Paymenter and Wizarr are separate projects with their own licenses.
