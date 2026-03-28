package steps

import (
	"context"
	"strings"

	"github.com/zanfridau/marketminded/internal/ai"
	"github.com/zanfridau/marketminded/internal/pipeline"
	"github.com/zanfridau/marketminded/internal/prompt"
	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/internal/tools"
)

type ToneAnalyzerStep struct {
	AI              *ai.Client
	Tools           *tools.Registry
	Prompt          *prompt.Builder
	ProjectSettings store.ProjectSettingsStore
	Model           func() string
}

func (s *ToneAnalyzerStep) Type() string { return "tone_analyzer" }

func (s *ToneAnalyzerStep) Run(ctx context.Context, input pipeline.StepInput, stream pipeline.StepStream) (pipeline.StepResult, error) {
	settings, _ := s.ProjectSettings.AllProjectSettings(input.ProjectID)
	blogURL := settings["company_blog"]

	if blogURL == "" {
		stream.SendDone()
		return pipeline.StepResult{Output: `{"tone_guide":"","posts":[]}`}, nil
	}

	blogURLs := strings.Join(splitURLs(blogURL), "\n")
	systemPrompt := s.Prompt.ForToneAnalyzer(blogURLs)
	toolList := s.Tools.ForStep("tone_analyzer")
	return runWithTools(ctx, s.AI, s.Model(), systemPrompt, "Fetch the blog posts and analyze the writing tone.", toolList, s.Tools, "submit_tone_analysis", stream, 0.3, 10)
}
