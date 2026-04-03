package handlers

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"strconv"
	"strings"
	"time"

	"github.com/zanfridau/marketminded/internal/ai"
	"github.com/zanfridau/marketminded/internal/applog"
	"github.com/zanfridau/marketminded/internal/search"
	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/internal/tools"
	"github.com/zanfridau/marketminded/internal/types"
)

type AudienceHandler struct {
	queries     *store.Queries
	aiClient    *ai.Client
	braveClient *search.BraveClient
	model       func() string
}

func NewAudienceHandler(q *store.Queries, aiClient *ai.Client, braveClient *search.BraveClient, model func() string) *AudienceHandler {
	return &AudienceHandler{queries: q, aiClient: aiClient, braveClient: braveClient, model: model}
}

func (h *AudienceHandler) Handle(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	switch {
	case rest == "personas" && r.Method == "GET":
		h.listPersonas(w, r, projectID)
	case rest == "personas" && r.Method == "POST":
		h.savePersona(w, r, projectID)
	case strings.HasPrefix(rest, "personas/") && r.Method == "DELETE":
		h.deletePersona(w, r, projectID, rest)
	case rest == "context" && r.Method == "GET":
		h.getContext(w, r, projectID)
	case rest == "save-context" && r.Method == "POST":
		h.saveContext(w, r, projectID)
	case rest == "generate" && r.Method == "GET":
		h.streamGenerate(w, r, projectID)
	case rest == "save-generated" && r.Method == "POST":
		h.saveGenerated(w, r, projectID)
	default:
		http.NotFound(w, r)
	}
}

func (h *AudienceHandler) listPersonas(w http.ResponseWriter, r *http.Request, projectID int64) {
	personas, err := h.queries.ListAudiencePersonas(projectID)
	if err != nil {
		http.Error(w, "Failed to list personas", http.StatusInternalServerError)
		return
	}
	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(personas)
}

func (h *AudienceHandler) savePersona(w http.ResponseWriter, r *http.Request, projectID int64) {
	var p store.AudiencePersona
	if err := json.NewDecoder(r.Body).Decode(&p); err != nil {
		http.Error(w, "Invalid request", http.StatusBadRequest)
		return
	}

	if p.ID > 0 {
		if err := h.queries.UpdateAudiencePersona(p.ID, p); err != nil {
			http.Error(w, "Failed to update persona", http.StatusInternalServerError)
			return
		}
	} else {
		if _, err := h.queries.CreateAudiencePersona(projectID, p); err != nil {
			http.Error(w, "Failed to create persona", http.StatusInternalServerError)
			return
		}
	}
	w.WriteHeader(http.StatusOK)
}

func (h *AudienceHandler) deletePersona(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	idStr := strings.TrimPrefix(rest, "personas/")
	id, err := strconv.ParseInt(idStr, 10, 64)
	if err != nil {
		http.Error(w, "Invalid persona ID", http.StatusBadRequest)
		return
	}
	if err := h.queries.DeleteAudiencePersona(id); err != nil {
		http.Error(w, "Failed to delete persona", http.StatusInternalServerError)
		return
	}
	w.WriteHeader(http.StatusOK)
}

func (h *AudienceHandler) getContext(w http.ResponseWriter, r *http.Request, projectID int64) {
	location, _ := h.queries.GetProjectSetting(projectID, "audience_location")
	notes, _ := h.queries.GetProjectSetting(projectID, "audience_notes")

	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(map[string]string{
		"location": location,
		"notes":    notes,
	})
}

func (h *AudienceHandler) saveContext(w http.ResponseWriter, r *http.Request, projectID int64) {
	var body struct {
		Location string `json:"location"`
		Notes    string `json:"notes"`
	}
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
		http.Error(w, "Invalid request", http.StatusBadRequest)
		return
	}
	h.queries.SetProjectSetting(projectID, "audience_location", body.Location)
	h.queries.SetProjectSetting(projectID, "audience_notes", body.Notes)
	w.WriteHeader(http.StatusOK)
}

