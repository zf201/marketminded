# Profile Chat Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the form-based profile page with a chat-first 70/30 layout where AI proposes card updates inline via `[UPDATE]` markers, users accept/reject, and cards are directly editable.

**Architecture:** Rewrite profile handler + template + JS. Trim store layer (drop input/proposal/reference CRUD). Remove ProfileAgent. Profile building happens via chat with a well-crafted system prompt. Frontend parses `[UPDATE]` markers from the SSE stream with a buffered scanner.

**Tech Stack:** Go, templ, vanilla JS (SSE), SQLite, OpenRouter

---

## File Map

```
Modify:
  migrations/001_initial.sql              — remove section_inputs, section_proposals, project_references tables; update profile_sections DEFAULT
  internal/store/profile.go               — trim to just 4 functions + update BuildProfileString filter
  internal/store/profile_test.go          — trim tests to match
  internal/store/brainstorm.go            — add GetOrCreateProfileChat
  cmd/server/main.go                      — remove ProfileAgent, simplify ProfileHandler constructor
  web/static/style.css                    — add profile layout styles
  web/static/app.js                       — add [UPDATE] marker parser for profile chat

Rewrite:
  web/handlers/profile.go                 — chat-based handler (page, message, stream, section edit)
  web/templates/profile.templ             — 70/30 layout with chat + cards

Delete:
  internal/agents/profile.go
  internal/agents/profile_test.go
```

---

## Chunk 1: Schema + Store Cleanup

### Task 1: Clean up migration and store

**Files:**
- Modify: `migrations/001_initial.sql`
- Modify: `internal/store/profile.go`
- Modify: `internal/store/profile_test.go`
- Modify: `internal/store/brainstorm.go`
- Delete: `internal/agents/profile.go`
- Delete: `internal/agents/profile_test.go`

- [ ] **Step 1: Update migrations/001_initial.sql**

Remove these tables entirely: `section_inputs`, `section_proposals`, `project_references` and their indexes. Update `profile_sections.content` DEFAULT from `'{}'` to `''`. Keep everything else.

- [ ] **Step 2: Rewrite internal/store/profile.go**

Keep only: `ProfileSection` type, `UpsertProfileSection`, `GetProfileSection`, `ListProfileSections`, `BuildProfileString`, `sectionTitle`. Delete everything else (types `SectionInput`, `SectionProposal`, `ProjectReference` and all their CRUD methods).

Update `BuildProfileString` to skip on `s.Content == ""` instead of `s.Content == "{}"`.

```go
package store

import (
	"fmt"
	"strings"
	"time"
)

type ProfileSection struct {
	ID        int64
	ProjectID int64
	Section   string
	Content   string
	UpdatedAt time.Time
}

func (q *Queries) UpsertProfileSection(projectID int64, section, content string) error {
	_, err := q.db.Exec(
		`INSERT INTO profile_sections (project_id, section, content) VALUES (?, ?, ?)
		 ON CONFLICT(project_id, section) DO UPDATE SET content = ?, updated_at = CURRENT_TIMESTAMP`,
		projectID, section, content, content,
	)
	return err
}

func (q *Queries) GetProfileSection(projectID int64, section string) (*ProfileSection, error) {
	s := &ProfileSection{}
	err := q.db.QueryRow(
		"SELECT id, project_id, section, content, updated_at FROM profile_sections WHERE project_id = ? AND section = ?",
		projectID, section,
	).Scan(&s.ID, &s.ProjectID, &s.Section, &s.Content, &s.UpdatedAt)
	return s, err
}

func (q *Queries) ListProfileSections(projectID int64) ([]ProfileSection, error) {
	rows, err := q.db.Query(
		"SELECT id, project_id, section, content, updated_at FROM profile_sections WHERE project_id = ? ORDER BY section",
		projectID,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var sections []ProfileSection
	for rows.Next() {
		var s ProfileSection
		if err := rows.Scan(&s.ID, &s.ProjectID, &s.Section, &s.Content, &s.UpdatedAt); err != nil {
			return nil, err
		}
		sections = append(sections, s)
	}
	return sections, rows.Err()
}

func (q *Queries) BuildProfileString(projectID int64) (string, error) {
	sections, err := q.ListProfileSections(projectID)
	if err != nil {
		return "", err
	}
	var b strings.Builder
	for _, s := range sections {
		if s.Content == "" {
			continue
		}
		fmt.Fprintf(&b, "## %s\n%s\n\n", sectionTitle(s.Section), s.Content)
	}
	return b.String(), nil
}

func sectionTitle(s string) string {
	if s == "" {
		return s
	}
	return strings.ToUpper(s[:1]) + s[1:]
}
```

