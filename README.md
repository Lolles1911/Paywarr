<p align="center"><img src="assets/banner.png" alt="Paywarr" width="760"></p>

# Paywarr

[![Tests](https://github.com/Lolles1911/paywarr/actions/workflows/tests.yml/badge.svg)](https://github.com/Lolles1911/paywarr/actions/workflows/tests.yml)

**Subscriptions and access management for your media server, with Paymenter and Wizarr (or Silo).**

Paywarr is a server extension for [Paymenter](https://paymenter.org) that turns a
subscription into access through [Wizarr](https://github.com/wizarrrr/wizarr)
automatically, and takes the access away again when it ends.

**What it is for:** sharing the running costs of a server with family and
friends, a club or community server, or content you own or have the rights
to share (your own videos and productions, licensed material). Paywarr only
automates who has access and for how long; it gives no right to charge for
content you don't have the rights to. Check your media server's terms as
well: Plex, for example, does not allow charging others for access. What you
offer, and to whom, is your responsibility.

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
[Languages](docs/customers.md#languages).

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
| Right after payment | Box at the bottom of every page: "Your access is ready – Create your user" ([more](docs/customers.md#your-access-is-ready)) |
| The customer forgot their password | Button in the client area: "Reset password" ([more](docs/customers.md#forgotten-login)) |
| Suspension/cancellation failed (Wizarr down) | Retried every hour until it goes through ([more](docs/running.md#safety-net-failed-suspensions)) |
| The product requires Discord | Only members of your Discord server can buy ([more](docs/discord.md)) |
| The shop is Discord-only | Only members can see categories, products and the cart ([more](docs/discord.md#shop-for-discord-members-only)) |
| Subscription active / ended | The customer gets / loses a Discord role, e.g. "Paid user" or "Trial" |
| The "Seerr" add-on bought / ended | The customer gets / loses access to Seerr (requests) |
| Trial ended | The user is disabled; if the customer buys, the same user is re-enabled ([more](docs/subscriptions.md#trials)) |
| You delete the customer in Paymenter | Access is removed everywhere first; otherwise the customer isn't deleted |
| You refund an invoice in full | The access it paid for is suspended ([more](docs/subscriptions.md#refunds)) |
| A customer leaves your Discord | A warning, then the access is paused until they come back ([more](docs/discord.md#customers-who-leave-the-discord)) |

## What it can do

- **Access that follows the subscription:** an invitation per media server
  (Jellyfin, Emby, Plex through Wizarr, or Silo), paused when unpaid, removed
  when it ends. → [Setting up](docs/setup.md), [Media servers](docs/media-servers.md)
- **For customers:** a box on every page until the user is made, a welcome mail
  with apps and your Discord, reminders, "Problems? Get a new link", password
  reset, pausing instead of cancelling, extra users for family, a code to invite
  friends (free months for them), a come-back code after cancelling, an
  anniversary thank-you, service status with a bar when a server is down, and
  Requests, "Report a problem" and Open in the top bar. → [What customers see](docs/customers.md)
- **Trials, gift cards and refunds:** a trial per server that becomes a
  subscription with the same user, gift codes a customer can send a friend.
  → [Trials, gift cards and refunds](docs/subscriptions.md)
- **Discord:** members only, roles that follow the subscription, logging in
  with Discord, leavers paused, commands for customers (/status, /newlink,
  /stats, /password), and "New on the server" every week. → [Discord](docs/discord.md)
- **Requests:** Seerr or Silo, request packages and top-ups, a Requests tab,
  and statistics with Paywarr Stats. → [Requests, reports and statistics](docs/requests.md)
- **Admin:** a Paywarr group in Paymenter's admin with customers (and
  everything you can do for them), server status, media server users,
  invitations, libraries and checks; messages to customers, service status,
  monthly reports and a clean-up of users without a subscription; with
  Tracearr, a limit on streams at once and a list of sharing. → [Admin](docs/admin.md)

## Installation

You need Paymenter (1.5 or newer), Wizarr (or Silo) with your media server,
and PHP 8.3. In short:

```bash
git clone https://github.com/Lolles1911/paywarr.git /opt/paywarr
bash /opt/paywarr/install.sh /opt/paymenter          # or your compose folder
```

Then, in Paymenter: **Admin → Servers → New** with the extension **Wizarr**,
and a product on that server. Every step, from an empty Paymenter to the first
customer, is in **[Setting up Paywarr](docs/setup.md)**.

## Updating

What changed in each version is on the
[Releases](https://github.com/Lolles1911/paywarr/releases) page.

```bash
cd /opt/paywarr && git pull
bash /opt/paywarr/install.sh /opt/paymenter          # or your compose folder
```

On Docker, then `docker compose restart paymenter`.

The version is in `extensions/Servers/Wizarr/Wizarr.php` (`version:`).

## Documentation

| | |
|---|---|
| [Setting up Paywarr](docs/setup.md) | Installation step by step, and troubleshooting |
| [Running it for real](docs/running.md) | Backups, alerts, mail that arrives, the safety net |
| [What customers see](docs/customers.md) | The access box, welcome mail, reminders, password reset, top bar, languages |
| [Admin: the Paywarr pages](docs/admin.md) | Customers, server status, users, invitations, libraries, checks |
| [Discord](docs/discord.md) | Members only, leavers, log in with Discord, members-only shop, roles |
| [Trials, gift cards and refunds](docs/subscriptions.md) | Trials, gift cards, refunds, deleting a customer |
| [Requests, reports and statistics](docs/requests.md) | Seerr, request packages, the Requests tab, "Report a problem", Paywarr Stats |
| [Media servers](docs/media-servers.md) | Jellyfin, Emby or both, Silo without Wizarr, Plex |
| [For theme authors and developers](docs/development.md) | Themes, security choices, limitations, tests, files |
| [CHANGELOG](CHANGELOG.md) | What changed in each version |

## License

[MIT](LICENSE). Paymenter and Wizarr are separate projects with their own licenses.
