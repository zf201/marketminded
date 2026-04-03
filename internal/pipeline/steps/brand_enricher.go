package steps

import (
	"context"

	"github.com/zanfridau/marketminded/internal/ai"
	"github.com/zanfridau/marketminded/internal/pipeline"
	"github.com/zanfridau/marketminded/internal/prompt"
	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/internal/tools"
)

type BrandEnricherStep struct {
	AI      *ai.Client
	Tools   *tools.Registry
	Prompt  *prompt.Builder
	Profile store.ProfileStore
	Model   func() string
}

func (s *BrandEnricherStep) Type() string { return "brand_enricher" }

func (s *BrandEnricherStep) Run(ctx context.Context, input pipeline.StepInput, stream pipeline.StepStream) (pipeline.StepResult, error) {
	researchOutput := input.PriorOutputs["research"]

	urlList, _ := s.Profile.BuildSourceURLList(input.ProjectID)

	if urlList == "" {
		stream.SendDone()
		return pipeline.StepResult{Output: researchOutput}, nil
	}

	systemPrompt := s.Prompt.ForBrandEnricher(input.Profile, researchOutput, urlList)
	toolList := s.Tools.ForStep("brand_enricher")
	return runWithTools(ctx, s.AI, s.Model(), systemPrompt, "Fetch the brand URLs and enrich the research with brand context.", toolList, s.Tools, "submit_brand_enrichment", stream, 0.3, 12)
}
