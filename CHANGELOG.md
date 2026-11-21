# Changelog

What changed in each version of Paywarr. Each section is also the text of its
release on GitHub (`.github/release-notes.sh` puts it there).

## 1.28.0 (2026-11-21)

- **New: Paywarr → Free access.** Free subscriptions (a price of 0, or a product
  with the new "Free access") and users without a subscription, with when they
  last watched. Who watched nothing for a number of days is warned, and some
  days later their access goes: Plex's share removed; Jellyfin, Emby and Silo
  switched off, then deleted; a free subscription cancelled. Watching resets
  it. The rules are set on the page; new users get a grace period; "Exempt"
  (the same list as the clean-up's "Keep"); a log; and "Give free access" to a
  customer from admin.

## 1.27.0 (2026-11-21)

- **New: extra users** (e.g. family): a configurable option `extra_users` and
  "Extra users: at most" on the product. Each gets their own invitation on
  every media server of the subscription and follows it; buying fewer removes
  the last ones. "Max streams at once" per user, or shared.
- **New: service status.** A media server that goes down gets a notice by
  itself (a bar on top of the client area for the customers on it, the public
  page /status, a post in the Discord channel), ended when it is back. Your
  own notices (e.g. planned maintenance) under Paywarr → Service status,
  optionally mailed.
- **New: Paywarr → Message customers.** To all active customers, a server, a
  product, a status, or one customer from their card; by mail, Discord, the
  bell and/or the announcements channel.
- **New: anniversaries.** Every so many months as a paying customer: a
  thank-you with free days, a discount code, or only the thank-you.
- **New: Paywarr → Monthly reports**, made on the 1st and kept two years:
  money, subscriptions, trials, keeping customers, watching, streams.
- **New: clean-up.** Users on the media servers without a subscription are
  switched off after a number of days and deleted later (both settings);
  "Keep" on Media server users, and a log of what was done. Expired
  invitations that belong to nobody are removed.

## 1.26.0 (2026-11-21)

- **New: customers pause themselves** ("Pause subscription", instead of
  cancelling): access off, the renewal date moved as many days, resume at any
  time. Times a year and the longest pause are settings on the Wizarr server.
- **New: a come-back code**, by mail and Discord, some days after a
  subscription was cancelled (days, discount and how long it's valid are
  settings). Once a year per customer.
- **New: invite a friend.** Every paying customer gets their own code ("Invite
  a friend" in the menu). The friend gets a discount on their first month;
  once they have paid, the customer gets free months added.
- **New: Tracearr in Paywarr** (URL and key on the Wizarr server; Paywarr Stats
  uses them too):
  - **Max streams at once** on a product, checked every minute: the newest over
    the limit is stopped (or only warned about), and the customer is told.
  - **Paywarr → Streams and sharing**: the stops, and Tracearr's own rule
    breaks (e.g. one account in two places), each with its customer, also on
    the customer card.
- **New: Discord commands** for customers: /status, /newlink, /stats and
  /password (in Danish /nytlink and /kodeord). Needs the application's public
  key and the Interactions Endpoint URL (see Discord in the docs).
- **New: "New on the server"** every week: the movies and shows added, posted
  in a Discord channel and, if you want, mailed to customers (with a link to
  stop them).

## 1.25.1 (2026-11-20)

- **Fixed:** Silo customers could be sent to your Jellyfin. Paywarr kept one
  list of media server addresses for Wizarr and Silo together, and their
  server IDs overlap (Silo's is always 1), so the welcome mail's "Watch in the
  browser" and the "Open the media library" button could show Jellyfin's
  address. Each Wizarr and Silo now has its own list.
- **Fixed:** a "media server" option left on a product with one media server
  (always so on Silo) stopped the order with "matches none of the servers".
  The choice is now left alone there.
- **New in Checks:** a product that asks for a media server it doesn't need,
  and a product whose media server choice names a server it doesn't have.
- Right after payment the subscription says "Your access is being prepared"
  instead of "Your invitation link has expired"; if the link never comes,
  "Your access is not ready yet" with "Get a new invitation link".

## 1.25.0 (2026-11-20)

- **New: Paywarr's own welcome mail** (Wizarr server: "Send Paywarr's welcome
  mail"). The link(s) to create the user, three steps to get going, where to
  get the apps for Jellyfin, Emby, Plex or Silo, your Discord and your own
  words. A new link for an existing subscription is mailed the same way, as
  "Your new link". Switch off Paymenter's "Service activated" mail when you
  use it, or customers get two.
- **New: gift cards.** Set "Gift card: months" on a product. The buyer gets a
  one-time code worth what they paid, and can send it to a friend with a
  greeting from their subscription. The code works once within a year, and is
  an ordinary Paymenter coupon.
- **New: reminders**, by mail and a private message from the Discord bot, each
  sent once and switched on per Wizarr server: the trial ends in two days, the
  user isn't made two days after the link was sent, and the subscription
  renews in three days with the invoice unpaid.
- **New: a watch on Paymenter's queue.** When it stands still for more than a
  quarter of an hour (so no mails go out), Checks and the dashboard say so.
- **Fixed in Checks:** a gift card product was flagged as having no media
  server, and Discord with only the invite link was called half set up.
- The code is split into one file per topic, and the README is short; the
  rest is in `docs/`, one page per topic.

## 1.24.1 (2026-11-20)

- **New: Re-invite missing users** (Paywarr → Customers: on top for everyone,
  per customer under Access, and for the ones ticked). After a new media
  server, or users deleted there, every active subscription whose user no
  longer exists gets a new invitation, mailed to the customer. Users that
  exist are left alone. A media server that is gone from Wizarr is dropped and
  the product's media server takes its place; if the product still points to
  the old one, nothing is changed and you're told to choose the new one first.
- **Fixed:** "Sync now" took a known user that Wizarr no longer has for a link
  used without a user (as after a failed Plex share), and marked the customer
  as a problem. It now says the user is gone, changes nothing, and clears that
  wrong mark.
- A user Wizarr re-imported with a new ID is found again by its name on the
  same server (when exactly one has that name).

## 1.24.0 (2026-11-20)

- **New: a Paywarr group in admin.** Six pages:
  - **Customers:** every subscription with its user or invitation, trial,
    Discord name and a note; search by Discord name too; a Problems filter;
    and per subscription: new invitation, allow a new link, sync, password
    reset link, change media server (e.g. Jellyfin to Plex), libraries for
    this customer, link an existing user, reset user (optionally deleting it
    on the media server), pause and resume, and everything about trials (set
    the dates, extend, start over, end now, may try again, give trial days).
    Bulk actions, and a customer card with what was done, by whom and when.
  - **Server status**, **Media server users** (link, disable, delete the ones
    without a subscription), **Invitations** (with a clean-up), **Libraries per
    product** and **Checks** (what might be wrong in the setup).
  - Badges in the menu and a line on the dashboard when something needs a look.
- **New: "Problems? Get a new link"** on the customer's subscription, by an
  unused link and by a link that was used without a user being made (e.g. a
  Plex share that failed), at most once an hour.
- **Fixed:** a link used without a user being made showed "Active" and the box
  kept offering the spent link. Both now say "Your user was not created" and
  lead to a new link.
- **Pause:** keeps the paid time, sends no invoice meanwhile, and resumes by
  itself on the chosen date.
- Trial days given in admin count as a trial everywhere (checkout, the hourly
  run, Paywarr Stats 1.7.1).

## 1.23.0 (2026-11-16)

- **For Paywarr Stats 1.7:** the Stats overview can show how many requests
  each customer has used, and who has used their limit up. Paywarr reads only
  the quota for it, without searches or discovery rows.

## 1.22.1 (2026-11-16)

- **Changed: one trial per server.** A customer can try each of your servers
  once, e.g. both Silo and Jellyfin (also with both in the cart at once), but
  not the same one twice. Having paid on one server no longer rules out a
  trial on another.
- Buying after a trial only takes over the trial's user on the same server.

## 1.22.0 (2026-11-16)

- **New: customers who leave the Discord are suspended**, after a warning.
  Turn on **Suspend customers who leave the Discord** on the Wizarr server.
  For products with **Require Discord membership**, a customer who leaves is
  warned and has **Hours to rejoin before the suspension** (default 24) to
  join again. Otherwise the subscription is suspended, and it opens by itself
  when they rejoin. Customers who never connected Discord are left alone.

## 1.21.0 (2026-11-15)

- **New: links in the top bar.** Signed-in customers get **Requests**,
  **Report a problem** and **Open Silo** (or Open Jellyfin …) next to Home
  and Shop, and in the menu on a phone. "Open" leads where the "Open" button on
  their subscription does, once they have created their user.
- **Report a problem is for every customer** with an active subscription, not
  only those with requests. On a Wizarr server it needs a Seerr to search the
  library. The Requests tab stays for customers with requests.

## 1.20.3 (2026-11-15)

- **Fixed:** the posters on "Report a problem" were cut to a thin strip with
  themes that don't include the Tailwind classes the page used. The page now
  brings its own styles, like the Requests tab.
- **Report a problem** has a new look: two steps at the top, full posters
  (three across on a phone), the chosen title with its poster, and the
  problems as large choices with a short explanation instead of a list.

## 1.20.2 (2026-11-15)

- **Fixed:** on Silo, requesting from the Requests tab failed ("your limit is
  used"), as Silo never lets an API key act as another account. On Silo the
  button is now **Request in Silo**: it opens the title in Silo, where the
  customer is signed in and requests it with their own account. Search,
  Discover, the limit and "My requests" stay in Paymenter. Seerr is unchanged.
- Silo's requests must be switched on in Silo (*Settings → Requests → Allow
  requests*); the README says so now.

## 1.20.1 (2026-11-15)

- **Fixed:** a customer who signed up with Discord using a single name (no
  last name) couldn't open their Account page in Paymenter. New sign-ups get
  an empty last name instead of none. Customers who already signed up are
  fixed with:
  `cd /opt/paymenter && sudo -u www-data XDG_CONFIG_HOME=/tmp php artisan tinker --execute='App\Models\User::whereNull("last_name")->update(["last_name" => ""]);'`

## 1.20.0 (2026-11-15)

- **New: the Requests tab.** Customers with requests can search, discover and
  request movies and shows inside Paymenter, choose a show's seasons, see what
  is left of their limit and follow their own requests. With Seerr everything
  happens as the customer; on Silo the request is placed as the customer's
  own account. A server setting chooses who may request: customers who bought
  requests, or every active customer.
- **New: extra requests.** A top-up product ("Extra requests" per unit, with
  quantity) adds requests to the customer's package for the package's period,
  e.g. 30 days. The Requests tab offers "Buy more requests" when the limit is
  used. Refunded top-ups come off again.

## 1.19.0 (2026-11-15)

- **New: Report a problem.** Customers with requests can report a movie or
  show with a fault (picture, sound, subtitles, wrong or missing episode,
  something else) from a new page in the client area. They search their own
  library (Silo's catalog, or with Seerr what is on the media server), choose
  the title, and it becomes a support ticket in Paymenter on their
  subscription, naming the exact title for staff. The department is a server
  setting.

## 1.18.1 (2026-11-15)

- The configurable option that sells requests is now called **`requests`**
  (environment variable), as it is not only Seerr: on a Silo server it is
  Silo's own requests. An option set up as `seerr` keeps working; if a
  subscription has both, `requests` counts. The product setting is now called
  **Requests included** (same setting, nothing to redo).

## 1.18.0 (2026-11-15)

- **New: requests on Silo.** On a Silo server, the `seerr` option and its
  request packages now control Silo's own requests instead of Seerr: switched
  on per account with the package's limit (e.g. 10 every 30 days, unlimited,
  or Silo's default), off without the option and while suspended. Changing
  package changes the limit at once, and the client area shows it.
- **Fixed:** with both a Wizarr and a Silo server in Paymenter, the hourly run
  handled every subscription with the first server's settings, so trials and
  requests of Silo customers could go to the wrong place. Each subscription
  now uses its own server's settings.

## 1.17.0 (2026-11-14)

- **New: Silo, directly, without Wizarr.** A server in Paymenter can connect
  to Silo instead of Wizarr ("Connects to"). Payment makes a one-time link
  invitation for an ordinary account with the product's libraries; overdue
  disables the account and payment enables it again; cancellation deletes it;
  "Reset password" uses Silo's own reset link. Administrators are never
  touched. Silo's API key needs full access. See "Silo, without Wizarr" in the
  README.

## 1.16.2 (2026-11-14)

- **Fixed:** an "Open" button never appeared when the media server's External
  URL was added in Wizarr after the customer's first visit. Addresses are now
  read from Wizarr again (at most every 10 minutes), and one without
  `https://` gets it.

## 1.16.1 (2026-11-14)

- Paywarr Stats moved to its own project:
  [Paywarr-stats](https://github.com/Lolles1911/Paywarr-stats).

## 1.14.0 (2026-11-14)

- **Up to three Seerr**, one per media server, Plex included.
- Requests can only be bought with a media server the Seerr is connected to.

## 1.12.1 (2026-11-14)

- Each media server gets its own "Open" button in the client area.

## 1.12.0 (2026-11-14)

- **Seerr request packages:** a request limit per customer, sold as an option.

## 1.11.0 (2026-11-14)

- **Jellyfin, Emby or both:** the customer can choose, and each server gets
  its own invitation.

## 1.10.0 (2026-11-14)

- A full refund of an invoice suspends the access it paid for.
- Alerts on Discord when something needs you, and an hourly heartbeat.
- A backup script for Paymenter, an email guide, tests on GitHub and releases.
