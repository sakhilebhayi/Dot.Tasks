# R-36 Dot.Tasks delivery report

Brief: `docs/programme/requirements/dot-tasks-transformation.md`, section 22. Audit and plan: `R36_AUDIT.md`. Design study: `R36_DESIGN_STUDY.md`.

## Where it stands

T1 to T9 are built and merged to `main`. **None of it is in production.** The last production deploy was in late October, before T1. The deploy is manual and the owner runs it.

This report does not call the platform production-ready. It is ready to deploy. What can only be proven after that is listed under production blockers.

| Check | Result |
|---|---|
| Feature and unit tests | 1,141 pass on SQLite and on PostgreSQL 16 |
| Browser journeys (Dusk) | 40 pass, under the enforced script policy |
| CI | Three jobs on every pull request: SQLite, PostgreSQL, browser |
| Accessibility | axe, WCAG 2.1 A and AA plus best practice: no violations on 29 pages and views at desktop width and 22 at phone width, plus the open task panel, quick add and find. Signed-in pages in light and dark; public pages light only, as they have no dark mode |
| Tenant sweep | 52 actions tried across businesses, none changed the other's data |

## Initial audit

Verified on 2026-11-05 by reading the code, running 537 tests and using the app. The base was sound: tenancy, roles, archive and restore, dependencies, Notify and Analytics clients. The product was thin. A task could not be edited after creation, subtasks came only from AI, drag worked with a mouse and kept no position, and statuses were a database enum. There was no calendar, timeline, list or team view, and no attachments, templates, custom fields, automation or reports.

One confirmed defect: finishing a recurring task made a new occurrence on every move to Done, so duplicates were possible. Fixed first.

## Skills reviewed and applied

| Skill | Used for |
|---|---|
| `design-taste-frontend` | Competitor study, the direction draw (style `achromatic-ledger`), `lint` and `inspect` on the finished app |
| `impeccable` | Audit of every screen on desktop and phone, in light and dark, before the frontend was called done |
| `emil-design-eng` | Drag, drop and panel behaviour |
| `gsap-*` | Reviewed. Not used: motion stays in CSS, which the job needs and which adds no dependency |
| `dataviz` | Charts: form, a one-hue ramp checked with its palette validator, table views |
| `design-md` | `DESIGN.md` for The Job Card |
| `claude-api` | The AI client's model and request shape |

`hallmark` was not used, because this was an existing design. Laravel Boost conventions were followed. The design was chosen by me under the owner's no-stops order, and `DESIGN.md` records why.

## Design and UX

The Job Card: ink on stone, Schibsted Grotesk, green for done and today, red for overdue, amber for waiting. Light and dark. Colour is never the only signal.

**Pages redesigned:** landing, sign-in pages, account and team pages, legal pages, Overview (personal, team, business), My Day, Inbox, My tasks, Upcoming, Due now, Team, Reports, the board in all four views, the task panel, quick add, find, Templates, Automation, Labels and fields, Notifications and settings, Removed, Escalation queue.

**New pages:** Connections, Audit log.

## Features

| Phase | Delivered |
|---|---|
| T1 Foundations | One occurrence per finish, rate limits, `/ready`, `/up/scheduler`, `/guardian/health`, built CSS in place of the CDN, browser tests in CI |
| T2 Design | Competitor study, The Job Card, shell |
| T3 Core | Every field edited in the panel, quick add from a sentence (`q`), find (`/`), manual subtasks, checklist, duplicate, history, private Inbox per person |
| T4 Direct manipulation | See below |
| T5 Depth | Custom columns per board, four dependency types, recurrence rules on the series, custom fields, templates, replies, internal notes, files, watchers, notification preferences, handover, one time zone per business |
| T6 Automation and AI | Rule engine with triggers, conditions, delays, approvals, loop protection and retries. AI breakdown with preview, summaries, clarity checks, focus ranking, workload check. All AI is off until a key and a cap are set |
| T7 Dashboards | Personal, team and business dashboards with arrangeable widgets, reports with stated definitions, CSV export |
| T8 Ecosystem | Files can be kept in Dot.Files, Connections page, project link on a task, `completed_7d` to Analytics |
| T9 Readiness | Enforced script policy, tenant sweep, audit log, restore check, lost connection handling, concurrent edit guard, batched long lists, PostgreSQL in CI |

## Drag and drop

Built on SortableJS, with keyboard moves written by hand.

- Between columns, and in order inside a column, on the board
- Rows in the list view
- Dates on the calendar, including tasks with no date
- Bars on the timeline, moved and stretched
- My Day: into the plan, in order, and onto a day of the week rack
- Between people on the Team page, and by lanes on the board
- Onto another board in the sidebar
- Subtasks, checklist items, columns, widgets, templates

Every move shows at once and the server has the last word. A refusal or a failed request puts the card back and says why. Positions persist. Pointer, touch and keyboard are each covered by a browser journey on the main surfaces.

