package agents

import (
	"context"
	"fmt"
	"strings"

	"github.com/zanfridau/marketminded/internal/types"
)

type VoiceAgent struct {
	ai    types.AIClient
	model func() string
}

func NewVoiceAgent(ai types.AIClient, model func() string) *VoiceAgent {
	return &VoiceAgent{ai: ai, model: model}
}

func (a *VoiceAgent) BuildProfile(ctx context.Context, samples []string) (string, error) {
	samplesText := strings.Join(samples, "\n\n---\n\n")

	messages := []types.Message{
		{
			Role: "system",
			Content: `You are a voice analysis expert. Analyze the writing samples and produce a JSON voice profile with these fields:
- tone: overall tone (e.g., professional, casual, authoritative)
- vocabulary: vocabulary level and style (e.g., technical, simple, jargon-heavy)
- sentence_style: sentence structure patterns (e.g., concise, flowing, varied)
- personality_traits: list of personality traits that come through
- phrases: recurring phrases or expressions
- dos: list of things to do when writing in this voice
- donts: list of things to avoid

Return ONLY valid JSON.`,
		},
		{
			Role:    "user",
			Content: fmt.Sprintf("Analyze these writing samples and build a voice profile:\n\n%s", samplesText),
		},
	}

	return a.ai.Complete(ctx, a.model(), messages)
}
