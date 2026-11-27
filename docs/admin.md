# Admin: the Paywarr pages

Paymenter's admin gets four Paywarr groups: **Paywarr: Customers**
(Customers, Media server users, Invitations, Free access, Moved to paid,
Streams and sharing), **Paywarr: Events** (Campaigns, Movie nights,
Challenges, Invite a friend, Satisfaction), **Paywarr: Operations** (Server
status, Service status, Checks, Libraries per product, Help page, Monthly
reports, and Paywarr Stats' pages) and **Paywarr: Mail** (Automatic mails,
Recipients, Message customers, Sent, Design). Below, "Paywarr → X"
is the page X in one of them. Staff who may view services see them; the
buttons need the right to edit services (products for the libraries). The
pages ask Wizarr and Silo at most once a minute.

![Paywarr customers](../screenshots/admin-customers.png)

## Customers

Every Paywarr subscription: the customer with their email, **Discord name** and
note, the subscription, its status, the user or invitation on each media
server, and the trial. Search by name, email, Discord name or ID, or the note.
Filters: status, server, without a user, and **Problems** (link used without a
user, link expired, may get a new link, left the Discord, paused, trial ended
and not bought). The menu shows how many customers need a look.

**Access** (the key):

| Button | Does |
|---|---|
| New invitation | A new link for every server where the customer has no user yet; mailed or not |
| Allow a new link | The customer gets "Get a new invitation link" once, even after using theirs (e.g. a failed Plex share) |
| Sync now | Asks Wizarr/Silo again: records the user, moves a trial along, Discord role and Seerr in line. Says so when a link was used without a user |
| Password reset link | A link to choose a new password on Jellyfin, Emby or Silo; mailed or not |
| Change media server | E.g. from Jellyfin to Plex on the same Wizarr: the new server gets an invitation, the user on the old one is removed, as at an upgrade |
| Libraries for this customer | Used for the customer's next invitation instead of the product's (Wizarr can't change an existing user's libraries) |
| Link an existing user | Gives the subscription a user that already exists, e.g. a friend's account from before |
| Note | About the customer, for you only |
| Reset user | Forgets the user and sends a new invitation; can delete the user on the media server too |
| Re-invite missing users | Where the customer's user no longer exists (a new media server, a user deleted there), a new invitation; users that exist are left alone |

**Subscription:** **Pause** switches the access off as when unpaid, without
telling the customer, and keeps the paid time: Paymenter sends no invoice and
doesn't terminate while paused, and the subscription resumes by itself on the
date you choose (or when you press **Resume**). A card or PayPal subscription
at the payment provider keeps charging: pause it there too. On Plex the share
is removed, and the customer gets a new invitation when it resumes.

**Trial:** set the start and end, extend, start over, end now, **may try
again** (this trial no longer counts at checkout; a "Per user limit" on the
product still does), and **give trial days** (makes the subscription a trial of
so many days, e.g. a free week for a friend; it closes at the end as any
trial).

Tick several customers for **Sync now**, **Allow a new link** or **New
invitation** at once; **New links for expired ones** does that for every link
that expired before a user was made.

**After a new media server** (e.g. a new Jellyfin without the old users):
check **Checks** first. If a product "uses a media server that is gone", the
new server got a new ID in Wizarr: choose it on the product. Then press **Re-invite
missing users** on top: every active customer whose user is gone gets a new
invitation by mail, the others are left alone.

Click a customer's name for their **card**: everything above for that customer,
their requests, and **what was done here**: each action with who did it and
when (links are left out).

![A customer card](../screenshots/admin-customer-card.png)

## Server status, users, invitations, libraries, checks

- **Server status:** every Wizarr and Silo, with its media servers: online or
  not verified, users, libraries, how fast it answered. One that doesn't answer
  shows the reason, a red badge in the menu and a line on the dashboard.
- **Media server users:** every user on your media servers with the
  subscription it belongs to; by default those **without one**. Link one to a
  subscription, disable, enable or delete it.
- **Invitations:** every invitation with its status, how long it works and its
  subscription. **Clean up** deletes expired ones and unused ones that no
  subscription has.
- **Libraries per product:** the media servers and libraries each product
  gives, with its trial, link lifetime and Discord requirement; the libraries
  can be chosen there.
- **Checks:** what might be wrong, worst first, each with a button to where it
  is fixed: a Wizarr or Silo that doesn't answer, an unverified media server, a
  product without a media server or with libraries that are gone, a server
  without a public address, Discord half set up, a trial product without a
  per-user limit, paid subscriptions that got no invitation.

The dashboard shows a Paywarr line when customers or servers need a look.

