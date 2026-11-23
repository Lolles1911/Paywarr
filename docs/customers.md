# What customers see

The box on every page, the welcome mail, reminders, the password reset, the top bar, and the languages.

## "Your access is ready"

![The box in the bottom right corner](../screenshots/banner.png)

As long as a customer has a paid subscription with an invitation that hasn't
been used yet, every page in the client area shows a small box at the bottom:

- **Right after payment:** "Your access is being prepared". Paymenter creates the invitation in the background a few seconds
  after payment. The box checks every 3 seconds and moves to the next step on
  its own, without reloading the page.
- **Ready:** "Your access is ready" with the button **Create your user**,
  which opens the invitation link directly.
- **Expired:** "Your invitation link has expired" with a button to the
  subscription, where the customer can get a new link.
- **User not created:** the link was used, but no user was made (e.g. a Plex
  share that failed): "Your user was not created" with a button to the
  subscription, where the customer gets a new link.
- **User created:** the box disappears by itself.

The customer can close it with ✕; it comes back if the message changes (e.g.
from "ready" to "expired").

Pages don't get slower: the box is drawn from what is stored on the
subscription and asks Wizarr in the background (at most 40 times a minute per
customer). It uses the theme's colours, so it fits the default theme and
custom themes alike. If the queue gives up on creating the invitation, the box stops
waiting after 15 minutes.