- [ ] **Step 3: Trim profile_test.go**

Keep only: `TestProfileSectionUpsert`, `TestListProfileSections`. Delete all input/proposal/reference tests.

```go
package store

import "testing"

func TestProfileSectionUpsert(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	err := q.UpsertProfileSection(p.ID, "voice", "Bold and confident voice")
	if err != nil {
		t.Fatalf("upsert: %v", err)
	}

	section, err := q.GetProfileSection(p.ID, "voice")
	if err != nil {
		t.Fatalf("get: %v", err)
	}
	if section.Content != "Bold and confident voice" {
		t.Errorf("unexpected content: %s", section.Content)
	}

	// Update existing
	q.UpsertProfileSection(p.ID, "voice", "Confident and irreverent")
	section, _ = q.GetProfileSection(p.ID, "voice")
	if section.Content != "Confident and irreverent" {
		t.Errorf("expected updated content, got: %s", section.Content)
	}
}

func TestListProfileSections(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	q.UpsertProfileSection(p.ID, "voice", "Bold voice")
	q.UpsertProfileSection(p.ID, "tone", "Casual tone")

	sections, err := q.ListProfileSections(p.ID)
	if err != nil {
		t.Fatalf("list: %v", err)
	}
	if len(sections) != 2 {
		t.Errorf("expected 2, got %d", len(sections))
	}
}

func TestBuildProfileString(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	q.UpsertProfileSection(p.ID, "voice", "Bold voice")
	q.UpsertProfileSection(p.ID, "tone", "")  // empty, should be skipped

	profile, _ := q.BuildProfileString(p.ID)
	if !strings.Contains(profile, "Bold voice") {
		t.Errorf("expected voice content in profile string")
	}
	if strings.Contains(profile, "Tone") {
		t.Errorf("empty tone should be skipped")
	}
}
```

Note: needs `"strings"` import for `TestBuildProfileString`.

- [ ] **Step 4: Add GetOrCreateProfileChat to brainstorm.go**

```go
func (q *Queries) GetOrCreateProfileChat(projectID int64) (*BrainstormChat, error) {
	c := &BrainstormChat{}
	err := q.db.QueryRow(
		"SELECT id, project_id, COALESCE(title,''), COALESCE(section,''), created_at FROM brainstorm_chats WHERE project_id = ? AND section = 'profile'",
		projectID,
	).Scan(&c.ID, &c.ProjectID, &c.Title, &c.Section, &c.CreatedAt)
	if err == nil {
		return c, nil
	}
	// Not found, create it
	return q.CreateBrainstormChat(projectID, "Profile Builder", "profile")
}
```

- [ ] **Step 5: Delete old agent files**

```bash
rm internal/agents/profile.go internal/agents/profile_test.go
```

- [ ] **Step 6: Delete DB, run tests**

```bash
rm -f marketminded.db
go test ./internal/store/ -v
go test ./internal/agents/ -v
# Expected: PASS
```

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "refactor: trim profile store, drop inputs/proposals/references, add GetOrCreateProfileChat"
```

---

## Chunk 2: Profile Handler + Template

### Task 2: Rewrite profile handler

**Files:**
- Rewrite: `web/handlers/profile.go`

The new handler is much simpler — a chat page, not a form page. No ProfileAgent dependency.

```go
package handlers

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strings"

	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/internal/types"
	"github.com/zanfridau/marketminded/web/templates"
)

var allSections = []string{
	"business", "audience", "voice", "tone", "strategy",
	"pillars", "guidelines", "competitors", "inspiration", "offers",
}

type ProfileHandler struct {
	queries *store.Queries
	ai      types.AIClient
	model   func() string
}

func NewProfileHandler(q *store.Queries, ai types.AIClient, model func() string) *ProfileHandler {
	return &ProfileHandler{queries: q, ai: ai, model: model}
}

