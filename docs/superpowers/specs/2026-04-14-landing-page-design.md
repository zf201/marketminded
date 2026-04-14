# Landing page design

## Context

The current `/` route renders Laravel's default `welcome.blade.php` (the starter-kit splash). MarketMinded is now a working product — blog-first AI copywriting tool, BYOK, open beta — and needs a real landing page to drive trial signups.

Core messaging:
- **Positioning:** Copywriting tool focused on blogs (primary) and social (secondary).
- **Differentiators:** Three specialist agents (brand strategist / topic researcher / writer) reading from a shared brand intelligence profile; bring-your-own-key.
- **Status:** Open beta, $25/month for single team / single seat.
- **Goal:** Drive registrations.

## Scope

Replace `resources/views/welcome.blade.php` with a single-page Flux-based landing page. Static Blade, no Livewire. Route, route name (`home`), and the existing `canRegister` view data remain unchanged.

## Page structure

Single-column, centered max-width container (`max-w-5xl` to align with the rest of the app per the layout spec). Flux UI components throughout. Instrument Sans is already loaded via the existing layout patterns.

### 1. Header (non-sticky)
- Left: `<x-app-logo />`
- Right:
  - `flux:button variant="ghost"` **Log in** → `route('login')`
  - `flux:button variant="primary"` **Start trial** → `route('register')` — rendered only when `$canRegister` is true

### 2. Hero
- `flux:badge` "Open beta"
- Headline (`flux:heading size="xl"`): *Write blogs your brand would actually publish.*
- Subhead (`flux:subheading`): *Specialist AI agents that learn your brand, pick the right topics, and draft blog + social copy. Bring your own API key.*
- Primary CTA: **Start trial** (hidden if `!$canRegister`)
- Secondary CTA: **Log in** (ghost variant)

### 3. Feature strip
Responsive grid — three columns on `md+`, stacked on mobile. Each cell is a `flux:card` with a Heroicon, heading, and short body:

| Icon | Heading | Body |
|---|---|---|
| `sparkles` | Brand intelligence | A living profile of your positioning, personas, and voice. Every agent reads from it. |
| `chat-bubble-left-right` | Specialist agents | Brand strategist, topic researcher, and writer — each tuned for its step, not one generic chatbot. |
| `key` | Bring your own key | Plug in your own model key. Your usage, your control, no markup. |

### 4. Pricing callout
Single centered `flux:card`:
- Large **$25 / month**
- "Single team, single seat. Open beta pricing."
- BYOK footnote: "You supply your own AI model key."
- CTA: **Start trial** (hidden if `!$canRegister`) or **Log in** fallback

### 5. Footer
- `<x-app-logo />` (small)
- `© {{ date('Y') }} MarketMinded`
- Link to Log in

## Non-goals

- No waitlist/email-capture form.
- No FAQ, testimonials, product screenshots, or long-form marketing copy.
- No analytics or tracking additions.
- No new tests — this is a static marketing view; existing route coverage is sufficient.
- No changes to auth flows, registration, or pricing enforcement.

## Files touched

- `marketminded-laravel/resources/views/welcome.blade.php` — full replacement.

Everything else (routes, controllers, config, assets) is unchanged.
