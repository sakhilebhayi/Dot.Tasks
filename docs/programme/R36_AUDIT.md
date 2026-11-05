# R-36 Dot.Tasks: audit and plan (2026-11-05)

Phase 1 of the owner's brief, `docs/programme/requirements/dot-tasks-transformation.md`. It records what Dot.Tasks is today, checked by reading the code, running the 537 tests, and using the app in a browser with seeded local data. Nothing here has been changed yet.

## The short version

Dot.Tasks is a sound, well-tested base with a thin product on top. Tenancy, roles, archive, restore, dependencies and Notify and Analytics clients are careful work and should be kept. The product around them is much smaller than it looks:

- **A task cannot be edited after it is created.** The drawer changes status, assignee and labels. Title, description, priority, due date and estimate have no edit path.
- **Subtasks can only come from AI.** With no key set there is no way to add one.
- **Drag and drop moves a card to a column and nothing else.** Where it lands is not kept, and `sort_order` is never written after creation.
- **One view.** A board per list, plus My tasks, Today and Upcoming as lists. No calendar, timeline, list view or team view.
- **One real defect against the brief.** Recurring tasks create duplicates.
- **The look is a dark template** on the Tailwind Play CDN, with most styling inline.

## State of each feature

| Area | State | What is there |
|---|---|---|
| Dashboard | Works, thin | Eight count tiles and a list of boards, from real data. Team-wide only |
| Task creation | Partial | One modal form. No quick add, inline add, duplicate or natural language |
| Task editing | **Missing** | No way to change title, description, priority, due date or estimate |
| Task detail | Partial | Status, assignee, labels, blockers, comments, archive, delete |
| Board | Partial | Fixed four columns. Filters and search work. No swimlanes or custom statuses |
| Drag and drop | Partial | Mouse only, between columns, drops to the end. A `Move to...` select is the touch fallback |
| Statuses | Fixed | Four values held in a database enum, so they cannot be configured |
| Priorities | Works | Four values. Set at creation only |
| Assignment | Works | One assignee, checked server side |
| Comments and mentions | Works | Mentions need a teammate's full name typed. No replies, editing or deleting |
| Notifications | Works in app | Bell, page and four events. No preferences. Email goes through Notify, off until secrets are set |
| Recurrence | **Defective** | Daily, weekly, monthly, every N days. See the defect below |
| Subtasks | Partial | One level, shown as a count. AI is the only way in |
| Checklists | Missing | |
| Dependencies | Works | Blocked-by with loop prevention. Finish-to-start only |
| Attachments | Missing | |
| Search and filtering | Partial | Per board and on My tasks. No global search or saved views |
| Reporting | Missing | Dashboard counts only. Daily numbers go to Analytics |
| Escalation | Works | Overdue tasks are proposed, and a list owner approves or rejects |
| Archive, delete, restore | Works | The strongest part of the app, with retention and purge |
| Labels | Works | Team labels with colours |
| Templates, custom fields, automation | Missing | |
| Time tracking | Missing | An estimate field only |
| Real time | Off live | Built on Reverb, and `/up/realtime` answers 503 on the live site |
| Settings | Jetstream | Profile and team only. No workspace administration |

Nothing was found that is visual only or runs on placeholder data. What exists does what it shows.

## Defects

1. **Recurring tasks duplicate.** Tested: marking a recurring task done twice, or reopening it and finishing it again, makes a second occurrence. Dropping a done card on Done is enough. `TaskStatusService::move` spawns whenever the new status is done, and nothing marks an occurrence as already spawned. This fails acceptance test 13.
2. **AI breakdown writes subtasks with no preview.** It takes a button press, but the brief asks for review first.
3. **A recurring occurrence loses its subtasks and takes the source's position.** Only labels are copied.
4. **Time zones are not handled.** Due dates are plain dates, and "today" is the server's.
5. **The board loads every task on a list,** with no paging.

## Architecture

- **Stack:** Laravel 13, Livewire 3.8, Jetstream teams, Reverb, Vite 8. The app layout loads Tailwind from the Play CDN at runtime, and most pages use inline styles: 38 views carry them, and `app.css` is 22 lines.
- **Tenancy:** a task has no `team_id`. The tenant comes through `task_lists.team_id`, and `Task::forTeam` is the one route for cross-list queries. There is no global scope, so each new query has to remember it.
- **Roles:** owner, admin, editor, viewer through Jetstream, enforced in `TaskPolicy`. Sound.
- **Order:** `sort_order` is an integer set once. Dragging needs a real position.
- **Workspaces:** a list is the only container, and the team is the business. There is no project, section or workspace level.
- **Tests:** 537 feature and unit tests, passing. No browser tests, which matters for a brief built on direct manipulation.

## Security

Reviewed: Livewire actions, policies, the drawer's event path, label and assignee checks. Each action authorizes on the server, and ids are checked against the team. No cross-tenant path was found by reading, and the route sweep used on Dot.Sheets still has to be run here.

To do: rate limits (no route in the signed-in group has one), an enforced script policy (live is report-only with `unsafe-inline`, and needs the Tailwind CDN gone first), `/ready`, `/up/scheduler` and `/guardian/health` (all 404 live), an audit log, and attachment protection when attachments arrive.

## Design