func (h *AudienceHandler) streamGenerate(w http.ResponseWriter, r *http.Request, projectID int64) {
	project, _ := h.queries.GetProject(projectID)

	// SSE headers
	w.Header().Set("Content-Type", "text/event-stream")
	w.Header().Set("Cache-Control", "no-cache")
	w.Header().Set("Connection", "keep-alive")

	flusher, ok := w.(http.Flusher)
	if !ok {
		http.Error(w, "Streaming not supported", http.StatusInternalServerError)
		return
	}

	sendEvent := func(v any) {
		data, _ := json.Marshal(v)
		fmt.Fprintf(w, "data: %s\n\n", data)
		flusher.Flush()
	}

	sendEvent(map[string]string{"type": "status", "status": "Gathering context..."})

	// Gather product & positioning content
	var productContent string
	if ps, err := h.queries.GetProfileSection(projectID, "product_and_positioning"); err == nil {
		productContent = ps.Content
	}

	// Gather voice & tone
	var voiceContent string
	if ps, err := h.queries.GetProfileSection(projectID, "voice_and_tone"); err == nil {
		voiceContent = ps.Content
	}

	// Audience location and notes
	location, _ := h.queries.GetProjectSetting(projectID, "audience_location")
	notes, _ := h.queries.GetProjectSetting(projectID, "audience_notes")

	// Memory setting
	memorySetting, _ := h.queries.GetProjectSetting(projectID, "memory")

	// Existing personas
	existingPersonas, _ := h.queries.ListAudiencePersonas(projectID)

	sendEvent(map[string]string{"type": "status", "status": "Generating personas..."})

	// Build system prompt
	var systemPrompt strings.Builder
	fmt.Fprintf(&systemPrompt, "Today's date: %s\n\n", time.Now().Format("January 2, 2006"))
	fmt.Fprintf(&systemPrompt, "You are an expert content marketing strategist building audience personas for \"%s\".\n\n", project.Name)

	if productContent != "" {
		fmt.Fprintf(&systemPrompt, "## Product & Positioning\n%s\n\n", productContent)
	}
	if voiceContent != "" {
		fmt.Fprintf(&systemPrompt, "## Voice & Tone\n%s\n\n", voiceContent)
	}
	if location != "" {
		fmt.Fprintf(&systemPrompt, "## Target Location/Market\n%s\n\n", location)
	}
	if notes != "" {
		fmt.Fprintf(&systemPrompt, "## Additional Context Notes\n%s\n\n", notes)
	}
	if memorySetting != "" {
		fmt.Fprintf(&systemPrompt, "## Important Rules and Facts\n%s\n\n", memorySetting)
	}

	if len(existingPersonas) > 0 {
		systemPrompt.WriteString("## Existing Personas (review and update/keep/remove as needed)\n")
		for i, p := range existingPersonas {
			fmt.Fprintf(&systemPrompt, "### Persona %d (ID: %d): %s\n", i+1, p.ID, p.Label)
			fmt.Fprintf(&systemPrompt, "Description: %s\n", p.Description)
			fmt.Fprintf(&systemPrompt, "Pain points: %s\n", p.PainPoints)
			fmt.Fprintf(&systemPrompt, "Push: %s\n", p.Push)
			fmt.Fprintf(&systemPrompt, "Pull: %s\n", p.Pull)
			fmt.Fprintf(&systemPrompt, "Anxiety: %s\n", p.Anxiety)
			fmt.Fprintf(&systemPrompt, "Habit: %s\n\n", p.Habit)
		}
	}

	systemPrompt.WriteString(`## Your Task
Research and define 3-5 detailed audience personas for this business. Use web_search to research the market, competitors, and target audience.

For each persona, provide:
- label: A short memorable name (e.g. "The Overwhelmed Founder")
- description: 2-3 sentences describing who they are
- pain_points: Their specific frustrations and problems
- push: What's driving them to seek a solution NOW
- pull: What attracts them to THIS specific solution
- anxiety: Concerns that might stop them from acting
- habit: What keeps them stuck with the status quo
- role: Their job title/role (if relevant)
- demographics: Age range, education, income level, etc. (if relevant)
- company_info: Company size, industry, stage (if B2B)
- content_habits: Where they consume content, what formats they prefer
- buying_triggers: What events/situations trigger a purchase decision

## Rules
- ALWAYS write in English. All persona fields must be in English.
- When the target market uses a non-English language, include important native-language terms in parentheses to help clarify meaning. For example: "Construction site manager (vodja gradbišča)" or "Fleet management (upravljanje voznega parka)". This helps downstream agents understand local terminology.
- NEVER fabricate details. Use web_search to research real market data.
- Be specific to THIS business. Generic personas are useless.
- Write in plain language, not marketing jargon.
- Each persona should be distinct and non-overlapping.
- You have a MAXIMUM of 15 tool calls total. Plan your searches efficiently — do 2-4 targeted searches, then call submit_personas. Do NOT keep searching endlessly.
`)

	if len(existingPersonas) > 0 {
		systemPrompt.WriteString(`
## Handling Existing Personas
- For each existing persona you want to keep as-is, set status to "unchanged" and include its "id"
- For each existing persona you want to update, set status to "updated", include its "id", and provide all fields
- For each existing persona you want to remove, set status to "removed" and include its "id"
- For brand new personas, set status to "new" and omit "id"
`)
	}

	systemPrompt.WriteString("\nWhen you have finished researching, call the submit_personas tool with your results.")

	// Build tools
	submitPersonasTool := ai.Tool{
		Type: "function",
		Function: ai.ToolFunction{
			Name:        "submit_personas",
			Description: "Submit the final set of audience personas after research is complete.",
			Parameters:  json.RawMessage(`{"type":"object","properties":{"personas":{"type":"array","items":{"type":"object","properties":{"id":{"type":"integer","description":"Existing persona ID if updating/unchanged/removing, omit if new"},"status":{"type":"string","enum":["new","updated","unchanged","removed"]},"label":{"type":"string"},"description":{"type":"string"},"pain_points":{"type":"string"},"push":{"type":"string"},"pull":{"type":"string"},"anxiety":{"type":"string"},"habit":{"type":"string"},"role":{"type":"string"},"demographics":{"type":"string"},"company_info":{"type":"string"},"content_habits":{"type":"string"},"buying_triggers":{"type":"string"}},"required":["status","label","description","pain_points","push","pull","anxiety","habit"]}},"reasoning":{"type":"string","description":"Brief explanation of why these personas were chosen"}},"required":["personas","reasoning"]}`),
		},
	}

	toolList := []ai.Tool{
		tools.NewSearchTool(),
		submitPersonasTool,
	}

	searchExec := tools.NewSearchExecutor(h.braveClient)

	var submittedResult string

	executor := func(ctx context.Context, name, args string) (string, error) {
		switch name {
		case "web_search":
			return searchExec(ctx, args)
		case "submit_personas":
			submittedResult = args
			return "Personas submitted successfully.", ai.ErrToolDone
		default:
			return "", fmt.Errorf("unknown tool: %s", name)
		}
	}

	onToolEvent := func(event ai.ToolEvent) {
		switch event.Type {
		case "tool_start":
			summary := ""
			if event.Tool == "web_search" {
				summary = tools.SearchSummary(event.Args)
			} else if event.Tool == "submit_personas" {
				summary = "Submitting personas..."
			}
			sendEvent(map[string]string{"type": "status", "status": summary})
		case "tool_result":
			// no-op for tool results
		}
	}

	onChunk := func(chunk string) error {
		return nil // no-op, we only care about the final tool call
	}

	onReasoning := func(chunk string) error {
		return nil
	}

	aiMsgs := []types.Message{
		{Role: "system", Content: systemPrompt.String()},
		{Role: "user", Content: "Research and build audience personas for this business. Use web_search to gather real market insights, then submit your personas."},
	}

	temp := 0.5
	model := h.model()
	start := time.Now()
	applog.Info("audience generate: project=%d model=%s starting", projectID, model)

	_, err := h.aiClient.StreamWithTools(r.Context(), model, aiMsgs, toolList, executor, onToolEvent, onChunk, onReasoning, &temp, 15)

	duration := time.Since(start)
	if err != nil && submittedResult == "" {
		applog.Error("audience generate: project=%d model=%s failed after %s: %s", projectID, model, duration, err.Error())
		sendEvent(map[string]string{"type": "error", "error": err.Error()})
		return
	}

	if submittedResult != "" {
		applog.Info("audience generate: project=%d model=%s completed in %s, result=%d bytes", projectID, model, duration, len(submittedResult))
		sendEvent(map[string]any{"type": "personas", "data": json.RawMessage(submittedResult)})
	} else {
		applog.Error("audience generate: project=%d model=%s completed in %s but no result submitted", projectID, model, duration)
	}

	sendEvent(map[string]string{"type": "done"})
}

