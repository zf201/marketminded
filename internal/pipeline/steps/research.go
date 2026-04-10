package steps

import (
	"context"
	"fmt"

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
	prefix := fmt.Sprintf("pipeline run=%d step=%d type=research", input.RunID, input.StepID)
	result, err := RunWithTools(ctx, s.AI, s.Model(), systemPrompt, "Begin researching this topic now.", toolList, s.Tools, "submit_research", stream, 0.3, 25, prefix)
	if err != nil {
		return result, err
	}

	// Validate the structured payload before passing it downstream. The
	// brand_enricher skip-path (no brand URLs) returns this output verbatim,
	// so a malformed payload here can otherwise reach the editor unnoticed.
	payload, perr := pipeline.ParseClaimsPayload(result.Output)
	if perr != nil {
		return result, fmt.Errorf("research: parse output: %w", perr)
	}
	if verr := pipeline.ValidateClaimsPayload(payload); verr != nil {
		return result, fmt.Errorf("research: invalid output: %w", verr)
	}
	return result, nil
}
