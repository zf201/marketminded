package steps

import (
	"context"

	"github.com/zanfridau/marketminded/internal/ai"
	"github.com/zanfridau/marketminded/internal/pipeline"
	"github.com/zanfridau/marketminded/internal/prompt"
	"github.com/zanfridau/marketminded/internal/tools"
)

type ResearchStep struct {
	AI     *ai.Client
	Tools  *tools.Registry
	Prompt *prompt.Builder
	Model  func() string
}

func (s *ResearchStep) Type() string { return "research" }

func (s *ResearchStep) Run(ctx context.Context, input pipeline.StepInput, stream pipeline.StepStream) (pipeline.StepResult, error) {
	systemPrompt := s.Prompt.ForResearch(input.Profile, input.Brief)
	toolList := s.Tools.ForStep("research")
	return runWithTools(ctx, s.AI, s.Model(), systemPrompt, "Begin researching this topic now.", toolList, s.Tools, "submit_research", stream, 0.3, 25)
}
