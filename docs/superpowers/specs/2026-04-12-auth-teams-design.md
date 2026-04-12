# Auth & Teams Foundation — Design Spec

## Context

MarketMinded is being ported from Go (Templ + Alpine.js + SQLite) to Laravel 13 (Livewire + Flux Pro + PostgreSQL). The Go app has zero authentication — all 18 tables hang off `projects` as the root entity with no ownership model.

This spec defines the auth, teams, and single-database tenancy foundation that all ported features will build on.

## Tech Stack

- Laravel 13 with Sail (Docker)
- Livewire 4 + Flux Pro for UI
- PostgreSQL 18
- Session-based auth (database driver)

---

## 1. Authentication

### Registration
- Email + password only (no OAuth)
- Livewire component with Flux form components
- Fields: name, email, password, password confirmation
- After registration: redirect to dashboard (empty "no teams" state)
- No team is auto-created — user can wait to be added to a team or create one themselves

### Login
- Email + password with "remember me" checkbox
- Livewire component with Flux form components
- After login: redirect to first team's project list (ordered by most recently created), or "no teams" state if user has no teams

### Password Reset
- Standard Laravel password reset flow
- Request form → email with reset link → reset form
- Livewire components with Flux UI

### Email Verification
- Not required at launch
- User model has `email_verified_at` ready — can be enabled later by adding `MustVerifyEmail` interface

### Auth Pages
- Minimal centered card layout
- Flux UI components for all form elements
- Guest-only routes (redirect to dashboard if already authenticated)

---

## 2. Teams & Membership

### Teams Table
```
teams
├── id            bigint PK
├── name          string
├── owner_id      FK → users
└── timestamps
```

- One owner per team (the creator)
- Ownership is transferable (owner can promote another admin to owner)
- Deleting a team cascades to all projects and their nested data

### Team Members (Pivot)
```
team_user
├── id            bigint PK
├── team_id       FK → teams
├── user_id       FK → users
├── role          enum: owner, admin, editor, viewer
└── timestamps
unique(team_id, user_id)
```

### Roles & Permissions

| Role | Manage Members | Team Settings | Create/Edit Projects | View Projects | Run Pipelines |
|------|---------------|---------------|---------------------|--------------|---------------|
| Owner | Yes (all) | Yes | Yes | Yes | Yes |
| Admin | Yes (except remove owner) | Yes | Yes | Yes | Yes |
| Editor | No | No | Yes | Yes | Yes |
| Viewer | No | No | No | Yes | No |

### Adding Members
- Owners and admins can add members directly by email
- The user must already have a registered account
- If the email doesn't match an existing user, show an error (no invite flow)
- Members can be removed by owners/admins (owner cannot be removed)
- Roles can be changed by owners/admins

### Team Management UI
- Team settings page at `/teams/{team}/settings`
- Member list with role badges
- Add member form: email input + role selector
- Remove member button (with confirmation)
- Change role dropdown per member
- Transfer ownership action (owner only)

---

## 3. Routing & Tenancy

### URL Structure
All authenticated routes are prefixed with `/teams/{team}`:

```
GET  /                                          → Dashboard (team list or redirect)
GET  /teams/create                              → Create team form
POST /teams                                     → Store team

GET  /teams/{team}/projects                     → Project list
GET  /teams/{team}/projects/create              → New project form
GET  /teams/{team}/projects/{project}           → Project detail
GET  /teams/{team}/projects/{project}/pipeline  → Pipeline view
...etc (all existing Go routes, nested under team)

GET  /teams/{team}/settings                     → Team settings + members
GET  /teams/{team}/settings/members             → Member management
```

Auth routes (no team prefix):
```
GET  /login
GET  /register
GET  /forgot-password
GET  /reset-password/{token}
POST /logout
```

### Team Resolution Middleware
- Resolves `{team}` from URL via route model binding
- Checks authenticated user is a member of the team
- Aborts 403 if not a member
- Sets current team in request context (available via `$request->team()` or similar helper)

### Role Authorization
- Laravel Gates/Policies for permission checks
- `TeamPolicy` handles: `viewAny`, `view`, `update`, `delete`, `manageMembers`, `addMember`, `removeMember`
- `ProjectPolicy` handles: `viewAny`, `view`, `create`, `update`, `delete` — checks team membership and role
- Middleware shorthand: `can:manageMembers,team` in route definitions

---

## 4. Data Model

### New Tables
- `users` — Laravel default (already exists in migrations)
- `teams` — team entity with owner reference
- `team_user` — pivot with role enum

### Modified Tables (vs Go schema)
- `projects` — adds `team_id` FK → teams (cascade delete)
- `settings` — adds `team_id` FK → teams, unique constraint becomes `(team_id, key)` instead of just `key`

### Unchanged Tables
All other tables retain their `project_id` FK unchanged:
- `profile_sections`, `profile_section_versions`
- `voice_tone_profiles`
- `audience_personas`
- `pipeline_runs`, `pipeline_steps`
- `content_pieces`
- `topic_runs`, `topic_steps`, `topic_backlog`
- `brainstorm_chats`, `brainstorm_messages`
- `context_items`, `project_settings`, `templates`

### Tenancy Scoping Strategy
- **Team-level tables** (`projects`, `settings`): scoped by `team_id` directly via Eloquent global scope
- **Project-level tables** (everything else): scoped through `project_id` → `projects.team_id` — no direct `team_id` column needed
- **Global scope on Project model**: automatically filters by current team when team context is set
- **Project access middleware**: verifies `{project}` belongs to current `{team}` in URL

---

## 5. Eloquent Models & Relationships

### User
```
belongsToMany(Team::class)->withPivot('role')->withTimestamps()
ownedTeams(): hasMany(Team::class, 'owner_id')
currentTeamRole(Team $team): string — helper to get role in a specific team
```

### Team
```
owner(): belongsTo(User::class, 'owner_id')
members(): belongsToMany(User::class)->withPivot('role')->withTimestamps()
projects(): hasMany(Project::class)
settings(): hasMany(Setting::class)
```

### Project
```
team(): belongsTo(Team::class)
// ...all existing relationships (personas, pipelines, etc.)
// Global scope: automatically filters by current team
```

---

## 6. Key Implementation Decisions

- **No Jetstream/Breeze** — we're building on Flux Pro directly to keep full control and avoid framework opinions that conflict with Flux's component system
- **No invite emails** — members are added directly, must already have an account
- **No personal teams** — registration creates no team; user starts with empty state
- **Settings become team-scoped** — the Go app had global settings; in Laravel they belong to a team (model defaults, feature flags)
- **Session stores current team** — for convenience in non-URL contexts (e.g., API calls later), but URL is the source of truth for web requests
- **No `agent_runs` table** — legacy table from Go app, not ported
