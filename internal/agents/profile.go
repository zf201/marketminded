package agents

import (
	"context"
	"encoding/json"
	"fmt"
	"strings"

	"github.com/zanfridau/marketminded/internal/types"
)

type ProfileAgent struct {
	ai    types.AIClient
	model func() string
}

func NewProfileAgent(ai types.AIClient, model func() string) *ProfileAgent {
	return &ProfileAgent{ai: ai, model: model}
}

type ProfileAnalysisInput struct {
	Inputs           []string          // raw inputs (content from section_inputs)
	ExistingSections map[string]string // section name → current JSON content
	Rejections       []string          // "section: reason" strings from past rejections
}

type ProfileProposal struct {
	Section string          `json:"section"`
	Content json.RawMessage `json:"content"`
}

func (a *ProfileAgent) Analyze(ctx context.Context, input ProfileAnalysisInput) ([]ProfileProposal, error) {
	inputsText := strings.Join(input.Inputs, "\n\n---\n\n")

	var existing strings.Builder
	for section, content := range input.ExistingSections {
		if content != "{}" {
			fmt.Fprintf(&existing, "## %s\n%s\n\n", section, content)
		}
	}

	rejectionsText := "None."
	if len(input.Rejections) > 0 {
		rejectionsText = strings.Join(input.Rejections, "\n")
	}

	messages := []types.Message{
		{
			Role: "system",
			Content: `You are a brand profile analyst. Given raw content inputs about a business, analyze them and propose structured profile sections.

Available sections: business, audience, voice, tone, strategy, pillars, guidelines, competitors, inspiration, offers.

Rules:
- Only propose sections where you have enough signal from the inputs
- If a section already has content, incorporate it into your proposal (don't lose existing data)
- Account for past rejections — if the user rejected something, adjust accordingly
- Each section's content should be a JSON object with descriptive fields appropriate to that section

Return a JSON array of objects with "section" and "content" fields. Return ONLY valid JSON, no markdown.`,
		},
		{
			Role: "user",
			Content: fmt.Sprintf("Raw inputs:\n\n%s\n\nExisting profile:\n%s\nPast rejections:\n%s\n\nAnalyze and propose profile sections.",
				inputsText, existing.String(), rejectionsText),
		},
	}

	response, err := a.ai.Complete(ctx, a.model(), messages)
	if err != nil {
		return nil, err
	}

	// Strip markdown code fences if present
	response = strings.TrimSpace(response)
	response = strings.TrimPrefix(response, "```json")
	response = strings.TrimPrefix(response, "```")
	response = strings.TrimSuffix(response, "```")
	response = strings.TrimSpace(response)

	var proposals []ProfileProposal
	if err := json.Unmarshal([]byte(response), &proposals); err != nil {
		return nil, fmt.Errorf("failed to parse agent response: %w\nResponse: %s", err, response)
	}

	return proposals, nil
}