func (h *ProfileHandler) Handle(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	switch {
	case rest == "profile" && r.Method == "GET":
		h.show(w, r, projectID)
	case rest == "profile/message" && r.Method == "POST":
		h.saveMessage(w, r, projectID)
	case rest == "profile/stream" && r.Method == "GET":
		h.stream(w, r, projectID)
	case strings.HasPrefix(rest, "profile/sections/") && r.Method == "POST":
		h.saveSection(w, r, projectID, rest)
	default:
		http.NotFound(w, r)
	}
}

func (h *ProfileHandler) show(w http.ResponseWriter, r *http.Request, projectID int64) {
	project, err := h.queries.GetProject(projectID)
	if err != nil {
		http.NotFound(w, r)
		return
	}

	chat, _ := h.queries.GetOrCreateProfileChat(projectID)
	msgs, _ := h.queries.ListBrainstormMessages(chat.ID)
	sections, _ := h.queries.ListProfileSections(projectID)

	sectionMap := make(map[string]string)
	for _, s := range sections {
		sectionMap[s.Section] = s.Content
	}

	cardViews := make([]templates.ProfileCardView, len(allSections))
	for i, name := range allSections {
		cardViews[i] = templates.ProfileCardView{
			Section: name,
			Title:   sectionTitle(name),
			Content: sectionMap[name],
		}
	}

	msgViews := make([]templates.ProfileMsgView, len(msgs))
	for i, m := range msgs {
		msgViews[i] = templates.ProfileMsgView{Role: m.Role, Content: m.Content}
	}

	templates.ProfilePage(templates.ProfilePageData{
		ProjectID:   projectID,
		ProjectName: project.Name,
		Cards:       cardViews,
		Messages:    msgViews,
	}).Render(r.Context(), w)
}

func (h *ProfileHandler) saveMessage(w http.ResponseWriter, r *http.Request, projectID int64) {
	r.ParseForm()
	content := r.FormValue("content")
	if content == "" {
		http.Error(w, "Content required", http.StatusBadRequest)
		return
	}

	chat, _ := h.queries.GetOrCreateProfileChat(projectID)
	h.queries.AddBrainstormMessage(chat.ID, "user", content)
	w.WriteHeader(http.StatusOK)
}

func (h *ProfileHandler) stream(w http.ResponseWriter, r *http.Request, projectID int64) {
	project, _ := h.queries.GetProject(projectID)
	chat, _ := h.queries.GetOrCreateProfileChat(projectID)
	msgs, _ := h.queries.ListBrainstormMessages(chat.ID)

	// Build current profile state for system prompt
	var profileState strings.Builder
	for _, name := range allSections {
		section, err := h.queries.GetProfileSection(projectID, name)
		if err != nil || section.Content == "" {
			fmt.Fprintf(&profileState, "- **%s**: (empty)\n", sectionTitle(name))
		} else {
			fmt.Fprintf(&profileState, "- **%s**: %s\n", sectionTitle(name), section.Content)
		}
	}

	systemPrompt := fmt.Sprintf(`You are a brand profile builder. Your job is to learn about this client through natural conversation and build out their content marketing profile.

You have 10 profile sections to fill:

1. **Business** — What the company does, who they serve, their industry, and what makes them different
2. **Audience** — Who they're trying to reach: demographics, roles, pain points, aspirations, and what content they consume
3. **Voice** — How the brand sounds: personality traits, vocabulary style, sentence patterns, characteristic phrases
4. **Tone** — The emotional register: formality level, humor, warmth, persuasion approach, how they relate to the audience
5. **Strategy** — Content goals (awareness, leads, authority), which platforms to publish on, posting frequency per platform
6. **Pillars** — The 3-5 core topic categories all content revolves around
7. **Guidelines** — Specific rules: words/phrases to always use or avoid, formatting preferences, brand-specific dos and don'ts
8. **Competitors** — Key competitors, what they do well in content, where they fall short, opportunities to differentiate
9. **Inspiration** — Creators, brands, or specific content the client admires and wants to emulate (not necessarily competitors)
10. **Offers** — Products/services they sell, primary call-to-action, secondary CTAs, what content should ultimately drive people toward

## Current profile state for "%s"

%s

## How to propose updates

When you learn something relevant to a section, propose an update using this exact format:

[UPDATE:section_name]
Write the full updated content for this section here.
Use clear, natural prose. Not JSON. Not raw bullet lists unless they genuinely fit.
If the section already has content, rewrite it to incorporate both old and new information.
[/UPDATE]

## Rules

- Propose one section update at a time. If you have updates for multiple sections from a single message, include them all in your response but each as a separate [UPDATE] block.
- Always rewrite the full section content when updating — do not write diffs or "add this to existing."
- After proposing updates, continue the conversation. Ask follow-up questions to fill gaps in other sections.
- Do not make up information. Only propose updates based on what the user has actually told you.
- Be conversational and concise. Don't lecture. Don't repeat back everything the user said.
- If the user gives you a large dump of info (like a website paste), process it methodically — propose the most important sections first.
- If a proposal is rejected, acknowledge it briefly and move on. You'll see rejected proposals in the chat history.`, project.Name, profileState.String())

	aiMsgs := []types.Message{{Role: "system", Content: systemPrompt}}
	for _, m := range msgs {
		aiMsgs = append(aiMsgs, types.Message{Role: m.Role, Content: m.Content})
	}

	// SSE headers
	w.Header().Set("Content-Type", "text/event-stream")
	w.Header().Set("Cache-Control", "no-cache")
	w.Header().Set("Connection", "keep-alive")

	flusher, ok := w.(http.Flusher)
	if !ok {
		http.Error(w, "Streaming not supported", http.StatusInternalServerError)
		return
	}

	sendChunk := func(chunk string) error {
		data, _ := json.Marshal(map[string]string{"chunk": chunk})
		fmt.Fprintf(w, "data: %s\n\n", data)
		flusher.Flush()
		return nil
	}

	fullResponse, err := h.ai.Stream(r.Context(), h.model(), aiMsgs, sendChunk)
	if err != nil {
		errData, _ := json.Marshal(map[string]string{"error": err.Error()})
		fmt.Fprintf(w, "data: %s\n\n", errData)
		flusher.Flush()
		return
	}

	h.queries.AddBrainstormMessage(chat.ID, "assistant", fullResponse)

	doneData, _ := json.Marshal(map[string]bool{"done": true})
	fmt.Fprintf(w, "data: %s\n\n", doneData)
	flusher.Flush()
}

