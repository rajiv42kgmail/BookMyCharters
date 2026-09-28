# Assignment 2: Production Incident Proposal

**Problem:** About 100 invalid requests per minute each go through Nginx into the full Yii2 bootstrap before returning 404. That causes CPU and memory spikes that are starting to hurt real customers.

**Core idea:** 100 requests/minute is under 2 per second, which is trivial *volume*. The problem is *cost per request*, not traffic size. Each junk request costs a PHP-FPM worker, framework bootstrap, routing and logging. The fix is to make junk requests cost almost nothing, by rejecting them in Nginx **based on the path**, not on who sent them. Blocking by IP, rate or user-agent is where the danger is, as the log shows.

**Diagrams:** see [`DIAGRAMS.md`](DIAGRAMS.md) for the before/after request flow, the Nginx request classification, and the rollout and rollback plan.

---

## 1. My read of the log

| Source (line) | What it does | My judgement | Why |
|---|---|---|---|
| `203.0.113.44` (zgrab): `/wp-admin/`, `/xmlrpc.php`, `/test.php` | Probes for WordPress and stray PHP files | **Hostile (recon)** | We don't run WordPress. A scanner looking for known vulnerable software. |
| `198.51.100.7` (python-requests): `/.env`, `/phpmyadmin/`, `/api/debug` | Probes for secrets and admin tools | **Hostile (recon)** | `/.env` is a search for leaked credentials. `/api/debug` needs checking against our real routes first. |
| `45.146.164.2` (no UA): `/admin/login.php`, `/random-string` | Login probe plus a random path | **Hostile / fuzzing** | Empty UA plus junk paths. But an empty UA alone is not proof, so I would not block on it. |
| `216.144.250.9` (UptimeRobot): `/health` 200 | Uptime check | **Legitimate, must not break** | This is our monitoring. It is repeated by design, so a naive rate limit would trip on it. |
| `104.18.22.33`: `POST /webhooks/razorpay` 200 | Payment provider callback | **Legitimate, must not break** | Blocking or throttling it means missed payment confirmations. |
| `66.249.66.1` (Googlebot): `/sitemap.xml` 200 | Search crawler | **Legitimate** | Blocking it hurts SEO. |
| `110.226.180.5` (iPhone UA): `/flights/mumbai-delhi` 200 **and** `/.env` 404 | A real customer's request and a probe from the same IP | **Ambiguous, this is the trap** | See below. |

**The line that makes a naive fix dangerous:** `110.226.180.5` fetches a real flight page and then requests `/.env`. It is most likely a shared address, for example a mobile carrier NAT, where many real customers share one IP and one device or neighbour is scanning. A UA that says "iPhone" is also trivial to spoof, so it proves nothing. Blocking this IP would cut off real customers, and blocking `/.env` by path would not. This is the evidence that the fix must be **path-based, not identity-based**.

Other traps: the `/health` and webhook lines are the ones we cannot break, so any rule must be tested against them explicitly. Blocking every `.php` URL is also unsafe if our app or a partner still uses one.

**Assumptions (to verify on day 1):** the app's public routes are the Yii `urlManager` rules plus `/health`, `/webhooks/*` and `/sitemap.xml`. No legitimate URL ends in `.php` except the front controller. `/api/debug` is not a real route.

---

## 2. The next two hours (relieve pressure, stay online)

1. **Check before blocking.** Replay the last 24 hours of the access log against the patterns I plan to block. Any request that returned 200/3xx, or any `POST`, and matches a block pattern is a false positive that I must resolve first. This is a read-only check on the log file. It is the safety net for the whole change.
2. **Add path rules in Nginx.** Junk paths return `404` immediately and never reach PHP-FPM:

```nginx
# Exact-match locations win over regex locations, so these stay untouched.
location = /health              { try_files $uri /index.php$is_args$args; }
location = /webhooks/razorpay   { try_files $uri /index.php$is_args$args; }

# Well-known junk: rejected in Nginx, no PHP, no app logging.
location ~* ^/(wp-admin|wp-login\.php|wp-content|wp-includes|xmlrpc\.php|phpmyadmin|pma) {
    access_log /var/log/nginx/junk.log; return 404;
}
location ~ /\.(?!well-known) { access_log /var/log/nginx/junk.log; return 404; }  # .env, .git ...
location ~* \.(php|asp|aspx|jsp|cgi)$ {
    # only /index.php is allowed to reach PHP
    access_log /var/log/nginx/junk.log; return 404;
}

# Missing static files must not boot Yii either.
location ~* \.(css|js|png|jpg|jpeg|gif|svg|ico|webp|woff2?)$ { try_files $uri =404; }
```

   (Adjust so the real front controller `= /index.php` is handled by the existing PHP `location`.)
