package steps

import (
	"context"
	"fmt"

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
		return pipeline.StepResult{Output: researchOutput}, nil
	}

	var audienceBlock string
	if raw, ok := input.PriorOutputs["audience_picker"]; ok && raw != "" {
		sel, perr := pipeline.ParseAudienceSelection(raw)
		if perr != nil {
			return pipeline.StepResult{}, fmt.Errorf("brand_enricher: parse audience selection: %w", perr)
		}
		audienceBlock = pipeline.FormatAudienceBlock(sel)
	}

	systemPrompt := s.Prompt.ForBrandEnricher(input.Profile, researchOutput, urlList, audienceBlock)
	toolList := s.Tools.ForStep("brand_enricher")
	prefix := fmt.Sprintf("pipeline run=%d step=%d type=brand_enricher", input.RunID, input.StepID)
	result, err := RunWithTools(ctx, s.AI, s.Model(), systemPrompt, "Fetch the brand URLs and enrich the research with brand context.", toolList, s.Tools, "submit_brand_enrichment", stream, 0.3, 15, prefix)
	if err != nil {
		return result, err
	}

	// Validate: claims/sources are well-formed and prior research claims are preserved untouched.
	priorPayload, perr := pipeline.ParseClaimsPayload(researchOutput)
	if perr != nil {
		return result, fmt.Errorf("brand_enricher: parse research output: %w", perr)
	}
	nextPayload, nerr := pipeline.ParseClaimsPayload(result.Output)
	if nerr != nil {
		return result, fmt.Errorf("brand_enricher: parse own output: %w", nerr)
	}
	if verr := pipeline.ValidateClaimsPayload(nextPayload); verr != nil {
		return result, fmt.Errorf("brand_enricher: invalid output: %w", verr)
	}
	if verr := pipeline.ValidatePreservesPriorClaims(priorPayload, nextPayload); verr != nil {
		return result, fmt.Errorf("brand_enricher: %w", verr)
	}

	return result, nil
}
