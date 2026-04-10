package steps

import (
	"context"
	"fmt"

	"github.com/zanfridau/marketminded/internal/ai"
	"github.com/zanfridau/marketminded/internal/pipeline"
	"github.com/zanfridau/marketminded/internal/prompt"
	"github.com/zanfridau/marketminded/internal/tools"
)

type ClaimVerifierStep struct {
	AI     *ai.Client
	Tools  *tools.Registry
	Prompt *prompt.Builder
	Model  func() string
}

func (s *ClaimVerifierStep) Type() string { return "claim_verifier" }

func (s *ClaimVerifierStep) Run(ctx context.Context, input pipeline.StepInput, stream pipeline.StepStream) (pipeline.StepResult, error) {
	// Read the claims payload from brand_enricher (the step that always sits
	// directly before the verifier in the run order).
	enricherOutput := input.PriorOutputs["brand_enricher"]
	if enricherOutput == "" {
		// Brand enricher may have skipped (no brand URLs); fall back to research.
		enricherOutput = input.PriorOutputs["research"]
	}

	systemPrompt := s.Prompt.ForClaimVerifier(enricherOutput)
	toolList := s.Tools.ForStep("claim_verifier")
	prefix := fmt.Sprintf("pipeline run=%d step=%d type=claim_verifier", input.RunID, input.StepID)
	result, err := RunWithTools(ctx, s.AI, s.Model(), systemPrompt, "Verify the highest-risk claims now via submit_claim_verification.", toolList, s.Tools, "submit_claim_verification", stream, 0.2, 20, prefix)
	if err != nil {
		return result, err
	}

	// Validate the patched claims array. The verifier may correct text in place,
	// so we don't run ValidatePreservesPriorClaims (text changes are allowed).
	// We do run ValidateClaimsPayload for structural integrity, plus
	// ValidateVerifiedClaims for the audit trail.
	payload, perr := pipeline.ParseClaimsPayload(result.Output)
	if perr != nil {
		return result, fmt.Errorf("claim_verifier: parse own output: %w", perr)
	}
	if verr := pipeline.ValidateClaimsPayload(payload); verr != nil {
		return result, fmt.Errorf("claim_verifier: %w", verr)
	}
	if verr := pipeline.ValidateVerifiedClaims(payload); verr != nil {
		return result, fmt.Errorf("claim_verifier: %w", verr)
	}
	return result, nil
}
