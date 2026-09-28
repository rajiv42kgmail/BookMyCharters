# Assignment 2 — Production Incident Proposal

## 1. Read of the log

The important observation is that the invalid traffic is reaching Yii2 at all. At roughly 100 invalid requests/minute, each scanner request currently pays the PHP/Yii bootstrap cost before returning a 404.

| Source / example | Assessment | Reason |
|---|---|---|
| `203.0.113.44` — `/wp-admin/`, `/xmlrpc.php`, `/test.php` (lines 83, 84, 89) | Hostile/scanning | These are WordPress/PHP probing paths and do not match the application's demonstrated routes. The repeated `zgrab` user-agent also looks like automated reconnaissance. |
| `198.51.100.7` — `/.env`, `/phpmyadmin/`, `/api/debug` (lines 85, 86, 91) | Hostile/scanning | `.env` and phpMyAdmin probing are common attempts to discover secrets or administrative surfaces. The same automated client probes multiple unrelated paths. |
| `216.144.250.9` — `/health` (lines 87, 88) | Legitimate | It returns 200 and identifies as `UptimeRobot/2.0`. Blocking it would risk uptime monitoring, which the brief explicitly says must remain working. |
| `104.18.22.33` — `POST /webhooks/razorpay` (line 90) | Legitimate integration | It returns 200 and identifies as `Razorpay-Webhook/1.0`. IP/user-agent blocking must not accidentally stop payment webhook delivery. |
| `45.146.164.2` — `/admin/login.php`, `/random-string` (lines 92, 96) | Hostile/scanning | Non-application PHP/admin probing followed by a random 404 path strongly suggests automated discovery. However, I would not block the IP globally based on this small sample. |
| `110.226.180.5` — `/flights/mumbai-delhi` (line 93) | Legitimate | It returns 200 and has a normal mobile browser user-agent. The same IP later requests `/.env` (line 94), so IP-level blocking is unsafe: one source can generate both legitimate and malicious-looking traffic. |
| `66.249.66.1` — `/sitemap.xml` (line 95) | Legitimate/expected crawler | It returns 200 and identifies as Googlebot. I would not block it based on this extract. |

The key danger for a naive fix is line 93/94: the same IP makes a successful customer-looking request and a suspicious `/.env` request. Blocking the IP would risk a real customer. Similarly, line 87/88 and line 90 are explicit examples of traffic that must survive.

I would therefore block **request paths at Nginx where the path itself is unambiguously invalid/sensitive**, rather than blocking IPs, all non-browser user-agents, or broad rate limits.

## 2. The next two hours

### Step 1 — Protect the application from the obvious probes

I would add Nginx exact-match locations for the high-confidence probe paths visible in the logs, for example:

```nginx
location = /wp-admin/      { return 404; }
location = /xmlrpc.php     { return 404; }
location = /.env           { return 404; }
location = /phpmyadmin/    { return 404; }
location = /test.php       { return 404; }
location = /admin/login.php { return 404; }
location = /api/debug      { return 404; }
```

These responses happen before PHP/Yii, reducing application CPU and memory use without touching `/health`, `/webhooks/razorpay`, `/sitemap.xml`, or known customer routes.

I would deploy this as a small Nginx config change, syntax-test it with `nginx -t`, reload rather than restart, and monitor error rate, PHP-FPM activity, CPU, memory and the affected request paths.

This is deliberately narrower than an IP deny list or user-agent deny list.

### Step 2 — Add observability before broadening the rule

For the next hour I would measure:

- requests/minute reaching Nginx versus PHP/Yii;
- PHP-FPM active workers and queue/backlog;
- EC2 CPU and memory;
- 4xx/5xx rate;
- request URI and status grouped by path;
- `/health` and Razorpay webhook success rate.

I would also verify that Googlebot and ordinary customer traffic remain unaffected.

### What I would not do immediately

I would not put CloudFront/WAF in front of the service as the first two-hour change. AWS WAF could provide useful managed filtering, but the brief asks to minimise additional spend and the immediate high-confidence paths can be rejected at the existing Nginx layer. I would also avoid fail2ban-style IP bans as the primary fix because the sample demonstrates that IP identity is not a safe proxy for intent.

## 3. Durable fix this week

The permanent solution is to make the edge responsible for traffic that should never reach the application.

### A. Define the public URL surface

Document the application's real public routes. Nginx should serve static files directly and pass only recognised application traffic to Yii. The important design point is that an arbitrary unknown path should not automatically become a PHP request.

For a small Yii application, I would use Nginx route/URI handling to reject known non-application probe patterns and tighten the front controller configuration. If the application has a sufficiently small and stable route surface, I would go further and explicitly allow the known public prefixes/routes at the edge, with exceptions for the health check and webhook endpoint.

The exact rule depends on the complete route inventory; I would not invent an allow-list from this six-line sample.

### B. Keep legitimate integrations explicit

`/health` and `/webhooks/razorpay` should have dedicated Nginx locations. They should remain reachable independently of generic filtering.

For Razorpay, I would also keep application-level webhook signature verification. Nginx filtering is not a substitute for authenticating the webhook payload.

### C. Add rate limiting carefully

After observing traffic for a few hours, I would consider a modest Nginx `limit_req` policy for expensive public endpoints. I would not apply a blanket per-IP rate limit to the whole site because shared NAT, mobile networks, crawlers and legitimate integrations can produce multiple requests from one IP.

I would also keep `/health` outside an aggressive rate limit and avoid applying a generic limit to the Razorpay webhook unless its expected delivery pattern has been confirmed.

### D. Consider AWS WAF only if the traffic warrants it

If scanning volume continues to grow, AWS WAF in front of an appropriate AWS edge/load-balancing architecture becomes the durable managed option. Its value is centralised managed rules, rate-based controls and better edge filtering.

The trade-off is additional AWS cost and operational complexity. For the current stated volume (~100 invalid requests/minute), I would first exhaust the low-cost Nginx solution and use metrics to justify WAF later.

## 4. Weakest point in my plan

The change most likely to backfire is an overly broad Nginx allow/deny rule intended to stop arbitrary invalid URLs.

If the application's route inventory is incomplete, a broad allow-list could turn a legitimate but less frequently used URL into a 404. This would be worse than the current scanner traffic because it would directly affect customers.

I would mitigate that by starting with exact high-confidence probe paths, reviewing the complete route inventory before introducing prefix/allow-list rules, deploying incrementally, and watching 404s, 5xxs, customer traffic and webhook/health-check success immediately after reload.

The rollback is simple: revert the Nginx snippet and reload Nginx.

## Summary

The immediate objective is not to identify every attacker. It is to stop obviously invalid traffic from booting Yii in the first place while preserving legitimate traffic.

I would therefore:

1. Reject the high-confidence probe paths at Nginx.
2. Leave health checks, payment webhooks, customer routes and Googlebot untouched.
3. Avoid IP and user-agent blanket blocking.
4. Measure the reduction in PHP/Yii traffic and resource pressure.
5. This week, tighten the edge/application boundary around the actual route inventory.
6. Introduce AWS WAF only if sustained traffic volume or attack patterns justify its cost.