**Paymenter's queue:** Paymenter sends invitations and mails through its queue
worker (`paymenter.service`). Paywarr sends a small test job through it every
hour; if it hasn't run a quarter of an hour later, Checks and the dashboard say
that the queue stands still, so a worker that stopped (e.g. during an update)
doesn't go unnoticed for days. `systemctl restart paymenter` starts it again;
`journalctl -u paymenter` tells why it stopped.

## Streams and sharing

With **Tracearr URL** and **API key** under Paywarr → Settings (Tracearr:
Settings → Data & API → API), Paywarr knows what is streaming:

- **Max streams at once** on a product: checked every minute. More at the same
  time on one subscription, and the newest are stopped (Plex shows the reason
  on the screen; Jellyfin and Emby just stop), or only warned about with
  **Too many streams at once: Only warn the customer**. The customer gets a
  mail and Discord message, at most once a day.
- **Paywarr → Streams and sharing** lists those, and Tracearr's own rule breaks
  (rules you make in Tracearr, such as one account in two places), each with
  its customer; the menu shows how many in the last 7 days. The customer card
  shows them too.

Silo is not in Tracearr, so Silo subscriptions have no stream limit.

## Messages, service status and reports

- **Message customers:** a message to all active customers, those on a server
  or product, a status (paying, on a trial, suspended, cancelled with nothing
  running), or one customer ("Send a message" on their card). By mail, private
  Discord message, under the bell and/or as a post in the announcements
  channel. The messages sent are listed.
- **Service status:** the notices customers see (see What customers see):
  outages that came by themselves, and your own, e.g. planned maintenance from
  and until a time, for everyone or the customers of some servers, optionally
  mailed and posted. End or delete them here.
- **Monthly reports:** made on the 1st for the month before and kept two years;
  "This month so far" at any time. Paid for subscriptions, paying customers,
  new and cancelled, trials and how many became paying, gift cards, what was
  done to keep customers (pauses, come-back codes, friends, anniversaries),
  watching (with Tracearr) and streams and sharing.

## Clean-up

With **Clean-up: switch off users without a subscription after (days)** on the
Wizarr server, users on the media servers that belong to no subscription
(left over, made by hand) are switched off after that many days, checked
daily, and with **delete them after (more days)** deleted that much later.
Plex users can't be switched off; they are only deleted. Expired invitations
that belong to nobody are removed a week after they expired. Media server users
shows when each will be switched off and deleted; **Keep** a user (an old
friend, your own) and the clean-up never touches it. **Clean-up log** lists
what was done.

## Free access

**Paywarr → Free access** follows everyone who watches for free: free
subscriptions (a price of 0, or a product with **Free access**) and users on
the media servers without a subscription. It shows when each last watched
(from Tracearr, and Silo itself), and what happens if nothing changes.

**Rules** (set on the page): after **Nothing watched for (days)** they get a
warning ("Still watching?"; customers by mail and Discord, users without a
subscription by mail when Wizarr or Silo knows their address), and **removed
after (days)** later: Plex's share is removed, Jellyfin, Emby and Silo users
are switched off and deleted after **(days)** more, and a free subscription is
cancelled. Watching anything in between resets it. New users are left alone
for **(days)**. With the rules off, the page only shows who would be warned and
removed. Checked daily.

**Exempt** a user (family, your own) and they are never warned or removed; it
is the same list as **Keep** for the clean-up. **Give free access** gives an
existing customer a free subscription from here, with the invitation by mail.
**Log** lists who was warned and removed, and when. A product with **Free
access** can also be in the shop for anyone to order.

**Move to paid** turns free users into paying ones. Choose who with a filter
(free subscriptions and/or users without a subscription, their status, what
they are on now; the dialog counts them), or by name: **Only these users** in
the dialog, **Move to paid** on a row, or tick several in the list and move
them together. Then the paid product (or **the same
media server**: the paid product on the same server with the fewest other
servers), and the days to pay in. Each keeps their user and history and gets:

- a free subscription: changed to the paid product and price, with an invoice
  due on the deadline;
- a user without a subscription whose mail address has an account: a new
  subscription with their user on it, and the invoice;
- a user without an account: a mail with the shop. They show as **Offered
  paid** and lose the access at the deadline unless a subscription has their
  user by then.

They are told by mail (and Discord), and Paymenter sends its invoice mail.
The dialog lists who will be moved before you press Move. A **welcome
discount** takes a percentage off the first invoice (later ones are the full
price). Those who haven't paid are reminded ("Your access stops soon") a
number of days before the deadline. Unpaid by the deadline, Paymenter suspends
the subscription as any unpaid invoice (after its **suspend after** days). The
default product, days, discount and reminder are set under **Rules**.