func (h *ProfileHandler) saveSection(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	// rest = "profile/sections/voice"
	section := strings.TrimPrefix(rest, "profile/sections/")
	r.ParseForm()
	content := r.FormValue("content")
	h.queries.UpsertProfileSection(projectID, section, content)
	w.WriteHeader(http.StatusOK)
}

func sectionTitle(s string) string {
	if s == "" {
		return s
	}
	return strings.ToUpper(s[:1]) + s[1:]
}
```

- [ ] **Step 1: Write the handler file as shown above**
- [ ] **Step 2: Update main.go**

Change `NewProfileHandler` call. Remove `profileAgent`. The new signature is:

```go
profileHandler := handlers.NewProfileHandler(queries, aiClient, contentModel)
```

Remove the `profileAgent` line and the `agents` import if no longer used in main.go (it's still used for ideaAgent/contentAgent).

- [ ] **Step 3: Build to verify**

```bash
go build ./...
```

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "feat: rewrite profile handler for chat-first experience"
```

---

### Task 3: Profile template (70/30 layout)

**Files:**
- Rewrite: `web/templates/profile.templ`

```
package templates

import "fmt"

type ProfilePageData struct {
	ProjectID   int64
	ProjectName string
	Cards       []ProfileCardView
	Messages    []ProfileMsgView
}

type ProfileCardView struct {
	Section string
	Title   string
	Content string
}

type ProfileMsgView struct {
	Role    string
	Content string
}

templ ProfilePage(data ProfilePageData) {
	@Layout(data.ProjectName + " - Profile") {
		<div class="flex-between mb-4">
			<h1>{ data.ProjectName } Profile</h1>
			<a href={ templ.SafeURL(fmt.Sprintf("/projects/%d", data.ProjectID)) } class="btn btn-secondary">Back to Project</a>
		</div>

		<div class="profile-layout">
			<div class="profile-chat">
				<div class="chat-messages" id="profile-messages">
					for _, msg := range data.Messages {
						<div class={ "chat-msg chat-msg-" + msg.Role }>
							<div class="chat-msg-role">{ msg.Role }</div>
							<div style="white-space:pre-wrap">{ msg.Content }</div>
						</div>
					}
				</div>

				<form id="profile-chat-form" class="mt-2">
					<div class="form-group">
						<textarea id="profile-chat-input" placeholder="Tell me about this client — paste a website, brand docs, or just describe what they do..." style="min-height:80px"></textarea>
					</div>
					<button type="submit" id="profile-chat-send" class="btn">Send</button>
				</form>
			</div>

			<div class="profile-cards">
				for _, card := range data.Cards {
					<div class={ "profile-card" + cardClass(card.Content) } id={ "card-" + card.Section } data-section={ card.Section }>
						<div class="profile-card-header">
							<strong>{ card.Title }</strong>
							<button class="btn btn-secondary profile-card-edit-btn" style="font-size:0.7rem;padding:0.2rem 0.5rem" onclick={ templ.SafeScript(fmt.Sprintf("editCard('%s')", card.Section)) }>Edit</button>
						</div>
						<div class="profile-card-content">
							if card.Content != "" {
								<p style="white-space:pre-wrap">{ card.Content }</p>
							} else {
								<p class="text-muted" style="font-style:italic">Not yet filled</p>
							}
						</div>
					</div>
				}
			</div>
		</div>

		<script>
			// Profile chat + [UPDATE] marker parsing
			// Inlined here to keep it self-contained with the template
		</script>
	}
}

func cardClass(content string) string {
	if content == "" {
		return " profile-card-empty"
	}
	return ""
}
```

