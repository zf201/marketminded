package steps

import (
	"context"
	"encoding/json"
	"fmt"

	"github.com/zanfridau/marketminded/internal/ai"
	"github.com/zanfridau/marketminded/internal/pipeline"
	"github.com/zanfridau/marketminded/internal/prompt"
	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/internal/tools"
)

type StyleReferenceStep struct {
	AI              *ai.Client
	Tools           *tools.Registry
	Prompt          *prompt.Builder
	ProjectSettings store.ProjectSettingsStore
	Model           func() string
}

func (s *StyleReferenceStep) Type() string { return "style_reference" }

func (s *StyleReferenceStep) Run(ctx context.Context, input pipeline.StepInput, stream pipeline.StepStream) (pipeline.StepResult, error) {
	blogURL, _ := s.ProjectSettings.GetProjectSetting(input.ProjectID, "blog_url")
	if blogURL == "" {
		// Scheduler should have skipped this step; guard regardless.
		return pipeline.StepResult{}, fmt.Errorf("style_reference: blog_url not set for project %d (this step should have been skipped)", input.ProjectID)
	}

	systemPrompt := s.Prompt.ForStyleReference(blogURL, input.Topic)
	toolList := s.Tools.ForStep("style_reference")
	prefix := fmt.Sprintf("pipeline run=%d step=%d type=style_reference", input.RunID, input.StepID)

	// maxIter = StyleReferenceMaxFetches + 2 gives headroom for the submission
	// call plus one retry cycle. The prompt instructs the model to stay inside
	// StyleReferenceMaxFetches; this is the safety net.
	result, runErr := RunWithTools(ctx, s.AI, s.Model(), systemPrompt, "Begin finding style exemplars now.", toolList, s.Tools, "submit_style_reference", stream, 0.2, tools.StyleReferenceMaxFetches+2, prefix)
	if runErr != nil {
		return result, runErr
	}

	ref, perr := pipeline.ParseStyleReference(result.Output)
	if perr != nil {
		return result, fmt.Errorf("style_reference: %w", perr)
	}

	fetcher := func(fctx context.Context, url string) (string, error) {
		args, _ := json.Marshal(map[string]string{"url": url})
		return tools.ExecuteFetch(fctx, string(args))
	}
	if perr := pipeline.PopulateStyleReferenceBodies(ctx, ref, fetcher); perr != nil {
		return result, fmt.Errorf("style_reference: %w", perr)
	}

	populated, merr := json.Marshal(ref)
	if merr != nil {
		return result, fmt.Errorf("style_reference: marshal populated reference: %w", merr)
	}
	result.Output = string(populated)

	return result, nil
}
