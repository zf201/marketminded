# Layout Overhaul Design

**Date:** 2026-04-04
**Goal:** Transform MarketMinded from a developer prototype into a professional-looking web application by overhauling the layout shell, navigation, and visual theme.

## Decisions

- **Sidebar navigation** (dark panel style) for all project pages
- **Project-focused dashboard** — polished project cards, no sidebar on dashboard
- **Neutral dark theme** — near-black backgrounds, gray tones, minimal color, content-focused (GitHub/Vercel aesthetic)
- **Remove DaisyUI** — pure Tailwind CSS for full control

## Layout Structure

### Dashboard (home page, no sidebar)

- Top bar: "MarketMinded" logo/text left, "Settings" link right
- Content area: centered, max-width container
- Project cards in a responsive grid (1/2/3 cols) with:
  - Project name, description
  - Status indicators (profile filled, pipeline runs count)
  - Hover state, subtle border
- "New Project" button in the header area

### Project Pages (sidebar + content)

- **Sidebar** (~240px fixed width):
  - Dark background (`#0a0a0f`), visually separated from content
  - Project name at top (bold)
  - Section group label: uppercase, muted
  - Nav links: Profile, Pipeline, Context & Memory, Chat, Storytelling, Settings
  - Active link: lighter background (`#1a1a25`), white text
  - Inactive links: muted gray text (`#71717a`), hover brightens
  - Bottom: "Back to Projects" link, global settings link
  - Mobile: sidebar hidden, hamburger menu toggles it as overlay

- **Main content area**:
  - Background: `#111118`
  - Breadcrumbs at top (small, muted)
  - Page title + actions row
  - Content scrolls independently; sidebar stays fixed

### Color System (Tailwind custom config)

| Role | Color | Tailwind equivalent |
|------|-------|-------------------|
| Page background | `#09090b` | zinc-950 |
| Sidebar background | `#0a0a0f` | custom |
| Card/surface | `#18181b` | zinc-900 |
| Card border | `#27272a` | zinc-800 |
| Elevated surface | `#27272a` | zinc-800 |
| Primary text | `#fafafa` | zinc-50 |
| Secondary text | `#a1a1aa` | zinc-400 |
| Muted text | `#52525b` | zinc-600 |
| Active/accent | `#f4f4f5` text on `#27272a` bg | zinc-100 on zinc-800 |
| Primary button | `#fafafa` text on `#27272a` bg, hover `#3f3f46` | — |
| Success | `#22c55e` | green-500 |
| Warning | `#eab308` | yellow-500 |
| Error | `#ef4444` | red-500 |
| Info | `#3b82f6` | blue-500 |

### Component Replacements

All DaisyUI component classes will be replaced with Tailwind utilities. Where repetition is excessive, define minimal custom classes in `input.css`.

| DaisyUI | Replacement |
|---------|-------------|
| `btn btn-primary` | `px-4 py-2 bg-zinc-800 text-zinc-100 rounded-lg hover:bg-zinc-700 font-medium text-sm transition-colors` |
| `btn btn-ghost` | `px-4 py-2 text-zinc-400 hover:text-zinc-100 hover:bg-zinc-800 rounded-lg text-sm transition-colors` |
| `btn btn-secondary` | `px-3 py-1.5 bg-zinc-800 text-zinc-300 rounded-lg hover:bg-zinc-700 text-sm transition-colors` |
| `btn btn-error` | `px-3 py-1.5 bg-red-500/10 text-red-400 rounded-lg hover:bg-red-500/20 text-sm transition-colors` |
| `btn btn-success` | `px-3 py-1.5 bg-green-500/10 text-green-400 rounded-lg hover:bg-green-500/20 text-sm transition-colors` |
| `card` | `bg-zinc-900 border border-zinc-800 rounded-xl` |
| `card-body` | `p-4` or `p-5` |
| `badge` | `px-2 py-0.5 text-xs font-medium rounded-full` with color variants |
| `input input-bordered` | `w-full bg-zinc-900 border border-zinc-800 rounded-lg px-3 py-2 text-sm text-zinc-100 placeholder-zinc-600 focus:border-zinc-600 focus:outline-none` |
| `textarea textarea-bordered` | Same as input but with `min-h-[80px]` |
| `modal` | Keep `<dialog>`, style with `bg-zinc-900 border border-zinc-800 rounded-xl shadow-2xl` |
| `navbar` | Replace with custom top bar or sidebar |
| `alert` | `rounded-lg px-4 py-3 text-sm` with color-specific bg/text |
| `steps` | Custom step indicator with Tailwind flex/circles |

