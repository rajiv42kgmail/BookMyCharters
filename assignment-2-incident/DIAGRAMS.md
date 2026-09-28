# Assignment 2: Architecture Diagrams

These diagrams support `PROPOSAL.md`. They are written in Mermaid, which GitHub renders automatically when you open this file in the repository.

---

## Diagram 1: Request flow before and after

Today every request, including junk, pays the full cost of booting Yii. In the proposal, junk is answered by Nginx and never reaches PHP.

```mermaid
flowchart TB
    subgraph BEFORE["BEFORE: every request boots the application"]
        direction LR
        A1["Any request<br/>real or junk"] --> B1["Nginx"]
        B1 --> C1["PHP-FPM worker"]
        C1 --> D1["Yii bootstrap,<br/>routing, logging"]
        D1 --> E1{"Route exists?"}
        E1 -->|yes| F1["200 response"]
        E1 -->|no| G1["404 after paying<br/>the full cost"]
    end

    subgraph AFTER["AFTER: Nginx decides first"]
        direction LR
        A2["Any request<br/>real or junk"] --> B2["Nginx<br/>path rules"]
        B2 -->|"known route"| C2["PHP-FPM + Yii"]
        C2 --> F2["200 response"]
        B2 -->|"junk or unknown"| G2["404 from Nginx<br/>no PHP, no app log"]
    end

    BEFORE ~~~ AFTER
```

---

## Diagram 2: How Nginx classifies each request

This is the decision the proposal adds. The two protected routes are matched first, by exact match, so no later rule can affect them.

```mermaid
flowchart TD
    R["Incoming request"] --> H{"Exact match:<br/>/health ?"}
    H -->|yes| APP["Pass to PHP-FPM and Yii<br/>UptimeRobot tests the real app"]
    H -->|no| W{"Exact match:<br/>/webhooks/razorpay ?"}
    W -->|yes| APP2["Pass to PHP-FPM and Yii<br/>App verifies the HMAC signature<br/>No IP block, no rate limit"]
    W -->|no| J{"Known junk?<br/>wp-*, xmlrpc, phpmyadmin,<br/>dotfiles like .env,<br/>.php other than index.php"}
    J -->|yes| J404["404 from Nginx<br/>logged to junk.log"]
    J -->|no| S{"Static file<br/>extension?"}
    S -->|yes| SF{"File exists<br/>on disk?"}
    SF -->|yes| SERVE["Serve file directly"]
    SF -->|no| S404["404 from Nginx"]
    S -->|no| AL{"Top-level prefix on<br/>the route allowlist?"}
    AL -->|yes| APP3["Pass to PHP-FPM and Yii"]
    AL -->|no| MODE{"Rollout mode?"}
    MODE -->|"mirror (days 2-3)"| MIRROR["Still pass to PHP-FPM<br/>Log as would-reject"]
    MODE -->|"enforce (day 4 on)"| A404["404 from Nginx"]

    classDef safe fill:#e6f4ea,stroke:#2e7d32,color:#1b5e20
    classDef reject fill:#fdecea,stroke:#c62828,color:#7f1d1d
    classDef watch fill:#fff8e1,stroke:#f9a825,color:#6d4c00
    class APP,APP2,APP3,SERVE safe
    class J404,S404,A404 reject
    class MIRROR watch
```

---

## Diagram 3: Rollout plan and rollback

The allowlist is the riskiest change, so it goes live in stages. It only starts rejecting after a day of log-only observation.

```mermaid
flowchart TD
    D0["Replay 24h of access log<br/>against the junk patterns"] --> FP{"Any real 200, 3xx<br/>or POST matched?"}
    FP -->|yes| FIX0["Adjust patterns,<br/>replay again"]
    FIX0 --> D0
    FP -->|no| D1["Two-hour fix live:<br/>junk paths return 404 in Nginx"]
    D1 --> CHK["curl /health, POST webhook, real page<br/>Watch CPU and 5xx for 15 min"]
    CHK --> D2["Day 2: allowlist in MIRROR mode<br/>log only, reject nothing"]
    D2 --> REV{"Any real URL in the<br/>would-reject log?"}
    REV -->|yes| FIX1["Add the missing prefix"]
    FIX1 --> D2
    REV -->|no| D4["Day 4: ENFORCE mode<br/>plus CI route-coverage check"]
    D4 --> MON["Monitor:<br/>404 rate on non-junk paths,<br/>synthetic checks of sitemap URLs"]
    MON --> OK{"Healthy?"}
    OK -->|yes| DONE["Day 5: alerts and runbook"]
    OK -->|no| RB["ROLLBACK:<br/>restore previous nginx config<br/>and reload, no restart"]
    RB --> D2

    classDef risk fill:#fff8e1,stroke:#f9a825,color:#6d4c00
    classDef bad fill:#fdecea,stroke:#c62828,color:#7f1d1d
    class D2,D4 risk
    class RB bad
```

---

## Summary of what each diagram shows

| Diagram | Question it answers |
|---|---|
| 1. Before and after | Where does the CPU and memory saving come from? |
| 2. Nginx classification | How are `/health` and the webhook guaranteed to stay safe? |
| 3. Rollout and rollback | How do we avoid the allowlist causing a new outage? |