NOTE: The actual `<script>` content goes in Task 4 (app.js). The templ file just includes a small inline script tag that calls `initProfileChat(projectID)` from app.js. Using templ's `fmt.Sprintf` for the project ID:

Replace the `<script>` block with:
```
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof initProfileChat === 'function') {
        initProfileChat(/* projectID */);
    }
});
</script>
```

Actually, since templ escapes things, it's cleaner to pass projectID via a data attribute on a wrapper div and have app.js read it. Add `data-project-id` to the profile-layout div:

```
<div class="profile-layout" id="profile-page" data-project-id={ fmt.Sprintf("%d", data.ProjectID) }>
```

Then app.js reads `document.getElementById('profile-page').dataset.projectId`.

- [ ] **Step 1: Write the template file**
- [ ] **Step 2: Generate templ**

```bash
templ generate ./web/templates/
```

- [ ] **Step 3: Build to verify**

```bash
go build ./...
```

- [ ] **Step 4: Commit**

```bash
git add web/templates/profile.templ
git commit -m "feat: add profile chat template with 70/30 layout"
```

---

## Chunk 3: Frontend (CSS + JS)

### Task 4: Profile CSS styles

**Files:**
- Modify: `web/static/style.css`

Add these styles at the end:

```css
/* Profile page 70/30 layout */
.profile-layout { display: flex; gap: 1.5rem; min-height: calc(100vh - 150px); }
.profile-chat { flex: 7; display: flex; flex-direction: column; }
.profile-chat .chat-messages { flex: 1; overflow-y: auto; max-height: calc(100vh - 300px); }
.profile-cards { flex: 3; overflow-y: auto; max-height: calc(100vh - 150px); }
.profile-card { border: 1px solid #e5e5e5; border-radius: 8px; padding: 0.75rem; margin-bottom: 0.75rem; }
.profile-card-empty { opacity: 0.5; border-style: dashed; }
.profile-card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; }
.profile-card-content { font-size: 0.85rem; line-height: 1.4; }
.profile-card-content p { margin: 0; }
.profile-card-editing textarea { width: 100%; min-height: 80px; font-size: 0.85rem; margin-bottom: 0.5rem; }

/* Proposal blocks in chat */
.proposal-block { border: 2px solid #3b82f6; border-radius: 8px; padding: 0.75rem; margin: 0.5rem 0; background: #eff6ff; }
.proposal-block-header { font-weight: 600; font-size: 0.8rem; color: #1e40af; margin-bottom: 0.5rem; text-transform: capitalize; }
.proposal-block-content { font-size: 0.85rem; white-space: pre-wrap; margin-bottom: 0.75rem; padding: 0.5rem; background: white; border-radius: 4px; }
.proposal-block-actions { display: flex; gap: 0.5rem; }
.proposal-block-accepted { border-color: #059669; background: #ecfdf5; }
.proposal-block-rejected { border-color: #d1d5db; background: #f9fafb; opacity: 0.6; }
.proposal-building { border: 2px dashed #3b82f6; border-radius: 8px; padding: 0.75rem; margin: 0.5rem 0; background: #eff6ff; }
.proposal-building-header { font-weight: 600; font-size: 0.8rem; color: #1e40af; }
```