### Custom Classes in input.css

Define these to avoid extreme repetition in templates:

```css
@layer components {
  .btn { @apply px-4 py-2 rounded-lg font-medium text-sm transition-colors inline-flex items-center justify-center gap-2; }
  .btn-primary { @apply bg-zinc-100 text-zinc-900 hover:bg-white; }
  .btn-secondary { @apply bg-zinc-800 text-zinc-300 hover:bg-zinc-700; }
  .btn-ghost { @apply text-zinc-400 hover:text-zinc-100 hover:bg-zinc-800; }
  .btn-danger { @apply bg-red-500/10 text-red-400 hover:bg-red-500/20; }
  .btn-success { @apply bg-green-500/10 text-green-400 hover:bg-green-500/20; }
  .btn-sm { @apply px-3 py-1.5 text-xs; }
  .btn-xs { @apply px-2 py-1 text-xs; }

  .badge { @apply px-2 py-0.5 text-xs font-medium rounded-full; }
  .badge-success { @apply bg-green-500/10 text-green-400; }
  .badge-warning { @apply bg-yellow-500/10 text-yellow-400; }
  .badge-error { @apply bg-red-500/10 text-red-400; }
  .badge-info { @apply bg-blue-500/10 text-blue-400; }
  .badge-ghost { @apply bg-zinc-800 text-zinc-500; }

  .input { @apply w-full bg-zinc-900 border border-zinc-800 rounded-lg px-3 py-2 text-sm text-zinc-100 placeholder-zinc-600 focus:border-zinc-600 focus:outline-none; }

  .card { @apply bg-zinc-900 border border-zinc-800 rounded-xl; }
}
```

### Files to Modify

1. **`tailwind.config.js`** — Add custom colors for sidebar, remove DaisyUI plugin
2. **`web/static/input.css`** — Remove DaisyUI imports, add custom component classes, base styles
3. **`web/templates/components/layout.templ`** — New layout shells with sidebar
4. **`web/templates/components/card.templ`** — Restyle with Tailwind
5. **`web/templates/components/form.templ`** — Restyle with Tailwind
6. **`web/templates/components/badge.templ`** — Restyle with Tailwind
7. **`web/templates/components/modal.templ`** — New if needed for shared modal markup
8. **`web/templates/dashboard.templ`** — Polished project cards, new top bar
9. **`web/templates/project.templ`** — Use sidebar shell
10. **`web/templates/profile.templ`** — Use sidebar shell, restyle DaisyUI classes
11. **`web/templates/pipeline.templ`** — Use sidebar shell, replace steps/cards
12. **`web/templates/brainstorm.templ`** — Use sidebar shell
13. **`web/templates/context.templ`** — Use sidebar shell
14. **`web/templates/context_memory.templ`** — Use sidebar shell
15. **`web/templates/settings.templ`** — Use sidebar shell (global) or top bar
16. **`web/templates/project_settings.templ`** — Use sidebar shell
17. **`web/templates/storytelling.templ`** — Use sidebar shell
18. **`web/templates/project_new.templ`** — Top bar shell (no sidebar, not in a project yet)
19. **`package.json`** — Remove DaisyUI dependency

### What Stays the Same

- All page content, forms, data structures, and functionality
- Alpine.js interactions and all JavaScript
- Chat drawer (restyled but same structure)
- Template file structure
- Go types and handler code

### Mobile Behavior

- Sidebar hidden by default on screens < 768px
- Hamburger button in top bar toggles sidebar as overlay
- Overlay has backdrop click to close
- Alpine.js `x-data` / `x-show` for toggle state
