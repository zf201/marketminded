package steps

import (
	"context"
	"encoding/json"

	"github.com/zanfridau/marketminded/internal/ai"
	"github.com/zanfridau/marketminded/internal/content"
	"github.com/zanfridau/marketminded/internal/pipeline"
	"github.com/zanfridau/marketminded/internal/prompt"
	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/internal/tools"
)

type EditorStep struct {
	AI              *ai.Client
	Tools           *tools.Registry
	Prompt          *prompt.Builder
	Pipeline        store.PipelineStore
	ProjectSettings store.ProjectSettingsStore
	Model           func() string
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
	if fwKey, err := s.ProjectSettings.GetProjectSetting(input.ProjectID, "storytelling_framework"); err == nil && fwKey != "" {
		if fw := content.FrameworkByKey(fwKey); fw != nil {
			frameworkBlock = "## Storytelling framework\nFramework: " + fw.Name + " (" + fw.Attribution + ")\n" + fw.PromptInstruction + "\nMap the framework beats to the article sections in your outline.\n"
		}
	}

	systemPrompt := s.Prompt.ForEditor(input.Profile, brief, sourcesText, frameworkBlock)
	toolList := s.Tools.ForStep("editor")
	return runWithTools(ctx, s.AI, s.Model(), systemPrompt, "Create the editorial outline now.", toolList, s.Tools, "submit_editorial_outline", stream, 0.3, 5)
}