Dark admin template: glowing KPI tiles, Syne headings, Material Symbols, indigo buttons on near-black. It is close to the brief's list of what to remove, and it doesn't match the warm cream sign-in page in the same app. The brief is right that this needs a real direction, not a recolour.

The phone layout holds up, with one board column wide and the Move to select always visible. It proves the point about touch: dragging isn't available there.

## Dot.Tasks and Dot.Projects

The brief asks for one owner of shared data. Today they are separate: Dot.Projects has its own `project_tasks` with status, dates, dependencies, baselines and earned value built on them. Dot.Tasks has `tasks`. Neither has an API.

Moving project tasks into Dot.Tasks would break the schedule, critical path and earned value that are live in Dot.Projects. So the recommendation is:

- **Dot.Projects keeps owning project tasks.** Its dates and costs depend on them.
- **Dot.Tasks owns everything else,** and shows a person's project tasks in My Day and My tasks as linked items.
- **Changes write through.** Finishing a linked item calls Dot.Projects, so one status exists, with no second copy.

This needs a read and write API in Dot.Projects, which is already on the backlog because Dot.Sheets is waiting on it too.

## Integrations

| Platform | State |
|---|---|
| InfoDot | Sign-in and team sync work |
| Dot.Notify | Client built for four events, in Notify's catalogue, waits on secrets |
| Dot.Analytics | Daily numbers built, waits on secrets |
| Dot.Files | Accepts platforms, not connected. Attachments would go here |
| Dot.Projects | No API either side |
| Others | Nothing to connect to yet |

## The 27 acceptance tests today

| # | Test | Today |
|---|---|---|
| 1 | Quick add | Partial, modal only |
| 2 | Natural language | Missing |
| 3 | Edit and delete | Partial, no editing |
| 4 | Assign | Pass |
| 5 | Drag between columns | Partial, mouse only |
| 6 | Reorder in a list | Missing |
| 7 | Move between dates | Missing |
| 8 | Reorder subtasks | Missing |
| 9 | Drag survives refresh | Status only |
| 10 | Failed drag handled | Partial, refusal shown |
| 11 | Subtasks | Partial, AI only |
| 12 | Dependencies | Pass, one type |
| 13 | Recurring without duplicates | **Fail** |
| 14 | Comments, mentions, attachments | Partial, no attachments |
| 15 | Templates | Missing |
| 16 | Notifications | Partial, in app only live |
| 17 | Automation | Missing |
| 18 | AI with human approval | Fail, no preview |
| 19 | Dashboards on real data | Partial, real but one team view |
| 20 | Filter, sort, search | Partial |
| 21 | Sharing and permissions | Partial, team roles only |
| 22 | Tenant isolation | Tested in part, sweep to run |
| 23 | Keyboard | Partial |
| 24 | Touch | Fallback only |
| 25 | Responsive | Pass |
| 26 | Large collections | Not measured |
| 27 | Interrupted connections | Missing |

## Plan

Each piece is one pull request with tests. Nothing deploys until the owner says so.

| Phase | Work |
|---|---|
| **T1 Foundations** | Fix recurrence, then add rate limits, `/ready`, health probes, Tailwind build in place of the CDN, browser tests in CI |
| **T2 Design** | Competitor study, installed design skills, three directions for the owner to choose, then DESIGN.md and the shell |
| **T3 Core task** | Inline editing of every field, quick add, inline add, duplicate, manual subtasks, checklists, completion, activity history |
| **T4 Direct manipulation** | Real positions, a drag engine for pointer, touch and keyboard, optimistic moves with rollback. List, board, calendar, timeline, My Day, Team |
| **T5 Depth** | Custom statuses, dependency types, recurrence rewrite, custom fields, templates, comments with replies, attachments, watchers, notification preferences |
| **T6 Automation and AI** | Rule engine, then natural language, breakdown with preview, clarification, summaries, smart priority |
| **T7 Dashboards** | Personal, team and business dashboards, then reports with clear definitions |
| **T8 Ecosystem** | Dot.Files attachments, Dot.Projects link, Notify events, Analytics |
| **T9 Readiness** | Route sweep, enforced script policy, audit log, performance numbers, accessibility, recovery, the delivery report |

T1 to T4 are the product. If time runs short, they alone make Dot.Tasks better than it is.

## Decisions for the owner

1. **Who owns project tasks.** Recommended: Dot.Projects keeps them and Dot.Tasks links to them, as set out above.
2. **Natural language without AI.** Recommended: a rule-based reader for dates and names first, which works with no key and no cost. AI adds to it once a provider is chosen. Your example sentence can be read this way.
3. **AI provider and monthly cap.** Same answer as the other platforms. The code uses Anthropic today, and T6 needs it for breakdown, summaries and clarification.
4. **Live updates.** `/up/realtime` is 503 on the shared host. Recommended: the 20-second refresh used on Dot.Sheets, keeping Reverb as an option.
5. **Drag library.** The app's rules ask for approval to add a dependency. Recommended: SortableJS, which is small and covers touch and auto-scroll, with keyboard moves built by hand. Timeline and calendar moves use the same pattern.
6. **Time zones.** Recommended: one per business, as on Dot.HR, with a person's own zone later.
7. **Where tasks without a list live.** The brief wants tasks with no project. Recommended: every person gets a private Inbox list, so nothing needs a board first.
