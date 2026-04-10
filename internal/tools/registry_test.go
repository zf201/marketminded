package tools_test

import (
	"context"
	"strings"
	"testing"

	"github.com/zanfridau/marketminded/internal/tools"
)

func TestRegistry_ForStep(t *testing.T) {
	r := tools.NewRegistry()

	researchTools := r.ForStep("research")
	if len(researchTools) != 3 {
		t.Fatalf("expected 3 tools for research (fetch_url, web_search, submit_research), got %d", len(researchTools))
	}

	// Check that we have the server tool for web search
	foundServerTool := false
	names := make(map[string]bool)
	for _, tool := range researchTools {
		if tool.Type == "openrouter:web_search" {
			foundServerTool = true
			continue
		}
		if tool.Function != nil {
			names[tool.Function.Name] = true
		}
	}
	if !foundServerTool {
		t.Error("expected openrouter:web_search server tool in research step")
	}
	for _, expected := range []string{"fetch_url", "submit_research"} {
		if !names[expected] {
			t.Errorf("expected tool %q in research step", expected)
		}
	}
}

func TestRegistry_ForStep_Writer(t *testing.T) {
	r := tools.NewRegistry()
	writerTools := r.ForStep("write")
	if len(writerTools) != 1 {
		t.Fatalf("expected 1 tool for write (write_blog_post), got %d", len(writerTools))
	}
	if writerTools[0].Function == nil || writerTools[0].Function.Name != "write_blog_post" {
		t.Errorf("expected write_blog_post, got unexpected tool")
	}
}

func TestRegistry_SubmitResearchHasClaims(t *testing.T) {
	r := tools.NewRegistry()
	for _, tool := range r.ForStep("research") {
		if tool.Function == nil || tool.Function.Name != "submit_research" {
			continue
		}
		params := string(tool.Function.Parameters)
		if !strings.Contains(params, `"claims"`) {
			t.Errorf("submit_research schema missing claims field:\n%s", params)
		}
		if !strings.Contains(params, `"source_ids"`) {
			t.Errorf("submit_research schema missing claims[].source_ids:\n%s", params)
		}
		return
	}
	t.Fatal("submit_research tool not found")
}

func TestRegistry_Execute_UnknownTool(t *testing.T) {
	r := tools.NewRegistry()
	_, err := r.Execute(context.Background(), "nonexistent_tool", "{}")
	if err == nil {
		t.Error("expected error for unknown tool")
	}
}
