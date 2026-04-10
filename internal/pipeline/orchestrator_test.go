package pipeline_test

import (
	"testing"

	"github.com/zanfridau/marketminded/internal/pipeline"
)

func TestStepDependencies(t *testing.T) {
	deps := pipeline.StepDependencies()

	if len(deps["research"]) != 0 {
		t.Errorf("research should have no deps, got %v", deps["research"])
	}
	if deps["brand_enricher"][0] != "research" {
		t.Errorf("brand_enricher should depend on research")
	}
	if deps["claim_verifier"][0] != "brand_enricher" {
		t.Errorf("claim_verifier should depend on brand_enricher")
	}
	if deps["editor"][0] != "brand_enricher" {
		t.Errorf("editor should depend on brand_enricher (claim_verifier dep is dynamic)")
	}
	if deps["write"][0] != "editor" {
		t.Errorf("write should depend on editor")
	}
}

func TestStepDependencies_IncludesNewSteps(t *testing.T) {
	deps := pipeline.StepDependencies()
	audDeps, ok := deps["audience_picker"]
	if !ok {
		t.Fatal("audience_picker missing from StepDependencies")
	}
	if len(audDeps) != 1 || audDeps[0] != "research" {
		t.Errorf("audience_picker deps: want [research], got %v", audDeps)
	}
	styleDeps, ok := deps["style_reference"]
	if !ok {
		t.Fatal("style_reference missing from StepDependencies")
	}
	if len(styleDeps) != 1 || styleDeps[0] != "editor" {
		t.Errorf("style_reference deps: want [editor], got %v", styleDeps)
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