**"Problems? Get a new link":** by an unused link, and by a link that was used
without a user being made, the subscription has this button. The customer gets
a new link and the old one stops working; at most once an hour. A customer
who has a user only gets a new link when you allow it in admin ("Allow a new
link", see [Admin](admin.md#customers)), so one subscription never turns into two users.

## Welcome mail

Tick **Send Paywarr's welcome mail** on the server, and customers get Paywarr's
own welcome instead of a bare link: the button(s) to create their user, three
steps to get going (create the user, where to watch, where Requests and "Report
a problem" are), **where to watch** with the apps for their media server
(Jellyfin, Emby, Plex, Silo in the browser), a button to your **Discord**, and
**your own words** from "Your own words in the welcome mail". A new invitation
later (a new link, a reset, a new media server) sends "Your new link" the same
way.

Paymenter still sends its own "Service activated" mail. Turn that template's
mail off (Admin → Notification Templates → Service activated), or empty the
products' Email template, so customers don't get two.

## Reminders

Each is switched on for the server, and sent once, by mail and as a private
Discord message to customers who connected Discord:

| Setting | When |
|---|---|
| Remind: the trial ends in 2 days | Two days before a trial ends, with a link to the subscriptions |
| Remind: user not created 2 days after the link | With the link again, while it still works |
| Remind: renews in 3 days and the invoice is unpaid | Not when paid automatically (a card or PayPal subscription) |

Paywarr Stats can add a fourth: customers who watched nothing for a number of
days ("Remind customers who watched nothing for (days)" on Paywarr Stats).

The bot can only write to members who share your server with it, and who
haven't closed their direct messages; the mail goes either way.

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

## Pausing

With **Customers may pause: times a year** set on the Wizarr server, active
subscriptions (not trials) get **Pause subscription**. The customer chooses
how long (up to **Longest pause (days)**, 30 by default): access is switched
off, the renewal date moves as many days, and their user and history stay.
They get a mail (and a Discord message) with a link to resume early; the pause
ends by itself on the day. Each pause shows on the customer card in admin.

## Coming back

With **Come-back code: days after cancelling** and **% off the first month**
set, a customer whose subscription was cancelled that many days ago (and who
has none running) gets one code by mail and Discord, valid for **Come-back
code: valid for (days)** (30 by default). At most once a year per customer;
trials get their own reminder instead.

## Invite a friend

With **Invite a friend: % off the friend's first month** set, customers with a
paid subscription get **Invite a friend** (in the My <site name> menu and on
its page): their own code (e.g. `PETER-DTH9`, a Paymenter coupon), a link to
share (`/wizarr/join/<code>`), and a form to email it. A friend who opens the
link lands on the product the customer has (or the shop) with a note about the
discount, and in the cart the
code is applied by itself; they can also type it under "Coupon". A friend who pays
with it gets that discount on their first period; once their first invoice is
paid, the customer gets **free months** (1 by default) added to their
subscription, and a mail saying so. Each friend can use a code once, and
nobody can use their own.
**Invite a friend: products the friend's discount is on** limits the code to
the products you choose (e.g. not yearly plans); empty means every
subscription. Changes apply to codes made before too.

## Extra users

A subscription can have extra users, e.g. for family. Make a configurable
option with environment variable `extra_users` and values `0`, `1`, `2`, …
(with a price each, as with Requests), attach it to the product, and set
**Extra users: at most** on the product. Each extra user gets their own
invitation on every media server of the subscription ("Jellyfin · user 2"),
in the welcome mail and on the subscription, and follows the subscription:
paused, suspended, cancelled. Buying fewer later removes the last ones.
**Max streams at once** applies to each user, or to all together with
**Extra users share "Max streams at once"**.

## Anniversaries

With **Anniversary: every (months)** set (e.g. 12), a paying customer gets a
thank-you on that day, by mail and Discord, with **Anniversary: gift**: free
days added to their subscription, a discount code, or only the thank-you.
Customers already past an anniversary when it is switched on are not
rewarded backwards.

## Service status

Customers with a subscription see a bar on top of the client area while a
notice concerns them, and **Service status** in their menu (`/status`, also
open to visitors who have the address) lists every media server,
up or down, the notices, and those of the last 14 days. A media server that
goes down (two checks in a row, every minute: Tracearr's servers, and every
Silo) gets a notice by itself, posted in the announcements channel, and ended
when it is back. Your own notices are made in admin (see Admin).

## Help and getting started

**Help** in the menu of customers with a subscription (`/wizarr/help`): guides per device (TV, Apple TV, Android,
computer, Plex, Silo), only for the media servers the customer has, frequently
asked questions, a search, and "Didn't help?" with Report a problem and a
ticket. Paywarr's own texts, in the customer's language, until you edit them
under Paywarr → Help page.

New customers (their first 30 days) see **Getting started** on the dashboard:
create your user, watch something, connect Discord, ask for a movie or show,
get the app. Steps tick themselves; the customer can hide it.

## Changing media server

**Change media server** on the subscription, when there is a choice: another
value of the `media_server` option at the same price, or another of the
product's servers. Free, once a month; a dearer choice links to Paymenter's
Upgrade. The new invitation is mailed, and the old user stays until the new one
is made (at most 7 days).

## Unpaid orders

With **Unpaid order: remind after (hours)** on the Wizarr server, an order
whose first invoice is unpaid gets one mail with the link to pay (and a second
with **remind again after (hours)**). Orders older than a week are left alone.

## My <site name>

**My <site name>** ("My" and the site name from Paymenter's settings, `/wizarr/me`, under Dashboard in the
menu) gathers it all as cards: the Open buttons, their subscriptions, the
service status, the movie night ("I'm coming"), the challenge's progress, the
friend pass, Invite a friend with their code, and shortcuts to Requests, My
stats, Help, Report a problem and Discord (each only when the customer has
it). The menu keeps Requests, My stats, Help and Report a problem; the rest is
on this page.

Above the cards: **Continue watching** (their own half-watched titles, with a
progress bar), **New on the server** and **Popular now**, as posters from
Tracearr (Plex, Jellyfin, Emby) and Silo; a poster opens their media server.

## A subscription's Overview

A Paywarr subscription's page has an **Overview** under the buttons: three
steps (create your user, get the app, watch something), a card per media
server with their user and **Open** (or **Create your user**), and shortcuts.

## The selection for visitors

With **Show the selection to visitors** on the Wizarr server, the front page
gets a row of posters ("Something for everyone") and **See the whole
selection** opens `/catalog`: movies and shows apart, posters only (no titles
or search), and **See subscriptions**. The posters are what's new and popular
in Tracearr; Paywarr serves the images itself.

## Mails

Paywarr's mails share one design: the logo from Paymenter's settings on a
dark top (the site's name without one), the button in **Mail colour** from the
Wizarr server, and links to My <site name>, Help and Status at the bottom.
Paymenter's own mails (invoices, receipts) are Paymenter's.

## Campaigns, movie nights, challenges, friend pass

- While a **campaign** runs, a banner with a countdown shows the code (or, for
  customers, that their next renewal is cheaper).
- **Movie night** (a card on My <site name> while one is planned for them):
  when, who is coming, "I'm coming", their Open links and the Discord thread,
  and a reminder an hour before.
- **Challenge** (a card on My <site name> while one runs): their progress, the
  leaderboard (first names) and the prize.
- **Friend pass** (paying customers, when switched on; a card on My
  <site name>): a free week for a friend, once a month, and the passes they gave.

## In the top bar

Signed-in customers with a subscription get links next to Home and Shop (in
the menu on a phone):

| Link | Who sees it |
|---|---|
| **🟢 Open Plex**, **Open Jellyfin**, **Open Emby**, **Open Silo** | Customers who have created their user: one per media server, with a dot for the servers they use (🟢 running, 🟠 maintenance, 🔴 down) |
| **✨ Create your user** | Customers whose user isn't made yet: their invitation (a menu, one per server, when several wait); it becomes Open within a minute of creating the user |
| **My <site name>** (a menu) | Overview (the My page), and while they are on: Movie night, Challenge, Friend pass, Invite a friend, Report a problem; and Service status |
| **Requests** | Customers with requests (see *The Requests tab*) |
| **Help** | Every customer with a subscription |

[Paywarr Stats](https://github.com/Lolles1911/Paywarr-stats) adds **My stats**
there too. Paymenter shows them as plain links; how they look is up to the
theme.

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

[← Paywarr](../README.md)
