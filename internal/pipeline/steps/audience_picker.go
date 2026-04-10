package steps

import (
	"context"
	"encoding/json"
	"fmt"
	"strings"

	"github.com/zanfridau/marketminded/internal/ai"
	"github.com/zanfridau/marketminded/internal/pipeline"
	"github.com/zanfridau/marketminded/internal/prompt"
	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/internal/tools"
)

type AudiencePickerStep struct {
	AI       *ai.Client
	Tools    *tools.Registry
	Prompt   *prompt.Builder
	Audience store.AudienceStore
	Model    func() string
}

func (s *AudiencePickerStep) Type() string { return "audience_picker" }

func (s *AudiencePickerStep) Run(ctx context.Context, input pipeline.StepInput, stream pipeline.StepStream) (pipeline.StepResult, error) {
	personas, err := s.Audience.ListAudiencePersonas(input.ProjectID)
	if err != nil {
		return pipeline.StepResult{}, fmt.Errorf("audience_picker: list personas: %w", err)
	}
	if len(personas) == 0 {
		// Scheduler should have skipped this step; guard regardless.
		return pipeline.StepResult{}, fmt.Errorf("audience_picker: no personas for project %d (this step should have been skipped)", input.ProjectID)
	}

	personasBlock := formatPersonasBlock(personas)
	researchOutput := input.PriorOutputs["research"]
	systemPrompt := s.Prompt.ForAudiencePicker(input.Topic, input.Brief, input.Profile, researchOutput, personasBlock)
	toolList := s.Tools.ForStep("audience_picker")
	prefix := fmt.Sprintf("pipeline run=%d step=%d type=audience_picker", input.RunID, input.StepID)

	result, runErr := RunWithTools(ctx, s.AI, s.Model(), systemPrompt, "Submit the audience selection now via submit_audience_selection.", toolList, s.Tools, "submit_audience_selection", stream, 0.2, 3, prefix)
	if runErr != nil {
		return result, runErr
	}

	hydrated, herr := hydrateAudienceSelection(result.Output, personas)
	if herr != nil {
		return result, fmt.Errorf("audience_picker: %w", herr)
	}
	result.Output = hydrated
	return result, nil
}

// formatPersonasBlock renders the project's personas as a numbered list the
// audience picker prompt embeds. Each persona is tagged with `[id=N]` so the
// model returns a valid persona_id.
func formatPersonasBlock(personas []store.AudiencePersona) string {
	var b strings.Builder
	for i, p := range personas {
		fmt.Fprintf(&b, "%d. [id=%d] %s\n", i+1, p.ID, p.Label)
		if p.Description != "" {
			fmt.Fprintf(&b, "   description: %s\n", p.Description)
		}
		if p.PainPoints != "" {
			fmt.Fprintf(&b, "   pain_points: %s\n", p.PainPoints)
		}
		if p.Push != "" {
			fmt.Fprintf(&b, "   push: %s\n", p.Push)
		}
		if p.Pull != "" {
			fmt.Fprintf(&b, "   pull: %s\n", p.Pull)
		}
		if p.Anxiety != "" {
			fmt.Fprintf(&b, "   anxiety: %s\n", p.Anxiety)
		}
		if p.Habit != "" {
			fmt.Fprintf(&b, "   habit: %s\n", p.Habit)
		}
		if p.Role != "" {
			fmt.Fprintf(&b, "   role: %s\n", p.Role)
		}
		if p.Demographics != "" {
			fmt.Fprintf(&b, "   demographics: %s\n", p.Demographics)
		}
		if p.CompanyInfo != "" {
			fmt.Fprintf(&b, "   company: %s\n", p.CompanyInfo)
		}
		if p.ContentHabits != "" {
			fmt.Fprintf(&b, "   content_habits: %s\n", p.ContentHabits)
		}
		if p.BuyingTriggers != "" {
			fmt.Fprintf(&b, "   buying_triggers: %s\n", p.BuyingTriggers)
		}
		b.WriteString("\n")
	}
	return b.String()
}

// hydrateAudienceSelection parses the raw tool output, validates that a
// persona_id (when present) exists in the project's personas, and rewrites the
// JSON to include persona_label and persona_summary copied from the matching
// persona. Downstream steps read the hydrated JSON without any DB access.
func hydrateAudienceSelection(raw string, personas []store.AudiencePersona) (string, error) {
	sel, err := pipeline.ParseAudienceSelection(raw)
	if err != nil {
		return "", fmt.Errorf("parse audience selection: %w", err)
	}
	if sel.Mode == "persona" {
		var match *store.AudiencePersona
		for i := range personas {
			if personas[i].ID == *sel.PersonaID {
				match = &personas[i]
				break
			}
		}
		if match == nil {
			return "", fmt.Errorf("persona_id %d not found in project personas", *sel.PersonaID)
		}
		sel.PersonaLabel = match.Label
		sel.PersonaSummary = personaSummary(match)
	}
	out, merr := json.Marshal(sel)
	if merr != nil {
		return "", fmt.Errorf("marshal hydrated audience selection: %w", merr)
	}
	return string(out), nil
}

// personaSummary builds a compact single-line summary from a persona row. It's
// embedded in the writer/editor/brand_enricher prompts via the audience block.
func personaSummary(p *store.AudiencePersona) string {
	var b strings.Builder
	if p.Description != "" {
		fmt.Fprintf(&b, "%s ", p.Description)
	}
	if p.PainPoints != "" {
		fmt.Fprintf(&b, "Pain points: %s. ", p.PainPoints)
	}
	if p.Push != "" {
		fmt.Fprintf(&b, "Push: %s. ", p.Push)
	}
	if p.Pull != "" {
		fmt.Fprintf(&b, "Pull: %s. ", p.Pull)
	}
	if p.Anxiety != "" {
		fmt.Fprintf(&b, "Anxiety: %s. ", p.Anxiety)
	}
	if p.Habit != "" {
		fmt.Fprintf(&b, "Habit: %s.", p.Habit)
	}
	return strings.TrimSpace(b.String())
}
