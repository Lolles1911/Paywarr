# Media servers

Jellyfin, Emby or both, Silo without Wizarr, and what differs between Plex, Jellyfin and Emby.

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

## Silo, without Wizarr

[Silo](https://github.com/Silo-Server/silo-server) has invitations and account
management of its own, so Paywarr talks to it directly; Wizarr is not needed
for Silo. Silo is new and not at version 1.0 yet: its API may still change, and
this support has been tested against Silo's published API contract, not yet on
a real Silo server. Feedback is very welcome.

**API key.** In Silo: **Admin → API Keys → Create API Key**, label `Paywarr`,
access **Full access**. The key belongs to your admin account. A key with only
the `admin:users` scope is not enough: Silo keeps invitations, password reset
links and the library list outside that scope.

**Server in Paymenter.** **Admin → Servers → New server**, extension **Wizarr**:

| Field | Value |
|---|---|
| Connects to | **Silo (directly, without Wizarr)** |
| Wizarr or Silo URL (internal) | Silo's own address, e.g. `http://192.168.1.10:8090`. Not the Jellyfin-compatible port (8096) |
| Wizarr or Silo URL (public) | Where customers watch, e.g. `https://silo.yourdomain.com`; the "Open" button |
| API key | The key from Silo (starts with `sa_`) |

Silo makes the invitation links itself, from the public address set in Silo,
so check that address there too. Create the product as usual and choose
**Silo** as media server; the libraries are Silo's.

| Happens in Paymenter | In Silo |
|---|---|
| First invoice paid | A one-time link invitation for an ordinary account (never an admin), with the product's libraries |
| Invoice overdue | The account is disabled (same account, same history); an unused link is revoked |
| Paid again | The account is enabled again; a revoked link is replaced by a new one, by mail |
| Cancelled | The account is deleted |
| Forgotten password | "Reset password" opens Silo's own reset link |
| Expired link | "Get a new invitation link" |

Good to know:

- One server in Paymenter connects to Wizarr *or* Silo. To offer both, add two
  servers and a product for each.
- How long an invitation link is valid is a setting in Silo; the product's
  "Invitation link is valid for" is only used in the mail text, so set them alike.
- Silo accounts never end on their own, so "Access expires after (days)" is
  refused for Silo: use a subscription.
- "Allow downloads" and "Allow live TV" are Wizarr settings; in Silo, set them
  on the access group or account.
- **Requests on Silo** are Silo's own, not Seerr: the same `requests` option and
  request packages (see *Request packages*) switch them on per account with
  the package's limit (`5` = 5 requests a week, `10/30`, `unlimited`, `yes` =
  Silo's default limit). Without the option they are switched off, and while
  a subscription is suspended too. Changing package changes the limit. The
  client area shows the package. No Seerr fields are needed for Silo.
- Paywarr Stats shows Silo customers' statistics from Silo itself.
- Paywarr changes an account with Silo's edit guard (`If-Match`), so a change
  made at the same moment in Silo's admin is not overwritten; Paywarr reads the
  account again and retries once.

## What you need to know about Plex, Jellyfin and Emby

Plex is supported, but less tested than Jellyfin and Emby: the tests cover
it, a real Plex server hasn't run it yet. Feedback is very welcome.

They behave differently, and that is up to Wizarr, not the extension:

- **Jellyfin and Emby** can disable a user. Suspension is a pause: same user, same
  history when payment resumes.
- **Plex** has no "disabled" state. Wizarr removes the share, and it can't be
  turned back on. If the customer pays after a suspension, the extension
  deletes the old record and **emails a new invitation**.
- **If the media server is unreachable when Wizarr needs to disable, Wizarr
  deletes the user instead.** On Jellyfin that means lost history. The customer
  gets a new invitation at the next payment, so the access repairs itself.

[← Paywarr](../README.md)

## Silo through Tracearr

Tracearr 3.3 and newer can watch a Silo server. Add it in Tracearr, and Paywarr
notices by itself (checked every hour): Silo's users, plays and streams then
come from Tracearr like Jellyfin's, Emby's and Plex's, so stream limits, My
devices, At risk, Continue watching, "Because you watched", challenges, free
access and service status cover Silo users too, and Silo isn't asked for them
separately (nothing is counted twice). Paywarr Stats 1.12.0 does the same.
Without Silo in Tracearr, Paywarr asks Silo itself, as before. Tracearr matches
Silo users by their username, as for the other servers.
