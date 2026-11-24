# Requests, reports and statistics

Seerr or Silo for requests, request packages and top-ups, the Requests tab, "Report a problem", and Paywarr Stats.

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
   - Environment Variable: **`requests`** (`seerr`, as it was called before
     1.18.1, keeps working)
   - Products: your subscription
   - Options: **No** (env `no`, price 0) and **Yes** (env `yes`, your price per
     period). The Danish `nej`/`ja` work too. To sell request packages instead
     of a plain Yes, see *Request packages* below.
7. If a product should always include requests (e.g. a premium plan), tick
   **Requests included** on the product instead.

Customers with the add-on get the button **"Request movies and shows"** in the
client area.

### More than one Seerr

A Seerr is connected to one media server (Jellyfin, Emby or Plex). To offer
Requests to customers on several, run a Seerr for each (they can all send
requests to the same Radarr and Sonarr) and fill in **Seerr 2** and **Seerr 3**
under Paywarr → Settings: URL (internal), URL (public) and API key, like the first.
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

Instead of a plain Yes, the `requests` option can sell packages with a limit on
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

## The Requests tab

Customers with requests get **Requests** in the client area menu and the top
bar (`/wizarr/requests`), a request page inside Paymenter:

- **What is left** of their limit, e.g. "3 of 10 requests left · 30 days" (with
  Seerr movies and TV seasons apart, as Seerr counts them).
- **Search** for movies and shows, each marked *Available*, *Partly
  available*, *Requested* or *Processing*; the others have a **Request**
  button. For a show the customer chooses the seasons (on Silo, in Silo).
- **Discover:** rows of trending, popular and upcoming movies and shows.
- **My requests:** their own, with their state (requested, approved,
  processing, available, declined).
- With subscriptions on more than one server, a tab per library.

**With Seerr** everything happens as the customer (Seerr's `X-API-User`), so
their own limit, name and Seerr's approval rules apply.

**On Silo** the page is read with the server's key, and the button is
**Request in Silo**: it opens the title in Silo (`<public URL>/title/movie/603`),
where the customer is signed in and requests it with their own account, and
chooses a show's seasons. Silo doesn't let an API key request for someone else,
so the request itself is made in Silo; their own limit, name and Silo's
approval rules apply. The address is the server's *Wizarr or Silo URL
(public)*. Requests must be switched on in Silo (*Admin → Settings → Requests →
Allow requests*), or search and Discover stay empty.

**Who may request** is a server setting, *Requests: who may request*:

| Setting | Who gets the tab, and requests in Seerr or Silo |
|---|---|
| Customers who bought requests (default) | Subscriptions with the `requests` option, or *Requests included* on the product |
| Every active customer on this server | Every active subscription on the server, with the default limit |

### Extra requests (top-ups)

Customers who have used up their requests can buy more:

1. Create a product, e.g. "Extra request", on the same server, with a
   **one-time** price per request and **Allow quantity: combined**, so the
   customer chooses how many. (The product page asks for a media server too;
   choose any, it isn't used.)
2. On the product, set **Extra requests (top-up product)** to how many one of
   it gives, e.g. `1`.
3. On the server, put the product's page in **Requests: link to buy more**.
   The Requests tab then shows **Buy more requests** when the limit is used.

When paid, the requests are added to the customer's package (number × quantity)
for the package's period from the purchase, e.g. 30 days, and then expire; no
invitation is sent. They can only be bought on top of a package with a limit:
"unlimited", or Seerr's or Silo's default limit, can't grow, and checkout
refuses a top-up without a package. A refunded top-up comes off again. With
Seerr the extra requests count for movies and for TV seasons alike.

## Report a problem with a movie or show

Every customer with an active subscription gets **Report a problem** in the
client area menu and the top bar, at `/wizarr/report`, with or without
requests. They search their library, so it needs something to search: on a
Silo server that is always there; on a Wizarr server (Jellyfin, Emby, Plex) it
needs a Seerr set up on the server, or the link doesn't show.

1. They search their library and choose the title: on Silo its catalog, with
   Seerr only what is on the media server (available or partly available).
2. They choose what is wrong (picture, sound, subtitles, wrong or missing
   episode, something else), for a show the season and episode, and can add a
   description.
3. It becomes a **support ticket** in Paymenter, on their subscription, so you
   answer it like any other ticket and they get your answer there and by mail.

The ticket names the title as the library knows it (looked up again, never
taken from the form), with Silo's catalog ID or Seerr's TMDB ID, the
subscription and the customer's media server user, so you know exactly which
file. It goes to the department set under **Problem reports: ticket
department** on the server, or the first department. One report every half
minute and ten a day per customer.

## Customer statistics: Paywarr Stats

Want each customer to see their own watch statistics in the client area?
[**Paywarr Stats**](https://github.com/Lolles1911/Paywarr-stats) is a separate
extension that does that with Tracearr, using the media server users Paywarr
records.

[← Paywarr](../README.md)
