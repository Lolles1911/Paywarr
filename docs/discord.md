# Discord

Members only at checkout, leavers, logging in with Discord, a members-only shop, and roles.

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
- The check happens at purchase. To also act when a customer leaves later,
  see [Customers who leave the Discord](#customers-who-leave-the-discord).
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

## Customers who leave the Discord

Tick **Suspend customers who leave the Discord** under Paywarr → Settings, and
subscriptions to products with **Require Discord membership** follow the
membership after purchase too. Paywarr checks every hour:

1. **The customer has left:** they are warned, in Paymenter (the bell) and by
   email, that they have **Hours to rejoin before the suspension** (default 24)
   to join again.
2. **Still gone when the hours are up:** the subscription is suspended, and
   they are told so.
3. **Back on the Discord:** it opens again by itself within the hour, or at
   once when they open **Account → Discord**. Back within the hours, the
   warning is simply dropped.

- **0 hours** suspends at once, without a warning.
- Customers who never connected Discord, and products without the Discord
  requirement, are left alone.
- A subscription suspended for another reason (e.g. an unpaid invoice) isn't
  opened again on rejoin, and one with an overdue invoice stays suspended.
- If Discord can't answer, nothing happens that hour. The reason is in the log.

## Log in with Discord

When the Discord fields are filled in, the login page shows the button
**"Log in or create an account with Discord"** below the regular form. The
register page shows **"Create an account with Discord"** only with a theme that
offers the `auth.register` hook (see [For theme authors](development.md#for-theme-authors)):
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

Tick **Only Discord members can see the shop** under Paywarr → Settings. Categories,
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
  them (see [For theme authors](development.md#for-theme-authors)).

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

## Commands

Customers can ask the bot, and only they see the answer:

| Command | Danish | Answers |
|---|---|---|
| `/status` | `/status` | Their subscriptions: active and renewal date, trial, paused, and their user |
| `/newlink` | `/nytlink` | Their invitation link, a new one when it expired; not when the user exists |
| `/stats` | `/stats` | Plays and watch time over 7 and 30 days (with Paywarr Stats) |
| `/password` | `/kodeord` | A link to choose a new password (Jellyfin, Emby, Silo) |

To switch them on: in the [Discord developer portal](https://discord.com/developers/applications),
your application → **General Information**: copy the **Public Key** into
**Discord application: Public key** under Paywarr → Settings, and set
**Interactions Endpoint URL** to `https://your-paymenter/wizarr/discord/interactions`
(Discord checks it when you save). Paywarr registers the commands on your
Discord server within the hour. Customers need to have connected Discord
under their account.

## Announcements

With **Discord: announcements channel ID** set (right-click the channel → Copy
Channel ID; the bot needs to be allowed to post there), the bot posts "New on
the server" every week (**New on the server: weekly on**, at 17:00: the movies
and shows Tracearr saw added, and with **mail it to customers too** also as a
mail with a link to stop them) and, with Paywarr Stats, the server's year in
review in December.

[← Paywarr](../README.md)