## Backend

Services replaced logic in components: `TaskEditor`, `TaskPlacement`, `TaskHistory`, `Automations`, `TaskRecurrenceService`, `TaskNotifier`, `DashboardFigures`, `TaskReports`, `AttachmentFilings`, `AuditLog`. Policies refuse server-side, and a record from another business answers 404, so ids cannot be walked. Scheduled work: `tasks:run-rules` every 5 minutes, `model:prune` for the audit log, the existing purge and metrics jobs.

Concurrent edits: a save of long text that would overwrite someone else's stops and offers both versions. Short fields are last write wins.

## Database

12 new migrations. New tables: `task_statuses`, `task_checklist_items`, `task_activities`, `task_views`, `day_plans`, `task_fields`, `task_field_values`, `task_templates`, `task_attachments`, `task_watchers`, `task_rules`, `task_rule_runs`, `task_approvals`, `dashboard_layouts`, `audit_events`. New columns on `tasks`, `task_attachments`, `task_dependencies` and `teams`, including `completed_at`, `status_id`, `project_url`, `timezone`. All pass on SQLite and PostgreSQL 16, from an empty database. They have not run on a copy of production data.

## Integrations genuinely operational

**None has been proven end to end.** Integration secrets are deferred by the owner until every platform is done.

| Link | State |
|---|---|
| Dot.Notify | Client signed and tested with fakes. Four events only. No real delivery from this version |
| Dot.Analytics | Client tested with fakes. Its catalogue lists all four metrics |
| Dot.Files | Client tested with fakes. Dot.Files needs `tasks` added as a caller: sakhilebhayi/Dot.Files#122, open. The action stays hidden until the secret is set |
| Dot.Projects | A link field only. No sync, because Dot.Projects has no read API |
| Reverb | Optional. The 20-second refresh is what runs |
| AI | No real AI call has ever been made. The client defaults to `claude-opus-5-5` with server-side fallbacks, off until `ANTHROPIC_API_KEY` and `ANTHROPIC_MONTHLY_TOKEN_CAP` are both set |

## Security and tenant isolation

- `TenantSweepTest`: 52 actions by an admin of one business against another's records, comparing every row before and after. No data changed. Deliberate breaks of the guards were caught.
- One finding, fixed: other businesses' boards and tasks answered 403 and missing ones 404. Both now answer 404.
- The list of routes that work without signing in, and the pages with a record in the address, are pinned by tests.
- Script policy enforced with a nonce. `unsafe-eval` stays because Livewire's Alpine needs it, and `unsafe-inline` for styles stays because of style attributes. `SECURITY_CSP_REPORT_ONLY=true` is the way back without a deploy.
- Attachments are on a private disk and served only after a permission check.
- Audit log kept 24 months, removed tasks purged after 90 days.

One slip to record: `database/dev.sqlite` and `database/dusk.sqlite` were on a pushed branch of this public repository for a short time during T6b (commit 59bdada). They held local fake data and a test password hash only. They never reached `main`, and `.gitignore` now excludes them.

A second slip: #56 was merged with its browser job red. The failure was a race in the test setup, not in the product, and the same code passed all three jobs on `main`. The cause, a cookie shared between journeys, was fixed in the next pull request.

## Performance

`LoadSeeder`: 20,200 synthetic tasks, 8 boards, 20 people, 2,000 tasks on the measured board. Server time through the HTTP kernel on PostgreSQL 16 on a laptop, best of three, before and after batching:

| Page | Before | After | Queries |
|---|---|---|---|
| Dashboard | 65 ms | 50 ms | 18 |
| My Day | 43 ms | 33 ms | 13 |
| Upcoming | 35 ms | 41 ms | 7 |
| Board | 276 ms, 2.3 MB | 134 ms, 473 KB | 16 |
| List | 1,747 ms, 10.9 MB | 413 ms, 2.2 MB | 17 |
| Calendar | 76 ms | 75 ms | 13 |
| Timeline | 448 ms | 448 ms | 12 |
| Team | 1,586 ms, 7.8 MB | 801 ms, 287 KB | 6 |
| Reports | 520 ms | 517 ms | 13 |

In a browser, on the single-threaded local server with SQLite: the board loaded in about 150 ms once warm with 300 cards, a refresh took 122 ms, a move saved in 143 ms.

`LargeBoardTest` holds query counts level as tasks grow. Not measured: the production host, a real phone, or several people at once. Team and Reports are the slowest pages at this size.

## Acceptance tests

