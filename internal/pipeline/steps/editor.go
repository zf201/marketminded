package steps

import (
	"context"
	"encoding/json"

	"github.com/zanfridau/marketminded/internal/ai"
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
	factcheckOutput := input.PriorOutputs["factcheck"]

	var factcheck struct {
		EnrichedBrief string `json:"enriched_brief"`
	}
	_ = json.Unmarshal([]byte(factcheckOutput), &factcheck)

	brief := factcheck.EnrichedBrief
	if brief == "" {
		brief = input.Brief
	}

	steps, _ := s.Pipeline.ListPipelineSteps(input.RunID)
	allSources := pipeline.CollectSources(steps)
	sourcesText := pipeline.FormatSourcesText(allSources)

	var frameworkBlock string
	if vt, err := s.VoiceTone.GetVoiceToneProfile(input.ProjectID); err == nil {
		frameworkBlock = vt.BuildFrameworkBlock()
	}

	systemPrompt := s.Prompt.ForEditor(input.Profile, brief, sourcesText, frameworkBlock)
	toolList := s.Tools.ForStep("editor")
	return runWithTools(ctx, s.AI, s.Model(), systemPrompt, "Create the editorial outline now.", toolList, s.Tools, "submit_editorial_outline", stream, 0.3, 5)
}
