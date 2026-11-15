"""A stand-in for Silo's native API v2, served by stub_wizarr.py under /silo.

Written from Silo's own contract (contracts/api/v2/openapi.json, its
fixtures, docs/invitations-api.md, docs/admin-users-api.md,
docs/api-keys-api.md and internal/api/middleware/api_key_scopes.go,
November 2026), including the parts the extension has to get right:

* "Authorization: Bearer sa_..."; a key scoped to admin:users gets 403 on
  invitations, libraries and password reset links.
* IDs are JSON strings; lists are cursor-paged ({items, page}).
* Creating an invitation: unknown members, null, an email with
  delivery=link and unknown library IDs are refused (422). The link
  (claim_url) is in the creation answer only; lists never show it.
* Revoking: 204, again 204; an accepted invitation 409.
* Account PUT/DELETE need If-Match with the current ETag: 428 without, 412
  when stale. An accepted invitation carries accepted_user_id.
* A password reset link is refused (409) for a disabled account.

Endpoints under /_test/silo-* play the invitee and the administrator, and
make an account change between Paywarr's read and write.
"""

from __future__ import annotations

import secrets
from datetime import datetime, timedelta, timezone

KEY = "sa_full"
SCOPED_KEY = "sa_users_only"  # admin:users only
PUBLIC = "https://silo.example.com"

INVITATION_FIELDS = {"delivery", "email", "role", "access_group_id", "library_ids", "create_profile", "show_tour", "note"}
ACCOUNT_FIELDS = {"username", "email", "enabled", "password", "role", "library_ids", "requests_allowed"}
LIMIT_FIELDS = {"limit_mode", "max_requests", "window_days", "approval_mode"}


