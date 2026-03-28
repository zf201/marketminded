package steps

import (
	"context"
	"fmt"
	"strings"

	"github.com/zanfridau/marketminded/internal/ai"
	"github.com/zanfridau/marketminded/internal/pipeline"
	"github.com/zanfridau/marketminded/internal/prompt"
	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/internal/tools"
)

type BrandEnricherStep struct {
	AI              *ai.Client
	Tools           *tools.Registry
	Prompt          *prompt.Builder
	ProjectSettings store.ProjectSettingsStore
	Model           func() string
}

func (s *BrandEnricherStep) Type() string { return "brand_enricher" }

func (s *BrandEnricherStep) Run(ctx context.Context, input pipeline.StepInput, stream pipeline.StepStream) (pipeline.StepResult, error) {
	researchOutput := input.PriorOutputs["research"]

	settings, _ := s.ProjectSettings.AllProjectSettings(input.ProjectID)
	type brandURL struct{ URL, Notes, Label string }
	var urls []brandURL
	if v := settings["company_website"]; v != "" {
		for _, u := range splitURLs(v) {
			urls = append(urls, brandURL{u, settings["website_notes"], "Company Website"})
		}
	}
	if v := settings["company_pricing"]; v != "" {
		for _, u := range splitURLs(v) {
			urls = append(urls, brandURL{u, settings["pricing_notes"], "Pricing Page"})
		}
	}

	if len(urls) == 0 {
		stream.SendDone()
		return pipeline.StepResult{Output: researchOutput}, nil
	}

	var urlList strings.Builder
	for _, u := range urls {
		fmt.Fprintf(&urlList, "- %s: %s", u.Label, u.URL)
		if u.Notes != "" {
			fmt.Fprintf(&urlList, " (Usage notes: %s)", u.Notes)
		}
		urlList.WriteString("\n")
	}

	systemPrompt := s.Prompt.ForBrandEnricher(input.Profile, researchOutput, urlList.String())
	toolList := s.Tools.ForStep("brand_enricher")
	return runWithTools(ctx, s.AI, s.Model(), systemPrompt, "Fetch the brand URLs and enrich the research with brand context.", toolList, s.Tools, "submit_brand_enrichment", stream, 0.3, 12)
}

func splitURLs(s string) []string {
	parts := strings.Split(s, ",")
	var urls []string
	for _, p := range parts {
		p = strings.TrimSpace(p)
		if p != "" {
			urls = append(urls, p)
		}
	}
	return urls
}
