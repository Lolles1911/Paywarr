# Changelog

What changed in each version of Paywarr. Each section is also the text of its
release on GitHub (`.github/release-notes.sh` puts it there).

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
