package tools_test

import (
	"context"
	"testing"

	"github.com/zanfridau/marketminded/internal/tools"
)

func TestRegistry_ForStep(t *testing.T) {
	r := tools.NewRegistry(nil)

	researchTools := r.ForStep("research")
	if len(researchTools) != 3 {
		t.Fatalf("expected 3 tools for research (fetch_url, web_search, submit_research), got %d", len(researchTools))
	}

	names := make(map[string]bool)
	for _, tool := range researchTools {
		names[tool.Function.Name] = true
	}
	for _, expected := range []string{"fetch_url", "web_search", "submit_research"} {
		if !names[expected] {
			t.Errorf("expected tool %q in research step", expected)
		}
	}
}

func TestRegistry_ForStep_Writer(t *testing.T) {
	r := tools.NewRegistry(nil)
	writerTools := r.ForStep("write")
	if len(writerTools) != 1 {
		t.Fatalf("expected 1 tool for write (write_blog_post), got %d", len(writerTools))
	}
	if writerTools[0].Function.Name != "write_blog_post" {
		t.Errorf("expected write_blog_post, got %s", writerTools[0].Function.Name)
	}
}

func TestRegistry_Execute_UnknownTool(t *testing.T) {
	r := tools.NewRegistry(nil)
	_, err := r.Execute(context.Background(), "nonexistent_tool", "{}")
	if err == nil {
		t.Error("expected error for unknown tool")
	}
}