- [ ] **Step 1: Add styles**
- [ ] **Step 2: Commit**

```bash
git add web/static/style.css
git commit -m "feat: add profile layout and proposal block CSS"
```

---

### Task 5: Profile chat JavaScript with [UPDATE] marker parsing

**Files:**
- Modify: `web/static/app.js`

Add the `initProfileChat` function at the end of app.js (outside the Alpine init block). This is the most complex piece — it handles:

1. Chat send (POST message → SSE stream)
2. Buffered stream parsing for `[UPDATE:x]...[/UPDATE]` markers
3. Rendering proposal blocks with Accept/Reject
4. Direct card editing
5. Accept → POST to save section + update card
6. Input disabled during streaming

```js
function initProfileChat(projectID) {
    var form = document.getElementById('profile-chat-form');
    var input = document.getElementById('profile-chat-input');
    var btn = document.getElementById('profile-chat-send');
    var messagesEl = document.getElementById('profile-messages');

    if (!form || !projectID) return;

    function scrollToBottom() {
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function addChatText(container, text) {
        var span = container.querySelector('.chat-text');
        if (!span) {
            span = document.createElement('span');
            span.className = 'chat-text';
            container.appendChild(span);
        }
        span.textContent += text;
    }

    function createProposalBlock(section, content) {
        var block = document.createElement('div');
        block.className = 'proposal-block';
        block.dataset.section = section;

        var header = document.createElement('div');
        header.className = 'proposal-block-header';
        header.textContent = 'Proposed update: ' + section;
        block.appendChild(header);

        var contentEl = document.createElement('div');
        contentEl.className = 'proposal-block-content';
        contentEl.textContent = content;
        block.appendChild(contentEl);

        var actions = document.createElement('div');
        actions.className = 'proposal-block-actions';

        var acceptBtn = document.createElement('button');
        acceptBtn.className = 'btn';
        acceptBtn.textContent = 'Accept';
        acceptBtn.style.fontSize = '0.8rem';
        acceptBtn.onclick = function() {
            fetch('/projects/' + projectID + '/profile/sections/' + section, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'content=' + encodeURIComponent(content)
            }).then(function() {
                block.className = 'proposal-block proposal-block-accepted';
                actions.remove();
                var badge = document.createElement('div');
                badge.style.cssText = 'color:#059669;font-size:0.8rem;font-weight:600';
                badge.textContent = 'Accepted';
                block.appendChild(badge);
                // Update card on the right
                var card = document.getElementById('card-' + section);
                if (card) {
                    var cardContent = card.querySelector('.profile-card-content');
                    cardContent.textContent = '';
                    var p = document.createElement('p');
                    p.style.whiteSpace = 'pre-wrap';
                    p.textContent = content;
                    cardContent.appendChild(p);
                    card.classList.remove('profile-card-empty');
                }
            });
        };

        var rejectBtn = document.createElement('button');
        rejectBtn.className = 'btn btn-secondary';
        rejectBtn.textContent = 'Reject';
        rejectBtn.style.fontSize = '0.8rem';
        rejectBtn.onclick = function() {
            block.className = 'proposal-block proposal-block-rejected';
            actions.remove();
            var badge = document.createElement('div');
            badge.style.cssText = 'color:#6b7280;font-size:0.8rem;font-weight:600';
            badge.textContent = 'Rejected';
            block.appendChild(badge);
        };

        actions.appendChild(acceptBtn);
        actions.appendChild(rejectBtn);
        block.appendChild(actions);

        return block;
    }

    function createBuildingIndicator(section) {
        var el = document.createElement('div');
        el.className = 'proposal-building';
        el.dataset.section = section;
        var header = document.createElement('div');
        header.className = 'proposal-building-header';
        header.textContent = 'Building proposal: ' + section + '...';
        el.appendChild(header);
        return el;
    }

    // Direct card editing
    window.editCard = function(section) {
        var card = document.getElementById('card-' + section);
        var contentEl = card.querySelector('.profile-card-content');
        var currentText = '';
        var p = contentEl.querySelector('p');
        if (p) currentText = p.textContent;

        contentEl.classList.add('profile-card-editing');
        var ta = document.createElement('textarea');
        ta.className = 'profile-card-editing';
        ta.value = currentText;
        ta.style.cssText = 'width:100%;min-height:80px;font-size:0.85rem;margin-bottom:0.5rem';

        var saveBtn = document.createElement('button');
        saveBtn.className = 'btn';
        saveBtn.textContent = 'Save';
        saveBtn.style.fontSize = '0.75rem';

        var cancelBtn = document.createElement('button');
        cancelBtn.className = 'btn btn-secondary';
        cancelBtn.textContent = 'Cancel';
        cancelBtn.style.cssText = 'font-size:0.75rem;margin-left:0.5rem';

        contentEl.textContent = '';
        contentEl.appendChild(ta);
        contentEl.appendChild(saveBtn);
        contentEl.appendChild(cancelBtn);

        saveBtn.onclick = function() {
            var newContent = ta.value;
            fetch('/projects/' + projectID + '/profile/sections/' + section, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'content=' + encodeURIComponent(newContent)
            }).then(function() {
                contentEl.textContent = '';
                contentEl.classList.remove('profile-card-editing');
                var np = document.createElement('p');
                np.style.whiteSpace = 'pre-wrap';
                np.textContent = newContent;
                contentEl.appendChild(np);
                if (newContent) card.classList.remove('profile-card-empty');
                else card.classList.add('profile-card-empty');
            });
        };

        cancelBtn.onclick = function() {
            contentEl.textContent = '';
            contentEl.classList.remove('profile-card-editing');
            var np = document.createElement('p');
            np.style.whiteSpace = 'pre-wrap';
            if (currentText) {
                np.textContent = currentText;
            } else {
                np.className = 'text-muted';
                np.style.fontStyle = 'italic';
                np.textContent = 'Not yet filled';
            }
            contentEl.appendChild(np);
        };

        ta.focus();
    };

    // Chat send with [UPDATE] marker parsing
    input.addEventListener('keydown', function(e) {
        if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') {
            e.preventDefault();
            form.dispatchEvent(new Event('submit'));
        }
    });

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        var msg = input.value.trim();
        if (!msg) return;

        input.disabled = true;
        btn.disabled = true;
        btn.textContent = 'Thinking...';
        input.value = '';

        // User message
        var userDiv = document.createElement('div');
        userDiv.className = 'chat-msg chat-msg-user';
        var roleEl = document.createElement('div');
        roleEl.className = 'chat-msg-role';
        roleEl.textContent = 'user';
        var bodyEl = document.createElement('div');
        bodyEl.style.whiteSpace = 'pre-wrap';
        bodyEl.textContent = msg;
        userDiv.appendChild(roleEl);
        userDiv.appendChild(bodyEl);
        messagesEl.appendChild(userDiv);

        // Assistant bubble
        var assistantDiv = document.createElement('div');
        assistantDiv.className = 'chat-msg chat-msg-assistant';
        var aRoleEl = document.createElement('div');
        aRoleEl.className = 'chat-msg-role';
        aRoleEl.textContent = 'assistant';
        assistantDiv.appendChild(aRoleEl);
        var aBody = document.createElement('div');
        aBody.style.whiteSpace = 'pre-wrap';
        assistantDiv.appendChild(aBody);
        messagesEl.appendChild(assistantDiv);
        scrollToBottom();

        // Stream with [UPDATE] parsing
        fetch('/projects/' + projectID + '/profile/message', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'content=' + encodeURIComponent(msg)
        }).then(function() {
            var source = new EventSource('/projects/' + projectID + '/profile/stream');
            var buffer = '';
            var inUpdate = false;
            var updateSection = '';
            var updateContent = '';
            var buildingEl = null;

            source.onmessage = function(event) {
                var d = JSON.parse(event.data);
                if (d.done) {
                    source.close();
                    // Flush any remaining buffer as text
                    if (buffer && !inUpdate) {
                        addChatText(aBody, buffer);
                        buffer = '';
                    }
                    input.disabled = false;
                    btn.disabled = false;
                    btn.textContent = 'Send';
                    scrollToBottom();
                    return;
                }
                if (d.error) {
                    source.close();
                    addChatText(aBody, '\nError: ' + d.error);
                    input.disabled = false;
                    btn.disabled = false;
                    btn.textContent = 'Send';
                    return;
                }
                if (d.chunk) {
                    buffer += d.chunk;

                    // Process buffer
                    while (true) {
                        if (!inUpdate) {
                            var openIdx = buffer.indexOf('[UPDATE:');
                            if (openIdx === -1) {
                                // No marker — render all buffered text except last 20 chars (could be partial marker)
                                var safe = buffer.length > 20 ? buffer.length - 20 : 0;
                                if (safe > 0) {
                                    addChatText(aBody, buffer.substring(0, safe));
                                    buffer = buffer.substring(safe);
                                }
                                break;
                            }
                            // Render text before marker
                            if (openIdx > 0) {
                                addChatText(aBody, buffer.substring(0, openIdx));
                            }
                            var closeBracket = buffer.indexOf(']', openIdx);
                            if (closeBracket === -1) break; // partial marker, wait for more
                            updateSection = buffer.substring(openIdx + 8, closeBracket);
                            var afterMarker = closeBracket + 1;
                            // Skip newline after opening marker
                            if (buffer[afterMarker] === '\n') afterMarker++;
                            buffer = buffer.substring(afterMarker);
                            inUpdate = true;
                            updateContent = '';
                            // Show building indicator
                            buildingEl = createBuildingIndicator(updateSection);
                            aBody.appendChild(buildingEl);
                            scrollToBottom();
                        }

                        if (inUpdate) {
                            var closeIdx = buffer.indexOf('[/UPDATE]');
                            if (closeIdx === -1) {
                                // Still accumulating update content
                                updateContent += buffer;
                                buffer = '';
                                break;
                            }
                            // Complete update found
                            updateContent += buffer.substring(0, closeIdx);
                            // Trim trailing newline
                            updateContent = updateContent.replace(/\n$/, '');
                            var afterClose = closeIdx + 9;
                            if (buffer[afterClose] === '\n') afterClose++;
                            buffer = buffer.substring(afterClose);
                            inUpdate = false;
                            // Replace building indicator with proposal block
                            if (buildingEl) {
                                var proposalBlock = createProposalBlock(updateSection, updateContent);
                                buildingEl.replaceWith(proposalBlock);
                                buildingEl = null;
                            }
                            scrollToBottom();
                            updateSection = '';
                            updateContent = '';
                            // Continue loop to process more markers
                        }
                    }
                    scrollToBottom();
                }
            };
            source.onerror = function() {
                source.close();
                input.disabled = false;
                btn.disabled = false;
                btn.textContent = 'Send';
            };
        }).catch(function(err) {
            addChatText(aBody, 'Error: ' + err.message);
            input.disabled = false;
            btn.disabled = false;
            btn.textContent = 'Send';
        });
    });

    scrollToBottom();
}

// Auto-init profile chat if on profile page
document.addEventListener('DOMContentLoaded', function() {
    var el = document.getElementById('profile-page');
    if (el) {
        initProfileChat(el.dataset.projectId);
    }
});
```

