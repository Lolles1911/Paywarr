#!/usr/bin/env python3
"""A stand-in for Wizarr's REST API, for testing the Paymenter extension.

Every behaviour here was copied from Wizarr's source (v2026.10,
app/blueprints/api/api_routes.py and app/services/media/*), including the
awkward ones the extension has to cope with:

* POST /api/invitations defaults "unlimited" to true and returns a relative url.
* "used_by" is documented as a username but is serialised as "<User 12>".
* /disable answers 200 and *deletes* the user when the media server cannot
  disable it (or cannot be reached).
* /enable answers 200 with "Enable failed or not supported" for Plex.
* Deleting a user clears used_by on the invitation but leaves it "used".
* POST /users/<id>/reset-password creates a token for any server type (the
  reset page is what fails later for Plex) and invalidates earlier tokens.

Endpoints under /_test/ are not part of Wizarr; they let the tests play the
customer (redeem a link) and break things on purpose.
"""

from __future__ import annotations

import json
import secrets
import sys
from datetime import datetime, timedelta, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

API_KEY = "stub-key"


def fresh_seerr(key: str, media: str) -> dict:
    """One Seerr: user 1 is the owner, as in Seerr."""
    return {"key": key, "csrf": False, "default_permissions": 32, "media": media,
            "users": [{"id": 1, "jellyfinUserId": "jf-owner", "permissions": 2}], "next_id": 2}


def fresh_state() -> dict:
    state = {
        "servers": [
            {"id": 1, "name": "Jellyfin", "server_type": "jellyfin", "verified": True, "offline": False, "external_url": "https://jellyfin.example.com"},
            {"id": 2, "name": "Plex", "server_type": "plex", "verified": True, "offline": False},
            {"id": 3, "name": "Unverified", "server_type": "emby", "verified": False, "offline": False},
            {"id": 4, "name": "Emby", "server_type": "emby", "verified": True, "offline": False, "external_url": "https://emby.example.com"},
        ],
        "libraries": [
            {"id": 10, "name": "Film", "server_id": 1, "server_name": "Jellyfin", "enabled": True},
            {"id": 11, "name": "Serier", "server_id": 1, "server_name": "Jellyfin", "enabled": True},
            {"id": 20, "name": "Movies", "server_id": 2, "server_name": "Plex", "enabled": True},
            {"id": 40, "name": "Film", "server_id": 4, "server_name": "Emby", "enabled": True},
        ],
        "invitations": [],
        "users": [],
        "reset_tokens": [],
        # Discord (served under /discord/api/v10, not part of Wizarr)
        # Seerr (served under /seerr/api/v1). User 1 is the owner, as in Seerr.
        # "media": the one media server type Seerr is connected to (Jellyfin or Emby).
        "seerr": {"key": "seerr-key", "csrf": False, "default_permissions": 32, "media": "jellyfin",
                  "users": [{"id": 1, "jellyfinUserId": "jf-owner", "permissions": 2}], "next_id": 2},
        "discord": {
            "token": "bot-token", "bot_id": "999", "guild": "100000000000000111", "members": ["999"], "down": False,
            # role id -> position; the bot's own role sits at 50
            "roles": {"100000000000000111": {"name": "@everyone", "position": 0}, "500": {"name": "Paid user", "position": 10},
                      "600": {"name": "Trial", "position": 9}, "700": {"name": "Admin", "position": 90},
                      "800": {"name": "WaffleBot", "position": 50, "managed": True}},
            "member_roles": {}, "manage_roles": True,
        },
        "next_invitation_id": 1,
        "next_user_id": 1,
        "used_by_mode": "repr",  # "repr" = what Wizarr does today, "username" = what it documents
        "fail_next": 0,
        "requests": [],
    }
    # More Seerr instances (served under /seerr2 and /seerr3), each with its own media server.
    state["seerr2"] = fresh_seerr("seerr2-key", "emby")
    state["seerr3"] = fresh_seerr("seerr3-key", "plex")
    # Tracearr (served under /tracearr/api/v2/public): identities with their accounts,
    # and per identity the numbers /users/:id/stats and /users/:id/history return.
    state["tracearr"] = {"key": "trr_pub_test", "identities": [], "stats": {}, "history": {}}
    return state


