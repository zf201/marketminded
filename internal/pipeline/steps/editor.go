package steps

import (
	"context"
	"encoding/json"
	"fmt"

	"github.com/zanfridau/marketminded/internal/ai"
	"github.com/zanfridau/marketminded/internal/applog"
	"github.com/zanfridau/marketminded/internal/pipeline"
	"github.com/zanfridau/marketminded/internal/prompt"
	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/internal/tools"
)

type EditorStep struct {
	AI        *ai.Client
	Tools     *tools.Registry
	Prompt    *prompt.Builder
	Pipeline  store.PipelineStore
	VoiceTone store.VoiceToneStore
	Model     func() string
}

func (s *EditorStep) Type() string { return "editor" }

func (s *EditorStep) Run(ctx context.Context, input pipeline.StepInput, stream pipeline.StepStream) (pipeline.StepResult, error) {
	payload, claimsSource := pipeline.FindLatestClaims(input.PriorOutputs)
	if claimsSource == "" || len(payload.Claims) == 0 {
		return pipeline.StepResult{}, fmt.Errorf("editor: no claims found in prior outputs (expected research, brand_enricher, or claim_verifier)")
	}

	// Pick the most up-to-date narrative brief: prefer enriched_brief from the
	// step that produced the latest claims, fall back to the raw run brief.
	brief := payload.EnrichedBrief
	if brief == "" {
		brief = payload.Brief
	}
	if brief == "" {
		brief = input.Brief
	}

	claimsBlock := pipeline.FormatClaimsBlock(payload)

	var frameworkBlock string
	if vt, err := s.VoiceTone.GetVoiceToneProfile(input.ProjectID); err == nil {
		frameworkBlock = vt.BuildFrameworkBlock()
	}

	var audienceBlock string
	if raw, ok := input.PriorOutputs["audience_picker"]; ok && raw != "" {
		sel, perr := pipeline.ParseAudienceSelection(raw)
		if perr != nil {
			return pipeline.StepResult{}, fmt.Errorf("editor: parse audience selection: %w", perr)
		}
		audienceBlock = pipeline.FormatAudienceBlock(sel)
	}

	toolList := s.Tools.ForStep("editor")
	prefix := fmt.Sprintf("pipeline run=%d step=%d type=editor (claims from %s)", input.RunID, input.StepID, claimsSource)

	const maxAttempts = 3
	var retryFeedback string
	var result pipeline.StepResult
	var err error

	for attempt := 1; attempt <= maxAttempts; attempt++ {
		systemPrompt := s.Prompt.ForEditor(input.Profile, brief, claimsBlock, frameworkBlock, audienceBlock, retryFeedback)
		attemptPrefix := prefix
		if attempt > 1 {
			attemptPrefix = fmt.Sprintf("%s attempt=%d", prefix, attempt)
		}

		result, err = RunWithTools(ctx, s.AI, s.Model(), systemPrompt, "Submit the editorial outline now via submit_editorial_outline.", toolList, s.Tools, "submit_editorial_outline", stream, 0.3, 3, attemptPrefix)
		if err != nil {
			// RunWithTools failure (model error, cancellation, etc.) — do not retry
			return result, err
		}

		verr := validateOutlineClaimRefs(result.Output, payload)
		if verr == nil {
			// success
			return result, nil
		}

		// validator rejected — prepare feedback and retry if attempts remain
		retryFeedback = verr.Error()
		applog.Info("%s: validator rejected attempt %d: %s", prefix, attempt, verr.Error())
		if attempt == maxAttempts {
			return result, fmt.Errorf("editor: %w (after %d attempts)", verr, maxAttempts)
		}
	}

	return result, err
}

// validateOutlineClaimRefs ensures every claim_id in the outline exists in the
// claims payload the editor was given. Broken refs hard-error the step.
func validateOutlineClaimRefs(outlineJSON string, payload pipeline.ClaimsPayload) error {
	known := make(map[string]bool, len(payload.Claims))
	for _, c := range payload.Claims {
		known[c.ID] = true
	}

	var parsed struct {
		Sections []struct {
			Heading  string   `json:"heading"`
			ClaimIDs []string `json:"claim_ids"`
		} `json:"sections"`
	}
	if err := json.Unmarshal([]byte(outlineJSON), &parsed); err != nil {
		return fmt.Errorf("parse outline: %w", err)
	}
	for i, sec := range parsed.Sections {
		if len(sec.ClaimIDs) == 0 {
			return fmt.Errorf("section[%d] %q has empty claim_ids — every section must lean on specific claims", i, sec.Heading)
		}
		for _, cid := range sec.ClaimIDs {
			if !known[cid] {
				return fmt.Errorf("section[%d] %q references unknown claim id %q", i, sec.Heading, cid)
			}
		}
	}
	return nil
}