**Paywarr → Moved to paid** (shown once someone is moved) follows them: the
invoice and deadline, **Paid**, **Waiting** or **Not paid**, how many of those
due paid, and **Export CSV**. **Back to free** undoes a move made by mistake:
the invoice is cancelled, the subscription gets its old free product and
renewal back (one made by the move stays, at 0), and a suspended one is opened
again.

## Campaigns, movie nights, challenges, friend pass

- **Campaigns**: **New campaign** with a title, a percentage, start and end.
  **A code for new customers** makes a Paymenter coupon (one use per customer,
  off for the periods you set, on the products you choose or every
  subscription); **Existing customers** get the percentage off their next
  renewal in the period, once. A banner with a countdown shows on every page
  while it runs, and **Announce** sends it by mail, Discord and the
  announcements channel when it starts. It starts now unless you set a later
  time. **Where it shows**: a banner for visitors, a banner for customers,
  mail and Discord when it starts (mail only to the mail list), and the
  announcements channel. The list shows when it starts, where it shows, new
  orders with the code, renewals made cheaper and what was given; **Edit**
  changes it (the code until someone used it), **Start now** starts a planned
  one, **End now** stops it.
- **Movie nights**: **Plan a movie night** with a title, a time, a few words,
  an optional link to the title, and who it is for. **Invite now** sends it by
  mail and Discord and posts it in the announcements channel with a thread for
  talking along (the bot needs "Create Public Threads"). Those who press "I'm
  coming" are reminded an hour before.
- **Challenges**: **New challenge**: what counts (movies, episodes or hours),
  the goal, the period, and the prize: a free month (the renewal moves a
  month), credit (Paymenter uses it on the next invoice) and/or a Discord role.
  Counted hourly from Tracearr and Silo; a play counts after 2 minutes, a movie
  or episode once. At the end the prizes are given and the result posted.
- **Friend pass**: tick **Friend pass** under Paywarr → Settings. Paying customers
  then have **Friend pass** in their menu: a free week for a friend, once a
  month, and once per friend. After the week the friend's user is deleted
  unless they bought a subscription with the same mail address.

## Settings

**Paywarr → Settings** (Operations): every setting for the whole shop, in
tabs: Shop, Reminders, Loyalty, Discord, Connections (Seerr, Tracearr, Sonarr,
Radarr) and Alerts and clean-up. The Wizarr server under Servers keeps only how
Paymenter reaches Wizarr or Silo (address, public address, API key). Keys,
secrets and tokens are kept encrypted. Updating from 1.36 or older moves the
values from the server(s) here once, by themselves (the first server with a
value wins).

## Price changes, most wanted

- **Price changes** (Customers): **New price** picks a plan, the new price and a
  date at least 30 days away. The plan's price changes at once (new customers),
  everyone on the plan gets "Price change" by mail, and on the date their
  subscriptions get the new price (with any discount they have), so invoices
  made from then on use it. **Cancel** before the date puts the plan's price
  back (customers were told already: send a message if needed).
- **Most wanted** (Customers): titles requested and not on the server yet, by
  how many want them. **Added** takes one off the list.

## Updates and backup

- **Updates** (Operations): the installed and newest versions of Paywarr and
  Paywarr Stats (from GitHub, every 6 hours), what each newer version brings,
  and the commands for the server. The menu shows a badge when there is one.
- **Backup** (Operations): **Download a backup** gives Paywarr's own data in one
  file (settings without keys, secrets and tokens; mail texts and design;
  campaigns, movie nights, challenges; the waiting list; price changes; the
  help page; logs). **Restore** puts such a file back; the keys you have now
  are kept. Customers, subscriptions and invoices are in Paymenter's database.

## Capacity and the waiting list

**Paywarr → Capacity** (Operations): every media server with seats in use =
users + unused invitations + unpaid orders. **Set maximum** gives a server a
number of seats; Paywarr then keeps the Paymenter stock of the products on it
at the free seats (every 5 minutes, and right after you save), so a full server
shows "out of stock" in the shop. A product on several servers gets the fewest
free. Removing the maximum makes the stock unlimited again. Products without a
maximum are left alone.

**Paywarr → Waiting list** (Customers): who waits for which product, and who was
mailed. **Offer a seat now** mails one by hand; **Remove** takes them off.
Those who order leave the list by themselves.

## At risk

**Paywarr → At risk** (Customers): paying customers (no trials, gift cards or
top-ups) who haven't watched for at least 7/14/30/60 days, from Tracearr's last
60 days, soonest renewal first, with when they were last reminded. **Send a
reminder** mails "Watched nothing for a while" with a few new titles. The
automatic reminder is Paywarr Stats' "Remind customers who watched nothing";
with Paywarr Stats 1.11.5 or newer it sends that same mail. Silo users count
when Tracearr watches your Silo (Tracearr 3.3 or newer).

