# Changelog

What changed in each version of Paywarr. Each section is also the text of its
release on GitHub (`.github/release-notes.sh` puts it there).

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
