package agents

import (
	"context"
	"fmt"
	"strings"

	"github.com/zanfridau/marketminded/internal/types"
)

type IdeaInput struct {
	Niche      string
	ContentLog []string
	Profile    string
}

type IdeaAgent struct {
	ai       types.AIClient
	searcher types.Searcher
	model    func() string
}

func NewIdeaAgent(ai types.AIClient, searcher types.Searcher, model func() string) *IdeaAgent {
	return &IdeaAgent{ai: ai, searcher: searcher, model: model}
}

func (a *IdeaAgent) Generate(ctx context.Context, input IdeaInput) (string, error) {
	return a.ai.Complete(ctx, a.model(), a.ideaMessages(ctx, input))
}

func (a *IdeaAgent) GenerateStream(ctx context.Context, input IdeaInput, fn types.StreamFunc) (string, error) {
	return a.ai.Stream(ctx, a.model(), a.ideaMessages(ctx, input), fn)
}

func (a *IdeaAgent) ideaMessages(ctx context.Context, input IdeaInput) []types.Message {
	var searchContext strings.Builder
	results, err := a.searcher.Search(ctx, input.Niche+" content ideas trending", 7)
	if err == nil {
		for _, r := range results {
			fmt.Fprintf(&searchContext, "- %s: %s (%s)\n", r.Title, r.Description, r.URL)
		}
	}

	contentLog := "None yet."
	if len(input.ContentLog) > 0 {
		contentLog = strings.Join(input.ContentLog, "\n")
	}

	return []types.Message{
		{
			Role: "system",
			Content: `You are a content strategist. Generate 10 pillar content ideas (blog post topics) based on the research, niche, and brand voice provided. Each idea should:
- Be specific and actionable
- Have a compelling working title
- Include a one-line angle/hook
- NOT repeat topics from the content log

Return as a numbered list with title and angle on each line.`,
		},
		{
			Role: "user",
			Content: fmt.Sprintf("Niche: %s\n\nClient Profile:\n%s\n\nRecent web research:\n%s\nPrevious content (avoid repeating):\n%s\n\nGenerate 10 pillar blog post ideas.",
				input.Niche, input.Profile, searchContext.String(), contentLog),
		},
	}
}