STATE = fresh_state()


def now() -> datetime:
    return datetime.now(timezone.utc)


def invitation_status(inv: dict) -> str:
    if inv["used"]:
        return "used"
    if inv["expires"] and datetime.fromisoformat(inv["expires"]) <= now():
        return "expired"
    return "pending"


def used_by(inv: dict):
    if inv["used_by_id"] is None:
        return None
    if STATE["used_by_mode"] == "repr":
        return f"<User {inv['used_by_id']}>"
    user = next((u for u in STATE["users"] if u["id"] == inv["used_by_id"]), None)
    return user["username"] if user else None


def server_by_id(server_id: int) -> dict | None:
    return next((s for s in STATE["servers"] if s["id"] == server_id), None)


def public_invitation(inv: dict) -> dict:
    return {
        "id": inv["id"],
        "code": inv["code"],
        "url": f"/j/{inv['code']}",
        "status": invitation_status(inv),
        "expires": inv["expires"],
        "used_by": used_by(inv),
        "duration": str(inv["duration"]) if inv["duration"] else "unlimited",
        "unlimited": inv["unlimited"],
        "specific_libraries": inv["library_ids"],
        "server_names": [server_by_id(i)["name"] for i in inv["server_ids"]],
    }


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, *args):  # keep test output readable
        pass

    def send_json(self, status: int, payload) -> None:
        body = json.dumps(payload).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def read_json(self) -> dict:
        length = int(self.headers.get("Content-Length") or 0)
        raw = self.rfile.read(length) if length else b""
        try:
            data = json.loads(raw or b"{}")
        except ValueError:
            data = {}
        return data if isinstance(data, dict) else {}

    def handle_any(self, method: str) -> None:
        global STATE
        path = self.path.split("?")[0].rstrip("/")
        body = self.read_json()

        if path.startswith("/_test/"):
            return self.handle_test(path, body)

        if path.startswith("/discord/api/v10/"):
            return self.handle_discord(method, path[len("/discord/api/v10"):])

        if path == "/tracearr/api/v1/images/proxy":
            # apps/server/src/routes/images.ts: no authentication; an image, whatever server it came from.
            from urllib.parse import parse_qs, urlparse
            url = parse_qs(urlparse(self.path).query).get("url", [""])[0]
            STATE["requests"].append({"method": method, "path": "tracearr-image:" + url, "body": {}})
            if "notimage" in url:
                return self.send_raw(200, "text/html", b"<html>login</html>")
            if "svg" in url:
                return self.send_raw(200, "image/svg+xml", b"<svg xmlns='http://www.w3.org/2000/svg'><script>alert(1)</script></svg>")
            return self.send_raw(200, "image/jpeg", b"\xff\xd8\xff\xe0JPEG:" + url.encode())

        if path.startswith("/tracearr/api/v2/public/"):
            return self.handle_tracearr(method, path[len("/tracearr/api/v2/public"):])

        for name in ("seerr2", "seerr3", "seerr"):
            if path.startswith(f"/{name}/api/v1/"):
                return self.handle_seerr(method, path[len(f"/{name}/api/v1"):], body, name)

        STATE["requests"].append({"method": method, "path": path, "body": body})

        if self.headers.get("X-API-Key") != API_KEY:
            return self.send_json(401, {"error": "Unauthorized"})

        if STATE["fail_next"] > 0:
            STATE["fail_next"] -= 1
            return self.send_json(503, {"error": "Service temporarily unavailable"})

        parts = path.strip("/").split("/")  # api, <ns>, ...

        if method == "GET" and path == "/api/status":
            return self.send_json(200, {"users": len(STATE["users"]), "invites": len(STATE["invitations"])})

        if method == "GET" and path == "/api/servers":
            servers = [{k: v for k, v in s.items() if k != "offline"} for s in STATE["servers"]]
            return self.send_json(200, {"servers": servers, "count": len(servers)})

        if method == "GET" and path == "/api/libraries":
            return self.send_json(200, {"libraries": STATE["libraries"], "count": len(STATE["libraries"])})

        if method == "GET" and path == "/api/invitations":
            rows = [public_invitation(i) for i in STATE["invitations"]]
            return self.send_json(200, {"invitations": rows, "count": len(rows)})

        if method == "POST" and path == "/api/invitations":
            return self.create_invitation(body)

        if method == "DELETE" and len(parts) == 3 and parts[1] == "invitations":
            inv = next((i for i in STATE["invitations"] if str(i["id"]) == parts[2]), None)
            if not inv:
                return self.send_json(404, {"error": "Invitation not found"})
            STATE["invitations"].remove(inv)
            return self.send_json(200, {"message": f"Invitation {inv['code']} deleted successfully"})

        if method == "GET" and path == "/api/users":
            rows = [
                {
                    "id": u["id"],
                    "username": u["username"],
                    "email": u["email"],
                    "server": server_by_id(u["server_id"])["name"],
                    "server_type": server_by_id(u["server_id"])["server_type"],
                    "expires": u["expires"],
                    "created_at": u.get("created_at"),
                }
                for u in STATE["users"]
            ]
            return self.send_json(200, {"users": rows, "count": len(rows)})

        if len(parts) >= 3 and parts[1] == "users":
            user = next((u for u in STATE["users"] if str(u["id"]) == parts[2]), None)
            if not user:
                return self.send_json(404, {"error": "User not found"})
            server = server_by_id(user["server_id"])

            if method == "DELETE" and len(parts) == 3:
                self.delete_user(user)
                return self.send_json(200, {"message": f"User {user['username']} deleted successfully"})

            if method == "POST" and len(parts) == 4 and parts[3] == "disable":
                # Jellyfin flips a policy flag; Plex removes the share. Both
                # report success. An unreachable server reports failure, and
                # Wizarr then falls back to deleting the user.
                if not server["offline"]:
                    user["is_disabled"] = True
                    if server["server_type"] == "plex":
                        user["plex_share_removed"] = True
                    return self.send_json(200, {"message": f"User {user['username']} disabled successfully"})
                self.delete_user(user)
                return self.send_json(
                    200, {"message": f"User {user['username']} deleted (disable not supported by server)"}
                )

            if method == "POST" and len(parts) == 4 and parts[3] == "reset-password":
                for token in STATE["reset_tokens"]:
                    if token["user_id"] == user["id"] and not token["used"]:
                        token["used"] = True  # one live link per user
                code = secrets.token_urlsafe(8)
                expires = (now() + timedelta(hours=24)).isoformat()
                STATE["reset_tokens"].append({"code": code, "user_id": user["id"], "used": False, "expires_at": expires})
                return self.send_json(
                    200,
                    {
                        "message": f"Password reset link created for {user['username']}",
                        "code": code,
                        "url": f"/reset/{code}",
                        "expires_at": expires,
                    },
                )

            if method == "POST" and len(parts) == 4 and parts[3] == "enable":
                if server["server_type"] != "plex" and not server["offline"]:
                    user["is_disabled"] = False
                    return self.send_json(200, {"message": f"User {user['username']} enabled successfully"})
                return self.send_json(
                    200, {"message": f"Enable failed or not supported for user {user['username']}"}
                )

        return self.send_json(404, {"error": f"no route for {method} {path}"})

    def handle_seerr(self, method: str, path: str, body: dict, name: str = "seerr") -> None:
        """Seerr v3.5.1's answers for the calls the add-on makes."""
        st = STATE[name]
        STATE["requests"].append({"method": method, "path": name + ":" + path, "body": body})
        if st["csrf"] and method != "GET":
            return self.send_json(403, {"message": "invalid csrf token"})
        if self.headers.get("X-Api-Key") != st["key"]:
            return self.send_json(403, {"status": 403, "error": "You do not have permission to access this endpoint"})
        parts = path.strip("/").split("/")
        users = st["users"]
        find = lambda uid: next((u for u in users if u["id"] == uid), None)

        if method == "GET" and path == "/status":
            return self.send_json(200, {"version": "3.5.1"})
        if method == "GET" and path == "/settings/main":
            # server/constants/server.ts: PLEX = 1, JELLYFIN = 2, EMBY = 3
            return self.send_json(200, {"mediaServerType": {"plex": 1, "jellyfin": 2, "emby": 3}.get(st["media"], 4), "applicationTitle": "Seerr"})
        # Plex: server/routes/settings/index.ts GET /plex/users lists the Plex users who have
        # access (Wizarr shared the library) and no Seerr account yet; /user/import-from-plex
        # creates accounts for the plexIds given.
        plex_id = lambda u: str(1000 + u["id"])
        has_account = lambda u: any(su.get("plexId") == int(plex_id(u)) or (su.get("email") or "").lower() == (u.get("email") or "").lower() for su in users)
        plex_users = lambda: [u for u in STATE["users"] if server_by_id(u["server_id"])["server_type"] == "plex" and u.get("email")]
        if method == "GET" and path == "/settings/plex/users":
            if st["media"] != "plex":
                return self.send_json(500, {"message": "Unable to retrieve unimported Plex users."})
            return self.send_json(200, [{"id": plex_id(u), "title": u["username"], "username": u["username"], "email": u["email"], "thumb": ""}
                                        for u in plex_users() if not has_account(u)])
        if method == "POST" and path == "/user/import-from-plex":
            created = []
            for u in plex_users():
                if plex_id(u) in body.get("plexIds", []) and not has_account(u):
                    new = {"id": st["next_id"], "plexId": int(plex_id(u)), "permissions": st["default_permissions"],
                           "username": u["username"], "email": u["email"], "settings": {},
                           "movieQuotaLimit": None, "movieQuotaDays": None, "tvQuotaLimit": None, "tvQuotaDays": None}
                    st["next_id"] += 1
                    users.append(new)
                    created.append(new)
            return self.send_json(201, {"createdUsers": created, "refreshedUsers": 0})
        if method == "GET" and path == "/settings/jellyfin/users":
            jf = [{"id": f"jf-{u['username']}", "username": u["username"]} for u in STATE["users"] if server_by_id(u["server_id"])["server_type"] == st["media"]]
            return self.send_json(200, [{"id": "jf-owner", "username": "owner"}] + jf)
        if method == "GET" and len(parts) == 3 and parts[:2] == ["user", "jellyfin"]:
            u = next((u for u in users if u["jellyfinUserId"] == parts[2]), None)
            return self.send_json(200, u) if u else self.send_json(404, {"message": "User not found."})
        if method == "POST" and path == "/user/import-from-jellyfin":
            created = []
            for jid in body.get("jellyfinUserIds", []):
                if not any(u["jellyfinUserId"] == jid for u in users):
                    name = jid[3:] if jid.startswith("jf-") else jid
                    u = {"id": st["next_id"], "jellyfinUserId": jid, "permissions": st["default_permissions"],
                         "username": name, "email": name + "@example.com", "jellyfinUsername": name,
                         # what a customer may have set themselves in Seerr
                         "settings": {"locale": "da", "discoverRegion": "DK", "streamingRegion": "DK", "originalLanguage": "da",
                                      "watchlistSyncMovies": True, "watchlistSyncTv": False},
                         "movieQuotaLimit": None, "movieQuotaDays": None, "tvQuotaLimit": None, "tvQuotaDays": None}
                    st["next_id"] += 1
                    users.append(u)
                    created.append(u)
            return self.send_json(201, created)
        if len(parts) >= 2 and parts[0] == "user" and parts[1].isdigit():
            u = find(int(parts[1]))
            if not u:
                return self.send_json(404, {"message": "User not found."})
            if method == "GET" and len(parts) == 2:
                return self.send_json(200, u)
            if method == "DELETE" and len(parts) == 2:
                if u["id"] == 1:
                    return self.send_json(405, {"message": "This account cannot be deleted."})
                users.remove(u)
                return self.send_json(200, u)
            if parts[2:] == ["settings", "main"]:
                # server/routes/user/usersettings.ts: GET and POST /main
                settings = u.setdefault("settings", {})
                if method == "GET":
                    return self.send_json(200, {
                        "username": u.get("username"), "email": u.get("email"),
                        **{k: settings.get(k) for k in ("locale", "discoverRegion", "streamingRegion", "originalLanguage")},
                        **{k: u.get(k) for k in ("movieQuotaLimit", "movieQuotaDays", "tvQuotaLimit", "tvQuotaDays")},
                        "globalMovieQuotaDays": 7, "globalMovieQuotaLimit": 3, "globalTvQuotaDays": 7, "globalTvQuotaLimit": 1,
                        **{k: settings.get(k) for k in ("watchlistSyncMovies", "watchlistSyncTv")},
                    })
                if u["id"] == 1:
                    return self.send_json(403, {"message": "You do not have permission to modify this user's settings."})
                # Everything is taken from the body; what is not sent is emptied.
                u["username"] = body.get("username")
                u["email"] = body.get("email") or u.get("jellyfinUsername") or u.get("email")
                # Quotas only change for users without Manage Users (8) or Admin (2).
                if not (u["permissions"] & (2 | 8)):
                    for k in ("movieQuotaDays", "movieQuotaLimit", "tvQuotaDays", "tvQuotaLimit"):
                        u[k] = body.get(k)
                for k in ("locale", "discoverRegion", "streamingRegion", "originalLanguage", "watchlistSyncMovies", "watchlistSyncTv"):
                    settings[k] = body.get(k)
                return self.send_json(200, {"username": u["username"], "email": u["email"]})
            if parts[2:] == ["settings", "permissions"]:
                if method == "GET":
                    return self.send_json(200, {"permissions": u["permissions"]})
                if u["id"] == 1:
                    return self.send_json(403, {"message": "You do not have permission to modify this user"})
                u["permissions"] = int(body["permissions"])
                return self.send_json(200, {"permissions": u["permissions"]})
        return self.send_json(404, {"message": "not found"})

    def send_raw(self, status: int, content_type: str, body: bytes) -> None:
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def handle_tracearr(self, method: str, path: str) -> None:
        """Tracearr's public API v2, as apps/server/src/routes/publicV2 answers it."""
        from urllib.parse import parse_qs, urlparse
        tr = STATE["tracearr"]
        query = {k: v[0] for k, v in parse_qs(urlparse(self.path).query).items()}
        STATE["requests"].append({"method": method, "path": "tracearr:" + path, "body": query})
        auth = self.headers.get("Authorization") or ""
        if not auth.startswith("Bearer "):
            return self.send_json(401, {"message": "Missing or invalid Authorization header"})
        if auth[7:] != tr["key"]:
            return self.send_json(401, {"message": "Invalid API key"})
        parts = path.strip("/").split("/")
        page_size = int(query.get("pageSize", 25))
        if method == "GET" and path == "/users":
            start = int(query.get("cursor", "0") or 0)
            page = tr["identities"][start:start + page_size]
            nxt = str(start + page_size) if start + page_size < len(tr["identities"]) else None
            return self.send_json(200, {"data": page, "meta": {"nextCursor": nxt, "pageSize": page_size}})
        if method == "GET" and len(parts) == 3 and parts[0] == "users" and parts[2] == "stats":
            empty = {"all_time": {"plays": 0, "watch_time_ms": 0}, "last_30": {"plays": 0, "watch_time_ms": 0}, "last_7": {"plays": 0, "watch_time_ms": 0}}
            st = tr["stats"].get(parts[1], {})
            return self.send_json(200, {"user_id": parts[1], "windows": st.get("windows", empty), "top_genres": st.get("top_genres", [])})
        if method == "GET" and len(parts) == 3 and parts[0] == "users" and parts[2] == "history":
            return self.send_json(200, {"data": tr["history"].get(parts[1], [])[:page_size], "meta": {"nextCursor": None, "pageSize": page_size}})
        return self.send_json(404, {"message": "Not found"})

    def handle_discord(self, method: str, path: str) -> None:
        """Discord's REST answers for the two calls the extension makes."""
        d = STATE["discord"]
        STATE["requests"].append({"method": method, "path": "discord:" + path, "body": {}})
        if d["down"]:
            return self.send_json(502, {"message": "Bad Gateway"})
        if self.headers.get("Authorization") != f"Bot {d['token']}":
            return self.send_json(401, {"message": "401: Unauthorized", "code": 0})
        if method == "GET" and path == "/users/@me":
            return self.send_json(200, {"id": d["bot_id"], "username": "WaffleBot", "bot": True})
        parts = path.strip("/").split("/")
        if method == "GET" and len(parts) == 3 and parts[0] == "guilds" and parts[2] == "roles":
            if parts[1] != d["guild"]:
                return self.send_json(404, {"message": "Unknown Guild", "code": 10004})
            return self.send_json(200, [{"id": rid, **r} for rid, r in d["roles"].items()])
        if method in ("PUT", "DELETE") and len(parts) == 6 and parts[0] == "guilds" and parts[2] == "members" and parts[4] == "roles":
            guild, user, role = parts[1], parts[3], parts[5]
            if guild != d["guild"]:
                return self.send_json(404, {"message": "Unknown Guild", "code": 10004})
            if user not in d["members"]:
                return self.send_json(404, {"message": "Unknown Member", "code": 10007})
            if role not in d["roles"]:
                return self.send_json(404, {"message": "Unknown Role", "code": 10011})
            # Without Manage Roles Discord answered 50001 on a live server; above the
            # bot's highest role it is 50013.
            if not d["manage_roles"]:
                return self.send_json(403, {"message": "Missing Access", "code": 50001})
            if d["roles"][role]["position"] >= 50:
                return self.send_json(403, {"message": "Missing Permissions", "code": 50013})
            roles = d["member_roles"].setdefault(user, [])
            if method == "PUT" and role not in roles:
                roles.append(role)
            if method == "DELETE" and role in roles:
                roles.remove(role)
            self.send_response(204)
            self.send_header("Content-Length", "0")
            self.end_headers()
            return None
        if method == "GET" and len(parts) == 4 and parts[0] == "guilds" and parts[2] == "members":
            guild, user = parts[1], parts[3]
            if guild != d["guild"]:
                return self.send_json(404, {"message": "Unknown Guild", "code": 10004})
            if d["bot_id"] not in d["members"]:
                return self.send_json(403, {"message": "Missing Access", "code": 50001})
            if user in d["members"]:
                return self.send_json(200, {"user": {"id": user}, "roles": d["member_roles"].get(user, [])})
            return self.send_json(404, {"message": "Unknown Member", "code": 10007})
        return self.send_json(404, {"message": "404: Not Found", "code": 0})

    def delete_user(self, user: dict) -> None:
        STATE["users"].remove(user)
        for inv in STATE["invitations"]:
            if inv["used_by_id"] == user["id"]:
                inv["used_by_id"] = None  # ON DELETE SET NULL; "used" stays true

    def create_invitation(self, data: dict) -> None:
        server_ids = data.get("server_ids")
        if not server_ids:
            return self.send_json(
                400, {"error": "Server selection is required. Please specify server_ids in request."}
            )
        valid = {s["id"] for s in STATE["servers"] if s["verified"]}
        invalid = [sid for sid in server_ids if sid not in valid]
        if invalid:
            return self.send_json(400, {"error": f"Server IDs {invalid} not found or not verified"})

        days = {1: 1, 7: 7, 30: 30}.get(data.get("expires_in_days"))  # anything else: never
        duration = data.get("duration", "unlimited")
        inv = {
            "id": STATE["next_invitation_id"],
            "code": secrets.token_hex(4).upper(),
            "server_ids": list(server_ids),
            "library_ids": [int(x) for x in data.get("library_ids", [])],
            "expires": (now() + timedelta(days=days)).isoformat() if days else None,
            "expires_in_days": days,
            "duration": None if duration in ("unlimited", "", None) else duration,
            "unlimited": bool(data.get("unlimited", True)),  # Wizarr's default really is True
            "allow_downloads": bool(data.get("allow_downloads", False)),
            "allow_live_tv": bool(data.get("allow_live_tv", False)),
            "used": False,
            "used_by_id": None,
        }
        STATE["next_invitation_id"] += 1
        STATE["invitations"].append(inv)
        self.send_json(201, {"message": "Invitation created successfully", "invitation": public_invitation(inv)})

    # -- test controls (not Wizarr) -------------------------------------

    def handle_test(self, path: str, body: dict) -> None:
        global STATE

        if path == "/_test/reset":
            STATE = fresh_state()
            return self.send_json(200, {"ok": True})

        if path == "/_test/state":
            return self.send_json(200, STATE)

        if path == "/_test/set":
            for key in ("used_by_mode", "fail_next"):
                if key in body:
                    STATE[key] = body[key]
            if "offline" in body:
                server_by_id(body["server_id"])["offline"] = body["offline"]
            for server_id, fields in body.get("servers", {}).items():
                server_by_id(int(server_id)).update(fields)
            if "next_user_id" in body:
                STATE["next_user_id"] = body["next_user_id"]
            if "discord" in body:
                STATE["discord"].update(body["discord"])
            for name in ("seerr", "seerr2", "seerr3", "tracearr"):
                if name in body:
                    STATE[name].update(body[name])
            return self.send_json(200, {"ok": True})

        if path == "/_test/set-user":
            user = next(u for u in STATE["users"] if u["id"] == body["id"])
            user.update({k: v for k, v in body.items() if k != "id"})
            return self.send_json(200, user)

        if path == "/_test/clear-requests":
            STATE["requests"] = []
            return self.send_json(200, {"ok": True})

        if path == "/_test/add-user":
            user = {
                "id": STATE["next_user_id"],
                "username": body["username"],
                "email": body.get("email"),
                "server_id": body.get("server_id", 1),
                "expires": None,
                "is_disabled": False,
                "created_at": now().isoformat(),
            }
            STATE["next_user_id"] += 1
            STATE["users"].append(user)
            return self.send_json(200, user)

        if path == "/_test/expire":
            inv = next(i for i in STATE["invitations"] if i["code"] == body["code"])
            inv["expires"] = (now() - timedelta(hours=1)).isoformat()
            return self.send_json(200, {"ok": True})

        if path == "/_test/redeem":
            inv = next((i for i in STATE["invitations"] if i["code"] == body["code"]), None)
            if not inv:
                return self.send_json(404, {"error": "no such invitation"})
            if invitation_status(inv) != "pending":
                return self.send_json(409, {"error": f"invitation is {invitation_status(inv)}"})
            created = []
            for server_id in inv["server_ids"]:
                user = {
                    "id": STATE["next_user_id"],
                    "username": body["username"],
                    "email": body.get("email"),
                    "server_id": server_id,
                    "expires": (now() + timedelta(days=int(inv["duration"]))).isoformat()
                    if inv["duration"]
                    else None,
                    "is_disabled": False,
                    "created_at": body.get("created_at") or now().isoformat(),
                }
                STATE["next_user_id"] += 1
                STATE["users"].append(user)
                created.append(user)
            if inv["used_by_id"] is None:
                inv["used_by_id"] = created[0]["id"]
            if not inv["unlimited"]:
                inv["used"] = True
            return self.send_json(200, {"users": created})

        self.send_json(404, {"error": "unknown test endpoint"})

    def do_GET(self):
        self.handle_any("GET")

    def do_POST(self):
        self.handle_any("POST")

    def do_DELETE(self):
        self.handle_any("DELETE")

    def do_PUT(self):
        self.handle_any("PUT")


if __name__ == "__main__":
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 8766
    ThreadingHTTPServer(("127.0.0.1", port), Handler).serve_forever()