## Discount codes

**Paywarr → Discount codes** (Events): every coupon, newest first, marked by
what made it: Gift card (`GIFT-`), Come back (`BACK-`), Anniversary
(`THANKS-`), Invite a friend, Campaign, or Other (your own). For each: how
often it was used, and **Paid since**: everything the subscriptions that used
it have paid, the first invoice and every renewal. On top, the same per kind.

## Several customers at once

On **Paywarr → Customers**, tick subscriptions and open **Bulk actions**:
besides Sync, a new link and invitations, there are **Give extra days** (a
longer trial, or a later renewal; the waiting invoice moves too; "Tell them"
sends "Extra days"), **Send a message** (once per customer), and **Change media
server**.

## Mail

- **Automatic mails:** every mail Paywarr sends by itself (welcome,
  reminders, movie nights, anniversaries, the survey and more), with when it
  goes and what customers unsubscribe from it as. **Preview** shows it with
  example values (an HTML mail such as the welcome: the last one sent),
  **Send me a test** mails it to you, **Edit** switches it off or gives it your
  own subject and text with {placeholders} such as {product} and {date}
  (blank: Paywarr's words, in the customer's language; your words are used in
  every language). **Paywarr's words** goes back. Whether a reminder is used at
  all is still set under Paywarr → Settings. Welcome switched off: Paymenter's own
  mail with the link goes instead.
- **Recipients:** how many are on the mail list and have unsubscribed from
  each kind, unsubscribes in the last 30 days, and every customer with what
  they get. **Change** sets it for a customer who asks you.
- **Message customers:** as before, now in this group.
- **Sent:** Paywarr's mails in the last 7 and 30 days, per kind, and the last
  300 (**Open** shows the mail). Paymenter's own Email logs has every mail.
- **Design:** the colour of buttons and the line under the logo, the logo or
  the site's name on top, and a line at the bottom (e.g. company name and
  address), with a preview. ("Mail colour" is gone from the Wizarr server; a
  colour set there is used until you save one here.)

## The mail list

News and offers go by mail only to customers on the mail list: they tick
**Send me news and offers by mail** when they make their account, in the
cart above the Checkout button, or under Account → Personal details, and leave it with the link
in every such mail. Everyone starts off the list. Customers whose account
is older than the list are asked once in the client area ("News and offers by
mail?" Yes please / No thanks), not by mail, as asking by mail is marketing
in itself. Campaigns, movie nights,
"New on the server", Message customers and "we'd love to have you back" use
it; mails about the customer's own subscription (invitations, reminders,
invoices, outages) always go. The field is a Paymenter custom property
(Admin → Configuration → Custom Properties, key `paywarr_mail_list`) that
Paywarr makes; rename it there if you like.

### Unsubscribe

Every mail a customer can do without ends with a line like "You get this
because you get reminders from us. **Unsubscribe from reminders**". One click
turns off that kind only (no login), with a button to turn it back on. Mail
programs show their own Unsubscribe button too (the `List-Unsubscribe` header).

| Kind | Mails |
|---|---|
| News and offers | campaigns, "New on the server", Message customers (as marketing), "we'd love to have you back". Unsubscribing takes them off the mail list |
| Movie nights and challenges | movie night invitations and reminders, challenge prizes (also only on the mail list) |
| Reminders | trial ending, user not created yet, renewal, unpaid cart, free access about to end, Paywarr Stats' "we miss you" |
| Stats and surveys | the satisfaction survey, anniversaries, Paywarr Stats' Wrapped |

Mails about access and payment (invitations, access ready, new password, move
to paid, outages, gifts, pauses, the Discord page) have no such line and always
go. What a customer turned off is the user property `paywarr_mail_off`.

## Satisfaction, Invite a friend board, CSV

- **Satisfaction** (with **Satisfaction survey** under Paywarr → Settings): after a
  month as a paying customer and every half year after, a mail asks "How likely
  are you to recommend us?" (0-10, one click, then a comment); customers who
  cancel are asked why (one click). The page shows the NPS, the answers, and
  why customers cancelled, also in their own words from Paymenter.
- **Invite a friend**: who brought the most friends, how many still pay, what
  they paid, and the free months given.
- **Help page**: the guides and questions customers see under Help.
- **Export CSV** on Customers, Monthly reports, Satisfaction and Invite a
  friend (and Viewing per customer in Paywarr Stats), for Excel.
- **Uptime**: Service status keeps each server's checks per day: percent over
  30 and 90 days, and a bar per day, also on `/status`.

[← Paywarr](../README.md)
