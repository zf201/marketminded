package agents

import (
	"context"
	"fmt"
	"strings"

	"github.com/zanfridau/marketminded/internal/types"
)

type ToneAgent struct {
	ai    types.AIClient
	model string
}

func NewToneAgent(ai types.AIClient, model string) *ToneAgent {
	return &ToneAgent{ai: ai, model: model}
}

func (a *ToneAgent) BuildProfile(ctx context.Context, samples []string, brandDocs []string) (string, error) {
	samplesText := strings.Join(samples, "\n\n---\n\n")
	docsText := strings.Join(brandDocs, "\n\n---\n\n")

	userContent := fmt.Sprintf("Writing samples:\n\n%s", samplesText)
	if docsText != "" {
		userContent += fmt.Sprintf("\n\nBrand documents:\n\n%s", docsText)
	}

	messages := []types.Message{
		{
			Role: "system",
			Content: `You are a tone analysis expert. Analyze the writing samples and brand documents to produce a JSON tone profile with these fields:
- formality: level from "very_casual" to "very_formal"
- humor: level from "none" to "heavy"
- emotion: level from "detached" to "highly_emotional"
- persuasion_style: how the brand persuades (e.g., data-driven, storytelling, authority)
- audience_relationship: how the brand relates to readers (e.g., peer, mentor, expert)
- guidelines: list of specific tone rules to follow
- avoid: list of tonal qualities to avoid

Return ONLY valid JSON.`,
		},
		{
			Role:    "user",
			Content: userContent,
		},
	}

	return a.ai.Complete(ctx, a.model, messages)
}