type generatedPersona struct {
	ID             int64  `json:"id"`
	Status         string `json:"status"`
	Label          string `json:"label"`
	Description    string `json:"description"`
	PainPoints     string `json:"pain_points"`
	Push           string `json:"push"`
	Pull           string `json:"pull"`
	Anxiety        string `json:"anxiety"`
	Habit          string `json:"habit"`
	Role           string `json:"role"`
	Demographics   string `json:"demographics"`
	CompanyInfo    string `json:"company_info"`
	ContentHabits  string `json:"content_habits"`
	BuyingTriggers string `json:"buying_triggers"`
}

func (h *AudienceHandler) saveGenerated(w http.ResponseWriter, r *http.Request, projectID int64) {
	var body struct {
		Personas []generatedPersona `json:"personas"`
	}
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
		http.Error(w, "Invalid request", http.StatusBadRequest)
		return
	}

	for _, p := range body.Personas {
		persona := store.AudiencePersona{
			Label:          p.Label,
			Description:    p.Description,
			PainPoints:     p.PainPoints,
			Push:           p.Push,
			Pull:           p.Pull,
			Anxiety:        p.Anxiety,
			Habit:          p.Habit,
			Role:           p.Role,
			Demographics:   p.Demographics,
			CompanyInfo:    p.CompanyInfo,
			ContentHabits:  p.ContentHabits,
			BuyingTriggers: p.BuyingTriggers,
		}

		switch p.Status {
		case "new":
			h.queries.CreateAudiencePersona(projectID, persona)
		case "updated":
			if p.ID > 0 {
				h.queries.UpdateAudiencePersona(p.ID, persona)
			}
		case "removed":
			if p.ID > 0 {
				h.queries.DeleteAudiencePersona(p.ID)
			}
		}
	}

	w.WriteHeader(http.StatusOK)
}
