package steps

import (
	"context"

	"github.com/zanfridau/marketminded/internal/ai"
	"github.com/zanfridau/marketminded/internal/pipeline"
	"github.com/zanfridau/marketminded/internal/prompt"
	"github.com/zanfridau/marketminded/internal/tools"
)

type FactcheckStep struct {
	AI     *ai.Client
	Tools  *tools.Registry
	Prompt *prompt.Builder
	Model  func() string
}

func (s *FactcheckStep) Type() string { return "factcheck" }

func (s *FactcheckStep) Run(ctx context.Context, input pipeline.StepInput, stream pipeline.StepStream) (pipeline.StepResult, error) {
	enricherOutput := input.PriorOutputs["brand_enricher"]
	systemPrompt := s.Prompt.ForFactcheck(enricherOutput)
	toolList := s.Tools.ForStep("factcheck")
	return runWithTools(ctx, s.AI, s.Model(), systemPrompt, "Begin fact-checking now.", toolList, s.Tools, "submit_factcheck", stream, 0.2, 20)
}
