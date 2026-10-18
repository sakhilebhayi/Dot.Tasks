<div align="center">

<img src="public/images/logo.png" alt="Dot.Tasks" width="320" />

<br /><br />

**Break down complex work into subtasks with time estimates and track everything on kanban.**

<br />

![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?style=flat-square&logo=laravel&logoColor=white) ![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?style=flat-square&logo=php&logoColor=white) ![Livewire](https://img.shields.io/badge/Livewire-3-FB70A9?style=flat-square) ![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-336791?style=flat-square&logo=postgresql&logoColor=white)

<br /><br />

**Part of the [InfoDot Ecosystem](https://github.com/sakhileb/InfoDot)** &nbsp;·&nbsp; `tasks.infodot.app`

</div>

---

## What is Dot.Tasks?

Dot.Tasks is the personal and team task management platform in the InfoDot ecosystem. AI decomposes complex goals into subtasks with time estimates; a flexible kanban board and list view let every team member manage their workload their way.

## Core Features

- AI task decomposition — break any goal into subtasks with time estimates (needs `ANTHROPIC_API_KEY`; without one it says so and creates nothing)
- Drag-and-drop kanban board with subtasks, priorities, due dates and recurring tasks (daily, weekly, monthly, every N days)
- Filters on a board by assignee, priority, label, due state (overdue, today, none) and text, all kept in the URL so a filtered board can be bookmarked; a **My tasks** view of everything assigned to you across boards, most urgent first
- Team labels (admins manage them from a fixed colour palette, editors put them on tasks)
- Task assignment, scoped server-side to the task's team; assignee and labels can be set when the task is created
- Archive (reversible, with an Archived list) and soft delete for tasks, archive for boards; subtasks are handled explicitly and need a confirmation
- Comments with `@Full Name` mentions of team members; mentioned people, the task's assignee and its creator are notified once each (never the author, never anyone outside the team)
- "Blocked by" dependencies between tasks on one board: no loops, and a blocked task cannot be moved to Done until its blockers are done
- Escalation queue for tasks more than 7 days overdue (the list owner approves or rejects)
- In-app notification bell (assigned, new comment, mentioned, due soon) on Laravel's `database` notification channel
- Dashboard KPIs for the team, from one query
- Roles: owner and admin do everything, editor works on tasks, viewer reads (see Roles below)
- Class-based dark/light theme toggle
- Ecosystem SSO from InfoDot hub

> Not implemented: a calendar view, a built-in Pomodoro timer, search beyond title and description (no full-text engine), and any integration with Dot.Projects. See `wiki.md` §8 for the known gaps.

## Roles

Roles are Jetstream team roles. An InfoDot `viewer` signs in here as `viewer`; owner and admin map to `admin`.

| | viewer | editor | admin / owner |
|---|---|---|---|
| Read boards and tasks | yes | yes | yes |
| Create boards and tasks; move, assign, label, comment, archive and restore tasks | | yes | yes |
| Delete tasks, archive boards, create/rename/recolour/delete labels | | | yes |

## Domain Models

- **TaskList** — a board; owns many tasks; can be archived
- **Task** — unit of work with status, priority, optional assignee and due date; subtasks are just tasks with a `parent_id`; can be archived or soft deleted; can be blocked by other tasks of the same board
- **Label** — team-scoped, colour-coded tag from a fixed palette, many-to-many with tasks
- **TaskComment** — discussion on a task, with `@Name` mentions
- **AiBreakdownLog** — prompt/response audit trail for each AI decomposition call

## Responsive shell

The app layout (`resources/views/layouts/app.blade.php`) is a fixed 260px sidebar plus a fixed top bar from **860px** up. Below that (`max-width: 859.98px`, with the matching `matchMedia('(min-width: 860px)')` in the layout script) the sidebar becomes an off-canvas drawer. Keep the two numbers in step if you move the breakpoint.

| Hook | What it is |
|---|---|
| `#shell-menu-button` | The labelled "Menu" button in the top bar (`aria-controls="shell-drawer"`, `aria-expanded`). Shown below the breakpoint only. |
| `#shell-drawer` | The sidebar itself (`<aside class="sidebar">`). Closed on a phone it is `visibility:hidden` and `inert`, so it cannot be focused or read. |
| `#shell-backdrop` / `.shell-backdrop` | Tap target behind the open drawer. |
| `.topbar` | The fixed top bar. `--topbar-h` (54px) is the single source for its height and for the content offset (`.content-wrap` padding-top). |
| `.table-scroll` | Wrapper for anything wide. |

Opening the drawer moves focus to its Close button, sets `inert` on the top bar and page content, and locks page scroll. Escape, the backdrop, Close, following a link, and growing past the breakpoint all close it; Escape, the backdrop and Close also return focus to the Menu button. It is a small inline script, not Alpine, so it keeps working if a script is slow. Livewire 3 supplies Alpine; do not add a second copy from a CDN (it breaks every Livewire re-render).

Team switching, Team settings and Log out live in the sidebar footer (the stock Jetstream bar that used to hold them rendered under the top bar and is no longer included).

**Adding a wide table:** wrap it, do not widen the page.

```blade
<div class="table-scroll">
    <table style="min-width:40rem;"> ... </table>
</div>
```

**Page pieces:** `.page-pad` (page padding, tighter on phones), `.page-head` (title row that wraps), `.kpi-grid` / `.kpi-tile` (3 columns on desktop, 2 on phones), `.list-grid` (adds a single column on phones), `.list-card`. The kanban board scrolls inside `.kanban-scroll` (scroll-snap on phones, one column per swipe); each card carries an always-visible "Move to..." select, which is the touch alternative to drag and drop.

## Tech Stack

| Layer | Technology |
|---|---|
| Framework | Laravel 12 |
| Language | PHP 8.4 |
| Frontend | Livewire 3 · Alpine.js 3 · Tailwind CSS |
| Database | PostgreSQL 16 (shared across ecosystem) |
| Realtime | Laravel Reverb |
| Auth | Laravel Sanctum (InfoDot SSO) |
| AI | Anthropic Claude (`claude-sonnet-4-6`) |
| Storage | AWS S3 / Local (Flysystem) |
| Search | SQL `LIKE` on task title and description (no search engine) |
| Queue | Redis · `php artisan queue:work` (no Horizon) |
| Scheduler | `php artisan schedule:run` from cron, every minute |

## Quick Start

```bash
git clone https://github.com/sakhileb/Dot.Tasks.git
cd Dot.Tasks
cp .env.example .env
composer install
npm install && npm run build
php artisan key:generate
php artisan migrate
php artisan serve
```

> **Ecosystem SSO:** Set `DB_*` env vars to the shared InfoDot PostgreSQL instance and `APP_URL=https://tasks.infodot.app`. Users authenticated through InfoDot gain access automatically via Sanctum handoff tokens.

## 🚢 Deployment

### Production Checklist

1. **Set environment**
   ```bash
   APP_ENV=production
   APP_DEBUG=false
   ```

2. **Install dependencies (no dev)**
   ```bash
   composer install --optimize-autoloader --no-dev
   npm ci
   ```

3. **Build frontend assets**
   ```bash
   npm run build
   ```

4. **Cache configuration**
   ```bash
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   php artisan event:cache
   ```

5. **Run migrations**
   ```bash
   php artisan migrate --force
   ```

6. **Start the queue worker** (use `deploy/queue-worker.service` for systemd or `deploy/queue-worker.supervisord.conf` for Supervisor). Requires `QUEUE_CONNECTION=redis`, matching `.env.production.example`. What needs it: the `TaskBoardUpdated` broadcast that keeps other people's open boards live. Without a worker those updates queue up and never go out. Notifications are written synchronously and do not wait for it.
   ```bash
   php artisan queue:work redis --tries=3 --timeout=90
   ```

7. **Run the scheduler.** Three jobs run from `routes/console.php`: `tasks:check-due-soon` (07:00, notifies assignees of tasks due within two days), `tasks:detect-escalation-candidates` (07:30, proposes escalating tasks more than 7 days overdue) and `tasks:publish-metrics` (02:10 UTC, sends daily aggregate numbers to Dot.Analytics once configured, see below). Laravel only runs them if something calls the scheduler every minute. Without it **no due-soon reminders are sent and nothing is proposed for escalation**, and no error says so. Use `deploy/scheduler.cron` (cron) or `deploy/scheduler.service` + `deploy/scheduler.timer` (systemd), which run:
   ```bash
   * * * * * cd /var/www/html && php artisan schedule:run >> /dev/null 2>&1
   ```
   Check it with `php artisan schedule:list` (shows the next run of each job) and, after the first 07:00, by looking for `task_due_soon` rows in `notifications`.

8. **Start the Reverb WebSocket server** (use `deploy/reverb.service` for systemd or `deploy/reverb.supervisord.conf` for Supervisor) -- never run this as a bare foreground command in production, it needs the same process supervision as the queue worker.
   ```bash
   php artisan reverb:start
   ```
   Binds to `REVERB_SERVER_HOST`/`REVERB_SERVER_PORT` (loopback-only by default in `.env.production.example`) -- it is not meant to be reachable directly from the internet. See "WebSocket Reverse Proxy" below for how browsers actually reach it over `wss://`.

### Web Server Configuration

#### Nginx

```nginx
server {
    listen 80;
    server_name your-domain.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    http2 on;
    server_name your-domain.com;
    root /var/www/tasks/public;

    ssl_certificate     /etc/letsencrypt/live/your-domain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/your-domain.com/privkey.pem;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    # WebSocket Reverse Proxy -- only Reverb's client-facing path
    # (/app/{key}, what Echo/pusher-js connects to) is proxied here. Its
    # server-to-server publish API (/apps/{id}/events etc.) is deliberately
    # NOT exposed publicly -- config/broadcasting.php's reverb connection
    # talks to it directly over the internal REVERB_SERVER_HOST/PORT
    # instead, so it never needs to be reachable from outside this box.
    # The Upgrade/Connection headers are what turn this from a plain HTTP
    # proxy into a WebSocket one; without them the client's protocol
    # upgrade handshake fails.
    location /app {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 60s;
    }
}
```

#### Apache

Requires `mod_proxy`, `mod_proxy_wstunnel`, and `mod_ssl` enabled (`a2enmod proxy proxy_wstunnel ssl`).

```apache
<VirtualHost *:80>
    ServerName your-domain.com
    Redirect permanent / https://your-domain.com/
</VirtualHost>

<VirtualHost *:443>
    ServerName your-domain.com
    DocumentRoot /var/www/tasks/public

    SSLEngine on
    SSLCertificateFile      /etc/letsencrypt/live/your-domain.com/fullchain.pem
    SSLCertificateKeyFile   /etc/letsencrypt/live/your-domain.com/privkey.pem

    <Directory /var/www/tasks/public>
        AllowOverride All
        Require all granted
    </Directory>

    ProxyPass        /app ws://127.0.0.1:8080/app
    ProxyPassReverse /app ws://127.0.0.1:8080/app
</VirtualHost>
```

### Email through Dot.Notify

Dot.Tasks tells [Dot.Notify](https://github.com/sakhilebhayi/Dot.Notify) about four events it already raises in-app, so the company can email them. Notify decides whether anything is sent; Dot.Tasks only reports what happened (`App\Services\Notify\NotifyClient`, `POST /api/ingest/events`).

| Event | Sent to | When | Data sent |
|---|---|---|---|
| `task.assigned` | the new assignee | someone else assigns them a task (never self-assignment) | `task_title`, `board_name`, `assigned_by`, `due_date`, `url` |
| `task.due_soon` | the assignee | `tasks:check-due-soon` raises the in-app reminder | `task_title`, `board_name`, `due_date`, `url` |
| `task.escalated` | the task's assignee | a board owner approves an escalation (there is no in-app notification for this one); not sent to the approver | `task_title`, `board_name`, `owner_name`, `url` |
| `task.mentioned` | the mentioned member | the same people who get the in-app mention notification | `task_title`, `board_name`, `mentioned_by`, `url` |

`url` is the absolute `https` address of the board in this app. Comment text and task descriptions are never sent, and an email address travels only in `recipient.email`.

- **Nothing is sent until `NOTIFY_INGEST_URL` and `NOTIFY_INGEST_SECRET` are both set**, and even then Notify sends nothing until the company has enabled the event (an active rule for it) in Dot.Notify. `NOTIFY_INGEST_SECRET` must equal `NOTIFY_INGEST_SECRET_<PLATFORM KEY>` in Notify's `.env`; `NOTIFY_PLATFORM_KEY` defaults to `tasks`.
- **A queue worker is required** (`php artisan queue:work`, see the checklist above). Events are queued after the database transaction commits, never sent during the user's request; with `QUEUE_CONNECTION=sync` they would run inline, so use a real queue in production.
- A team is only addressed once it is linked to InfoDot (`teams.infodot_team_uuid`), and a person only if they have an email address; otherwise the event is skipped.
- Notify can never break or slow a user action: 5 second timeout, 3 tries with backoff (60 s, then 5 min), every failure is caught and logged without addresses or secrets, and after 5 failures in a row calls pause for 60 seconds (a cache-based circuit breaker; the in-app notification is written by its own code and is unaffected).
- Requests are signed with HMAC-SHA256 over `"{timestamp}.{raw body}"` (`X-InfoDot-Timestamp`, `X-InfoDot-Signature`). The idempotency key is `{event}:{subject id}:{sha1 of the recipient email}:{bucket}` (bucket: the task's last-updated time for an assignment, the due date for a reminder, the proposal id for an escalation, the task id for a mention), so retries and double dispatches collapse in Notify.

### Daily metrics to Dot.Analytics

Dot.Tasks publishes **one daily snapshot of aggregate numbers per company** to [Dot.Analytics](https://github.com/sakhilebhayi/Dot.Analytics), so its dashboards and briefings show real figures. Analytics only receives; this app never reads anything back from it, so an Analytics outage or a wrong setting changes nothing here (`tasks:publish-metrics`, `App\Services\Analytics\MetricsCollector`, `POST /api/ingest/snapshots`).

What is sent for a company (company = a team linked to InfoDot, not a personal team):

```json
{ "platform": "tasks", "company_uuid": "<the team's InfoDot uuid>", "taken_at": "2026-11-05T00:00:00Z", "period": "day", "metrics": { ... } }
```

| Key | Meaning |
|---|---|
| `open` | tasks that are not done |
| `overdue` | open tasks due before today |
| `created_7d` | tasks created in the last 7 days |

`completed_7d`, which Analytics' catalogue also lists for Tasks, is **not sent**: Dot.Tasks does not record when a task was completed (the dashboard's "completed this week" is only "done and last edited this week"), and a guess would not be a measurement. It will be added once completion is recorded.

**Aggregates only.** Never a name, email, title, reason, note or any other row. Counts leave out archived and deleted rows, every query is scoped to the one company, and "today" and the windows are in UTC.

- **Nothing is sent until `ANALYTICS_INGEST_URL` and `ANALYTICS_INGEST_SECRET` are both set.** `ANALYTICS_INGEST_URL` is the full address ending `/api/ingest/snapshots`. `ANALYTICS_INGEST_SECRET` must equal `ANALYTICS_INGEST_SECRET_<PLATFORM KEY>` in Dot.Analytics' `.env`; `ANALYTICS_PLATFORM_KEY` defaults to `tasks`.
- **The scheduler must run** (`* * * * * php artisan schedule:run`, see the checklist above): `tasks:publish-metrics` is scheduled daily at 02:10 UTC. **A queue worker is required** (`php artisan queue:work`); with `QUEUE_CONNECTION=sync` the snapshots would be sent inline by the command, which is also fine.
- Try it safely: `php artisan tasks:publish-metrics --dry-run` prints each payload and sends nothing (it works with no configuration). `--company=<InfoDot team uuid>` limits a run to one company.
- Safe to repeat: `taken_at` is the start of the UTC day, and Analytics treats the same company, platform, `taken_at` and period as one snapshot, so a re-run or a retry the same day cannot double count.
- Analytics can never break or slow this app: 5 second timeout, no redirects followed, 3 tries with backoff (60 s, then 5 min), every failure caught and logged without the body or the secret, and after 5 failures in a row calls pause for 60 seconds (a cache-based circuit breaker). A snapshot that cannot be delivered is simply replaced by the next day's.
- Requests are signed with HMAC-SHA256 over `"{timestamp}.{raw body}"` (`X-InfoDot-Timestamp`, `X-InfoDot-Signature: sha256=<hex>`), made at send time so a retry carries a fresh timestamp.

### Real-Time Health Check

`GET /up/realtime` checks broadcasting config, queue connection, and whether `reverb:start` is actually accepting connections -- independently, so it reports which link broke rather than a single healthy/unhealthy bit. See `app/Http/Controllers/RealtimeHealthController.php`.

---

## Ecosystem

**Dot.Tasks** is one of **21 platforms** in the InfoDot ecosystem, connected via shared PostgreSQL and Sanctum SSO. Visit [InfoDot](https://github.com/sakhileb/InfoDot) to explore the full platform map.

## License

MIT © [SK Digital / BluPin Incorporated](https://github.com/sakhileb)
