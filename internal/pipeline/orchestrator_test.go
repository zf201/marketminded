package pipeline_test

import (
	"testing"

	"github.com/zanfridau/marketminded/internal/pipeline"
	"github.com/zanfridau/marketminded/internal/store"
)

func TestCollectSources(t *testing.T) {
	steps := []store.PipelineStep{
		{Output: `{"sources":[{"url":"https://a.com","title":"A","summary":"s1"}]}`},
		{Output: `{"sources":[{"url":"https://a.com","title":"A","summary":"s1"},{"url":"https://b.com","title":"B","summary":"s2"}]}`},
		{Output: ""},
	}

	sources := pipeline.CollectSources(steps)
	if len(sources) != 2 {
		t.Fatalf("expected 2 unique sources, got %d", len(sources))
	}
	if sources[0].URL != "https://a.com" {
		t.Errorf("expected first source a.com, got %s", sources[0].URL)
	}
	if sources[1].URL != "https://b.com" {
		t.Errorf("expected second source b.com, got %s", sources[1].URL)
	}
}

func TestFormatSourcesText_Empty(t *testing.T) {
	result := pipeline.FormatSourcesText(nil)
	if result != "" {
		t.Errorf("expected empty string, got: %q", result)
	}
}

func TestStepDependencies(t *testing.T) {
	deps := pipeline.StepDependencies()

	if len(deps["research"]) != 0 {
		t.Errorf("research should have no deps, got %v", deps["research"])
	}
	if deps["brand_enricher"][0] != "research" {
		t.Errorf("brand_enricher should depend on research")
	}
	if deps["write"][0] != "editor" {
		t.Errorf("write should depend on editor")
	}
}

func TestToolCallsJSON_Empty(t *testing.T) {
	result := pipeline.ToolCallsJSON(nil)
	if result != "" {
		t.Errorf("expected empty string, got: %q", result)
	}
}

func TestToolCallsJSON_WithRecords(t *testing.T) {
	records := []pipeline.ToolCallRecord{
		{Type: "fetch", Value: "https://example.com"},
	}
	result := pipeline.ToolCallsJSON(records)
	if result == "" {
		t.Error("expected non-empty JSON")
	}
}