def now() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%S.") + f"{datetime.now(timezone.utc).microsecond // 1000:03d}Z"


def fresh() -> dict:
    return {
        "libraries": [{"id": "1", "name": "Movies", "type": "movies"}, {"id": "2", "name": "Shows", "type": "series"}],
        "invitations": [],
        "accounts": [{"id": "1", "username": "owner", "email": "owner@example.com", "role": "admin", "enabled": True, "password_login": True, "rev": 1, "is_owner": True}],
        "reset_links": [],
        "profiles": [{"id": "p-owner", "is_primary": True, "has_pin": False}],
        # The request side (TMDB titles), as /api/v2/requests/* answer them.
        "request_catalog": [
            {"media_type": "movie", "tmdb_id": 949, "title": "Heat", "year": 1995, "poster_path": "/heat.jpg", "availability": "available"},
            {"media_type": "movie", "tmdb_id": 603, "title": "The Matrix", "year": 1999, "poster_path": "/matrix.jpg", "availability": "missing"},
            {"media_type": "series", "tmdb_id": 1399, "title": "Dragon Throne", "year": 2011, "poster_path": "/dt.jpg", "availability": "missing",
             "seasons": [{"season_number": 1, "name": "Season 1", "episode_count": 10, "availability": "available"},
                         {"season_number": 2, "name": "Season 2", "episode_count": 10, "availability": "missing"}]},
        ],
        "media_requests": [],
        "sessions": {},
        "catalog": [
            {"content_id": "movie:heat-1995", "type": "movie", "title": "Heat", "year": 1995, "poster_url": "/img/heat.jpg"},
            {"content_id": "series:heat-house", "type": "series", "title": "Heat House", "year": 2024, "series_poster_url": "/img/heat-house.jpg"},
            {"content_id": "episode:heat-1", "type": "episode", "title": "Heatwave", "year": 2024},
        ],
        "next_invitation": 1,
        "next_account": 2,
        "requests": [],
    }


def problem(status: int, title: str, detail: str = "") -> tuple[int, dict]:
    return status, {"type": "about:blank", "title": title, "status": status, "detail": detail or title}


def public_invitation(inv: dict) -> dict:
    row = {k: inv[k] for k in ("id", "email", "delivery", "role", "library_ids", "create_profile", "show_tour", "note",
                                "invited_by", "invited_by_name", "status", "expires_at", "created_at")}
    if inv.get("accepted_user_id"):
        row["accepted_user_id"] = inv["accepted_user_id"]
        row["accepted_at"] = inv["accepted_at"]
    return row


def public_account(acc: dict) -> dict:
    return {"id": acc["id"], "username": acc["username"], "email": acc["email"], "role": acc["role"], "permissions": [],
            "enabled": acc["enabled"], "library_ids": acc.get("library_ids"), "password_login": acc["password_login"],
            "password_change_required": False, "is_owner": acc.get("is_owner", False), "break_glass": False,
            "access_group_id": None, "requests_allowed": acc.get("requests_allowed"),
            "created_at": "2026-11-01T00:00:00.000Z", "updated_at": now(), "last_active_at": None}


def etag(acc: dict) -> str:
    return f'"acct-{acc["id"]}-{acc["rev"]}"'


def page(items: list, query: dict) -> dict:
    limit = int(query.get("limit", 50))
    start = int(query.get("cursor", "0") or 0)
    chunk = items[start:start + limit]
    more = start + limit < len(items)
    out = {"items": chunk, "page": {"has_more": more}}
    if more:
        out["page"]["next_cursor"] = str(start + limit)
    return out


def handle(state: dict, method: str, path: str, query: dict, body, headers) -> tuple[int, object, dict]:
    """Returns (status, json body or None, extra headers)."""
    silo = state["silo"]
    silo["requests"].append({"method": method, "path": path, "query": query, "body": body,
                             "if_match": headers.get("If-Match")})

    auth = headers.get("Authorization") or ""
    session = silo["sessions"].get(auth[7:]) if auth.startswith("Bearer imp-") else None
    if session is not None:
        return as_account(silo, session, auth[7:], method, path, body, headers)
    if auth not in (f"Bearer {KEY}", f"Bearer {SCOPED_KEY}"):
        return (*problem(401, "authentication_required"), {})
    scoped = auth == f"Bearer {SCOPED_KEY}"
    users_route = path == "/api/v2/admin/users" or (path.startswith("/api/v2/admin/users/") and path.split("/")[-1].isdigit())
    if scoped and not users_route:
        return (*problem(403, "permission_denied", "The API key's scopes do not cover this route."), {})

    if state.get("fail_next", 0) > 0:
        state["fail_next"] -= 1
        return (*problem(503, "unavailable"), {})

    parts = path.strip("/").split("/")  # api v2 ...

    if method == "GET" and path.startswith("/img/"):
        return 200, {"image": path}, {"Content-Type": "image/jpeg"}
    if method == "POST" and len(parts) == 6 and parts[2:4] == ["admin", "users"] and parts[5] == "impersonate":
        # As Silo: an API key may never act as another account (auth.StartImpersonation).
        return (*problem(403, "permission_denied", "Impersonation is not allowed."), {})
    if method == "GET" and len(parts) == 6 and parts[2:4] == ["admin", "request-users"] and parts[5] == "usage":
        acc = next((a for a in silo["accounts"] if a["id"] == parts[4]), None)
        if not acc:
            return (*problem(404, "not_found"), {})
        limit = acc.get("request_limit") or {"limit_mode": "inherit"}
        used = len([r for r in silo["media_requests"] if r["requested_by_user_id"] == acc["id"]])
        unlimited = limit["limit_mode"] == "unlimited"
        maximum = limit.get("max_requests") if limit["limit_mode"] == "custom" else 5
        return 200, {"allowed": acc.get("requests_allowed") is not False, "auto_approve": False, "unlimited": unlimited, "max_requests": maximum or 0,
                     "used": 0 if unlimited else used, "remaining": 0 if unlimited else max(0, (maximum or 0) - used),
                     "window_days": limit.get("window_days") or 7, "window_start": "2026-11-08T00:00:00.000Z", "requests_enabled": True}, {}
    if method == "GET" and path.startswith("/api/v2/requests/") and headers.get("X-Profile-Id") != "p-owner":
        return (*problem(400, "profile_required"), {})
    if method == "GET" and path == "/api/v2/requests/search":
        q = query.get("q", "").lower()
        return 200, {"page": 1, "total_pages": 1, "total_results": 0, "results": [request_result(silo, r) for r in silo["request_catalog"] if q in r["title"].lower()]}, {}
    if method == "GET" and path.startswith("/api/v2/requests/discover/"):
        section = parts[4]
        if section not in ("trending_movies", "trending_series", "popular_movies", "popular_series", "upcoming_movies", "on_air_series"):
            return (*problem(422, "validation_failed"), {})
        kind = "series" if "series" in section else "movie"
        rows = [request_result(silo, r) for r in silo["request_catalog"] if r["media_type"] == kind]
        return 200, {"key": section, "title": section, "page": 1, "total_pages": 1, "total_results": len(rows), "results": rows}, {}
    if method == "GET" and len(parts) == 6 and parts[2:4] == ["requests", "detail"]:
        hit = next((r for r in silo["request_catalog"] if r["media_type"] == parts[4] and str(r["tmdb_id"]) == parts[5]), None)
        if not hit:
            return (*problem(404, "not_found"), {})
        requested = {n for x in silo["media_requests"] if x["tmdb_id"] == hit["tmdb_id"] for n in x["seasons"]}
        return 200, {**request_result(silo, hit), "seasons": [{**season, "requested": season["season_number"] in requested} for season in hit.get("seasons", [])],
                     "genres": [], "cast": [], "creators": [], "networks": [], "production_companies": [], "recommendations": []}, {}
    if method == "GET" and path == "/api/v2/admin/requests":
        rows = [r for r in reversed(silo["media_requests"]) if "requested_by_user_id" not in query or r["requested_by_user_id"] == query["requested_by_user_id"]]
        return 200, {"items": rows[: int(query.get("limit", 50))], "page": {"has_more": False}}, {}

    if method == "GET" and path == "/api/v2/profiles":
        return 200, {"items": silo["profiles"]}, {}
    if method == "GET" and (path == "/api/v2/catalog" or path.startswith("/api/v2/catalog/items/")):
        if headers.get("X-Profile-Id") != "p-owner":
            return (*problem(400, "profile_required"), {})
        if path == "/api/v2/catalog":
            q = query.get("q", "").lower()
            rows = [i for i in silo["catalog"] if q in i["title"].lower() and (query.get("type") != "video" or i["type"] in ("movie", "series"))]
            return 200, {"items": rows[: int(query.get("limit", 50))], "page": {"has_more": False}}, {}
        from urllib.parse import unquote
        item = next((i for i in silo["catalog"] if i["content_id"] == unquote(parts[4])), None)
        return (200, {"genres": [], **item}, {}) if item else (*problem(404, "not_found"), {})

    if method == "GET" and path == "/api/v2/admin/invitations/capabilities":
        return 200, {"revision": "1", "state": "available", "default_profile": True, "profileless": True, "email_delivery": False}, {}

    if method == "GET" and path == "/api/v2/libraries":
        return 200, {"items": silo["libraries"]}, {}

    if method == "GET" and path == "/api/v2/admin/invitations":
        rows = [public_invitation(i) for i in sorted(silo["invitations"], key=lambda i: int(i["id"]), reverse=True)]
        return 200, page(rows, query), {}

    if method == "POST" and path == "/api/v2/admin/invitations":
        if not isinstance(body, dict) or set(body) - INVITATION_FIELDS:
            return (*problem(422, "validation_failed", "unknown member"), {})
        if any(v is None for v in body.values()):
            return (*problem(422, "validation_failed", "omit optional members instead of null"), {})
        if body.get("delivery") == "link" and "email" in body:
            return (*problem(422, "validation_failed", "email is forbidden with delivery=link"), {})
        if body.get("delivery") != "link":
            return (*problem(422, "validation_failed", "this stub only knows link invitations"), {})
        libraries = body.get("library_ids")
        if libraries is not None:
            known = {lib["id"] for lib in silo["libraries"]}
            if not all(isinstance(x, str) and x in known for x in libraries):
                return (*problem(422, "validation_failed", "body.library_ids"), {})
        inv = {
            "id": str(silo["next_invitation"]), "email": "", "delivery": "link", "role": body.get("role", "user"),
            "library_ids": libraries, "create_profile": body.get("create_profile", True), "show_tour": body.get("show_tour", True),
            "note": body.get("note", ""), "invited_by": "1", "invited_by_name": "owner", "status": "pending",
            "expires_at": (datetime.now(timezone.utc) + timedelta(days=7)).strftime("%Y-%m-%dT%H:%M:%S.000Z"),
            "created_at": now(), "token": secrets.token_urlsafe(12),
        }
        silo["next_invitation"] += 1
        silo["invitations"].append(inv)
        return 201, {"invitation": public_invitation(inv), "claim_url": f"{PUBLIC}/invite#{inv['token']}",
                     "delivery_status": "not_requested"}, {"Location": f"/api/v2/admin/invitations/{inv['id']}"}

    if method == "DELETE" and len(parts) == 5 and parts[2:4] == ["admin", "invitations"]:
        inv = next((i for i in silo["invitations"] if i["id"] == parts[4]), None)
        if not inv:
            return (*problem(404, "not_found"), {})
        if inv["status"] == "accepted":
            return (*problem(409, "conflict", "an accepted invitation cannot be revoked"), {})
        inv["status"] = "revoked"
        return 204, None, {}

    if method == "GET" and path == "/api/v2/admin/users":
        rows = [public_account(a) for a in silo["accounts"]]
        if "identity" in query:
            wanted = query["identity"].strip().lower()
            rows = [r for r in rows if wanted in (r["username"].lower(), r["email"].lower())]
        return 200, page(rows, query), {}

    if len(parts) == 6 and parts[2:4] == ["admin", "request-users"] and parts[4].isdigit() and parts[5] == "limit":
        acc = next((a for a in silo["accounts"] if a["id"] == parts[4]), None)
        if not acc:
            return (*problem(404, "not_found"), {})
        limit = acc.setdefault("request_limit", {"limit_mode": "inherit", "max_requests": None, "window_days": None, "approval_mode": "inherit", "rev": 1})
        tag = f'"limit-{acc["id"]}-{limit["rev"]}"'
        public = {"user_id": acc["id"], **{k: limit[k] for k in LIMIT_FIELDS}}
        if method == "GET":
            return 200, public, {"ETag": tag}
        if method == "PUT":
            guard = headers.get("If-Match")
            if not guard:
                return (*problem(428, "precondition_required"), {})
            if guard != tag:
                return (*problem(412, "precondition_failed"), {"ETag": tag})
            if not isinstance(body, dict) or set(body) != LIMIT_FIELDS:
                return (*problem(422, "validation_failed", "all four members are required"), {})
            if body["limit_mode"] == "custom" and (not isinstance(body["max_requests"], int) or not isinstance(body["window_days"], int) or body["window_days"] < 1):
                return (*problem(422, "validation_failed", "custom needs max_requests and window_days"), {})
            limit.update({k: body[k] for k in LIMIT_FIELDS}, rev=limit["rev"] + 1)
            return 200, {"user_id": acc["id"], **{k: limit[k] for k in LIMIT_FIELDS}}, {"ETag": f'"limit-{acc["id"]}-{limit["rev"]}"'}

    if len(parts) >= 5 and parts[2:4] == ["admin", "users"] and parts[4].isdigit():
        acc = next((a for a in silo["accounts"] if a["id"] == parts[4]), None)
        if not acc:
            return (*problem(404, "not_found"), {})

        if len(parts) == 6 and parts[5] == "password-reset" and method == "POST":
            if not isinstance(body, dict) or body.get("delivery") not in ("link", "email") or set(body) - {"delivery"}:
                return (*problem(422, "validation_failed"), {})
            if not acc["enabled"] or not acc["password_login"]:
                return (*problem(409, "conflict", "account disabled or without password sign-in"), {})
            token = secrets.token_urlsafe(12)
            silo["reset_links"] = [r for r in silo["reset_links"] if r["account"] != acc["id"]]
            silo["reset_links"].append({"account": acc["id"], "token": token})
            return 201, {"delivery": "link", "delivery_status": "not_requested",
                         "reset_url": f"{PUBLIC}/reset-password#{token}", "expires_at": "2099-01-01T00:00:00.000Z"}, {}

        if len(parts) != 5:
            return (*problem(404, "not_found"), {})
        if method == "GET":
            answer = (200, public_account(acc), {"ETag": etag(acc)})
            if silo.get("stale_next", 0) > 0:  # someone else edits it right after this read
                silo["stale_next"] -= 1
                acc["rev"] += 1
            return answer
        if method in ("PUT", "DELETE"):
            guard = headers.get("If-Match")
            if not guard:
                return (*problem(428, "precondition_required"), {})
            if guard != etag(acc):
                return (*problem(412, "precondition_failed"), {"ETag": etag(acc)})
            if acc["role"] == "admin" and scoped:
                return (*problem(403, "permission_denied"), {})
            if method == "DELETE":
                silo["accounts"].remove(acc)
                return 204, None, {}
            if not isinstance(body, dict) or set(body) - ACCOUNT_FIELDS or any(v is None for k, v in body.items() if k != "library_ids"):
                return (*problem(422, "validation_failed"), {})
            if "enabled" in body:
                acc["enabled"] = bool(body["enabled"])
            if "requests_allowed" in body:
                acc["requests_allowed"] = body["requests_allowed"]
            acc["rev"] += 1
            return 204, None, {}

    return (*problem(404, "not_found"), {})


def request_result(silo: dict, entry: dict) -> dict:
    """A request-side title with the state of its active request, if any."""
    active = next((r for r in silo["media_requests"] if r["tmdb_id"] == entry["tmdb_id"] and r["outcome"] == "active"), None)
    out = {k: v for k, v in entry.items() if k != "seasons"}
    out["request"] = {"requestable": active is None and entry["availability"] != "available", "following": False, "requested_by_viewer": False,
                      **({"request_id": active["id"], "state": active["state"], "status": active["status"]} if active else {})}
    out["in_watchlist"] = False
    return out


def as_account(silo: dict, session: dict, token: str, method: str, path: str, body, headers) -> tuple[int, object, dict]:
    """What an impersonation session may do here: its profiles, a request, end."""
    if session["ended"]:
        return (*problem(401, "authentication_required"), {})
    acc = next((a for a in silo["accounts"] if a["id"] == session["account"]), None)
    if method == "GET" and path == "/api/v2/profiles":
        return 200, {"items": [{"id": "p-" + acc["id"], "is_primary": True, "has_pin": False}]}, {}
    if method == "POST" and path == "/api/v2/auth/impersonation/end":
        session["ended"] = True
        return 204, None, {}
    if method == "POST" and path == "/api/v2/requests":
        if headers.get("X-Profile-Id") != "p-" + acc["id"]:
            return (*problem(403, "permission_denied", "profile"), {})
        if not isinstance(body, dict) or not {"media_type", "tmdb_id", "title"} <= set(body):
            return (*problem(422, "validation_failed"), {})
        if acc.get("requests_allowed") is False:
            return (*problem(403, "permission_denied", "requests are off"), {})
        limit = acc.get("request_limit") or {"limit_mode": "inherit"}
        used = len([r for r in silo["media_requests"] if r["requested_by_user_id"] == acc["id"]])
        maximum = limit.get("max_requests") if limit["limit_mode"] == "custom" else (None if limit["limit_mode"] == "unlimited" else 5)
        if maximum is not None and used >= maximum:
            return (*problem(403, "quota_exceeded"), {})
        if any(r["tmdb_id"] == body["tmdb_id"] and r["outcome"] == "active" and not body.get("seasons") for r in silo["media_requests"]):
            return (*problem(409, "conflict", "already requested"), {})
        r = {"id": f"r{len(silo['media_requests']) + 1}", "provider": "tmdb", "media_type": body["media_type"], "tmdb_id": body["tmdb_id"], "title": body["title"],
             "year": body.get("year"), "poster_path": body.get("poster_path"), "status": "pending", "outcome": "active", "state": "pending",
             "seasons": body.get("seasons", []), "season_progress": [], "source": "direct", "requested_by_user_id": acc["id"],
             "requested_by_profile_id": headers.get("X-Profile-Id"), "is_anime": False, "targets": [], "created_at": "2026-11-15T10:00:00.000Z", "updated_at": "2026-11-15T10:00:00.000Z"}
        silo["media_requests"].append(r)
        return 201, r, {}
    return (*problem(403, "permission_denied"), {})


def handle_test(state: dict, path: str, body: dict) -> object:
    """The invitee and the administrator, for the tests."""
    silo = state["silo"]
    if path == "/_test/silo-accept":
        inv = next((i for i in silo["invitations"] if body["link"].endswith("#" + i["token"])), None)
        if not inv or inv["status"] != "pending":
            return {"error": "dead link"}
        email = body.get("email") or f"{body['username']}@example.com"
        if any(a["username"].lower() == body["username"].lower() or a["email"].lower() == email.lower() for a in silo["accounts"]):
            return {"error": "conflict: username or email taken"}  # 409 in Silo; the link stays usable
        acc = {"id": str(silo["next_account"]), "username": body["username"], "email": email,
               "role": inv["role"], "enabled": True, "password_login": True, "rev": 1, "library_ids": inv["library_ids"]}
        silo["next_account"] += 1
        silo["accounts"].append(acc)
        inv.update(status="accepted", accepted_user_id=acc["id"], accepted_at=now(), email=acc["email"])
        return {"ok": True, "account": acc["id"]}
    if path == "/_test/silo-expire":
        for inv in silo["invitations"]:
            if inv["id"] == str(body["id"]):
                inv["status"] = "expired"
        return {"ok": True}
    if path == "/_test/silo-stale":  # the next N account reads are outdated by the time Paywarr writes
        silo["stale_next"] = int(body.get("times", 1))
        return {"ok": True}
    if path == "/_test/silo-set-limit":  # an administrator changes requests by hand
        for acc in silo["accounts"]:
            if acc["id"] == str(body["id"]):
                acc["requests_allowed"] = body.get("requests_allowed")
                acc["rev"] += 1
        return {"ok": True}
    if path == "/_test/silo-add":
        silo["accounts"].append({"id": str(silo["next_account"]), "rev": 1, "password_login": True, "enabled": True, "role": "user", **body})
        silo["next_account"] += 1
        return {"ok": True}
    return None