| # | Test | Result | Evidence |
|---|---|---|---|
| 1 | Quick add | Pass | Dusk `pressing_q...`, `a_card_is_added_at_the_foot...` |
| 2 | Natural language | Pass, rule-based | Dusk `pressing_q...`, `TaskCoreTest`. AI assistance not exercised |
| 3 | Edit and delete with permissions | Pass | Dusk `a_task_is_edited...`, `TaskRolesEnforcementTest` |
| 4 | Assign to authorised people | Pass | `TaskAssignmentTest`, `TenantSweepTest` |
| 5 | Drag between columns | Pass | Dusk `a_card_dragged_to_another_column...` |
| 6 | Reorder in lists | Pass | Dusk `a_card_is_put_in_order...`, `a_row_dragged...` |
| 7 | Move between dates | Pass | Dusk `a_card_dragged_to_another_day...` |
| 8 | Reorder subtasks | Pass | Dusk `subtasks_move_with_the_keyboard...` |
| 9 | Refresh keeps the change | Pass | Dusk `...stays_there_after_a_refresh` |
| 10 | Failed drag handled | Pass | Dusk `a_drop_the_server_refuses...`, `a_lost_connection_puts_the_card_back...` |
| 11 | Subtasks | Pass | `TaskCoreTest`, Dusk `a_task_is_edited...` |
| 12 | Dependencies | Pass | `TaskDependencyTest`, Dusk `a_blocked_card_will_not_seat_in_done` |
| 13 | Recurring without duplicates | Pass | `RecurrenceNoDuplicatesTest`, Dusk `finishing_a_recurring_task_twice...` |
| 14 | Comments, mentions, attachments | Pass | `TaskCollaborationTest`, Dusk `a_reply_and_a_file...` |
| 15 | Templates | Pass | `TaskFieldsAndTemplatesTest`, Dusk `a_template_is_used...` |
| 16 | Notifications | **Partial** | In-app tested. Email tested with fakes only, no real delivery |
| 17 | Automation | Pass | `AutomationTest`, Dusk `a_rule_is_written...` |
| 18 | AI with human approval | **Partial** | Preview and approval tested with a faked provider. No real AI call |
| 19 | Dashboards on real data | Pass | `DashboardTest`, `ReportsTest` |
| 20 | Filter, sort, search | Pass | `TaskBoardFiltersTest`, `TaskBoardSearchTest`, Dusk `the_slash_key...`, `a_saved_view...` |
| 21 | Sharing and permissions | Pass, team roles | `TaskRolesPolicyTest`, `TaskRolesEnforcementTest`. There are no share links |
| 22 | Tenant isolation | Pass | `TenantSweepTest` |
| 23 | Keyboard | Pass | Dusk `the_keyboard_moves...`, `the_keyboard_changes_a_date...` |
| 24 | Touch | Pass in emulation | Dusk `a_card_is_dragged_by_touch...`. No real phone |
| 25 | Responsive | Pass | `ResponsiveShellTest`, sweep at 390 px with no sideways scroll on 22 pages |
| 26 | Large collections | Pass, local | `LargeBoardTest`, the table above |
| 27 | Interrupted connections | Pass | Dusk `an_edit_made_with_no_connection...`, `a_card_moved_with_no_connection...` |

## Known limitations

- A repeating series makes its next task on finish. Nothing generates on a schedule.
- A year is stored as monthly with an interval of 12, because `recurrence_type` is an enum.
- Short fields are last write wins.
- Offline edits wait only in the open page: closing it loses them. Comments and new tasks are not retried, because they could land twice.
- Focus ranking has no user objectives. The workload check has one weekly number and no availability calendar.
- Automation has no "trigger a downstream workflow" action.
- Time zones are per business, not per person.
- The public pages have no dark mode.
- A task's full history is on the task. The audit log covers setup, members and exports, not every task edit.

## Outstanding work

| Item | Needs |
|---|---|
| Show a person's Dot.Projects tasks and write through (decision 1) | A read and write API in Dot.Projects |
| Notify catalogue entries for the events added since the four original ones | A change in Dot.Notify, then this client |
| Links to Docs, Agents, Finance, Brain, Memory | Decisions on what each should do |
| AI | Owner's provider and monthly cap |

## Production blockers

**Owner only:**

1. Run the deploy.
2. Take a database backup first, and run the deploy when a failed migration can be handled. 12 migrations have not run on real data.
3. Prove recovery: restore a backup and run `tasks:verify-restore`. Attachments live on the disk, so the backup must include `storage/app/private`. The Mac backups were failing at last check.
4. Renew `infodot.co.za` before 2026-11-27 13:53 UTC.
5. Merge sakhilebhayi/Dot.Files#122 when Files integration is wanted, then set the matching secrets.

**To check straight after the deploy:**

- `/up`, `/ready`, `/up/scheduler` and `/guardian/health` answer as expected. `/up/scheduler` shows whether `schedule:run` fires, which `tasks:run-rules` depends on.
- The enforced script policy on the live host: no violations in the `/csp-report` logs, and the board, panel and drag work. Set `SECURITY_CSP_REPORT_ONLY=true` if not.
- The upload limit on the host allows the attachment size.
