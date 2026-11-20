# Running it for real

Backups, alerts, mail that arrives, and the safety net for suspensions that fail.

## Backups

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

## Alerts

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

## Email that arrives

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

[← Paywarr](../README.md)