3. **Make the app's own 404 cheap** in case anything still slips through. Exclude `yii\web\HttpException:404` from the log target so 404s do not write to disk, and use a minimal error response.
4. **Protect the box.** Cap PHP-FPM `pm.max_children` to what memory allows, so a spike degrades gracefully instead of causing an out-of-memory crash.
5. **Deploy safely.** Run `nginx -t`, then `reload` (graceful, no dropped connections), never restart. Then immediately `curl` `/health`, `POST /webhooks/razorpay` and a real page, and watch the 5xx rate and CPU for 15 minutes. **Rollback:** restore the previous config file and reload.

I would deliberately **not** add IP bans or rate limits in this first step.

---

## 3. The durable fix (this week: 2 engineers, about 4 to 5 days)

**Approach: an allowlist of routes in Nginx, backed by monitoring. Nothing outside the allowlist ever reaches PHP.**

| Day | Work |
|---|---|
| 1 | Two-hour fix live and watched. Extract the real route list from the Yii `urlManager` rules. Confirm assumptions above. |
| 2 | Build the allowlist as coarse top-level prefixes (`/flights/`, `/api/`, `/webhooks/`, `/health`, `/sitemap.xml`, static assets). Deploy in **mirror mode** first: Nginx only *logs* what it would reject, and still serves everything as before. |
| 3 | Review 24 hours of mirror logs. Every "would-reject" that is a real URL is a missing route. Fix the list. |
| 4 | Switch from mirror to enforce. Add a CI check that fails if a Yii route is not covered by the Nginx allowlist. |
| 5 | Alerts, runbook, rollback rehearsal. |

**Razorpay:** do not protect the webhook with an IP allowlist, since provider IPs change and a stale list silently drops payments. Verify the webhook's HMAC signature in the app. That is what actually authenticates the caller, so the Nginx layer just needs to let it through.

**Why not the alternatives:**

- **AWS WAF**: about $5 per web ACL per month, $1 per rule per month and $0.60 per million requests (AWS list prices, worth re-checking). It only attaches to an ALB, CloudFront or API Gateway, and our EC2 sits directly behind Nginx. So we would first pay for an ALB (roughly $16 or more per month, an approximate figure to confirm) or CloudFront, and change the architecture, to solve a problem Nginx can solve for free. It becomes worthwhile if the attacks grow into real volumetric or application-layer floods.
- **Cloudflare or another CDN proxy:** good protection, but it changes DNS and the real client IP handling, and adds a new dependency in the payment webhook path. Too much change for one week on a live site.
- **fail2ban / IP bans:** free, but rejected for now for the reason in section 1. The shared-IP customer (`110.226.180.5`) would be banned along with the scanner. Also, once junk requests cost microseconds in Nginx, banning gains very little. If I revisit this, it would use a high threshold, only count hits on junk patterns, ban briefly, and **exempt** UptimeRobot and verified Googlebot.
- **Nginx `limit_req` on everything:** UptimeRobot's repeated `/health` hits and a shared mobile IP would both look like abusers. If trialled, use `limit_req_dry_run` first to see who *would* be limited.

**Cost:** no new AWS spend. The main cost is engineer time.

---

## 4. The weakest point in my own plan

**The route allowlist is the change most likely to backfire.**

**How it fails:** someone ships a new customer-facing URL (a campaign landing page, a new partner callback, a redirect) and forgets to update the Nginx list. Real customers then get a 404, with no error on the app side, because the request never reaches it. It is a silent revenue leak, and worse than the problem I am fixing.

**How I would detect it early:**

1. **Mirror mode first** (day 2 to 3): the list runs for a day or more in log-only mode before it can reject anything.
2. **CI check** that every route in Yii's `urlManager` matches the Nginx allowlist, so a missing prefix fails the build.
3. **Synthetic checks** that request every URL in the sitemap and a set of key routes, and alert on any 404.
4. **Alert on 404 rate for browser-like traffic**, and separately on 404s for paths that are *not* in the junk list. A real missing route shows up here, while scanner noise does not.
5. **Rollback** is one config file and one `reload`, and I rehearse it on day 5.

Secondary risk: the blanket `\.php$` rule. That is why the log-replay check in step 1 of section 2 happens before anything is deployed.