- [ ] **Step 1: Add the `initProfileChat` function and DOMContentLoaded auto-init to the end of app.js (outside the Alpine block)**
- [ ] **Step 2: Verify build**

```bash
go build ./...
```

- [ ] **Step 3: Commit**

```bash
git add web/static/app.js
git commit -m "feat: add profile chat JS with [UPDATE] marker parsing and card editing"
```

---

## Chunk 4: Wire Up + Verify

### Task 6: Final wiring and cleanup

**Files:**
- Modify: `cmd/server/main.go`

- [ ] **Step 1: Ensure main.go creates ProfileHandler correctly**

```go
profileHandler := handlers.NewProfileHandler(queries, aiClient, contentModel)
```

Remove any reference to `agents.NewProfileAgent` or `profileAgent`.

- [ ] **Step 2: Delete DB, generate templ, build, run all tests**

```bash
rm -f marketminded.db
templ generate ./web/templates/
go build ./...
go test ./...
```

All should pass.

- [ ] **Step 3: Manual smoke test**

```bash
go build -o server ./cmd/server/
OPENROUTER_API_KEY=... BRAVE_API_KEY=... ./server
```

Visit http://localhost:8080. Create project. Go to Profile. Type something. Verify:
- Chat works, streaming response appears
- `[UPDATE]` markers render as proposal blocks
- Accept updates the card on the right
- Reject fades the block
- Direct card editing works

- [ ] **Step 4: Final commit**

```bash
git add -A
git commit -m "feat: complete profile chat — chat-first 70/30 layout with inline proposals"
```
