package agents

import (
	"context"
	"testing"

	"github.com/zanfridau/marketminded/internal/types"
)


func TestIdeaAgent_Generate(t *testing.T) {
	ai := &mockAI{response: "1. How to scale your web agency\n2. 5 tips for client retention"}
	searcher := &mockSearcher{results: []types.SearchResult{
		{Title: "Web Agency Growth", URL: "https://example.com", Description: "Guide to growing"},
	}}

	agent := NewIdeaAgent(ai, searcher, testModel)

	ideas, err := agent.Generate(context.Background(), IdeaInput{
		Niche:      "web development agency",
		ContentLog: []string{"Previous: How we onboard clients"},
		Profile:    "test profile",
	})
	if err != nil {
		t.Fatalf("generate: %v", err)
	}
	if ideas == "" {
		t.Fatal("expected non-empty ideas")
	}
}
