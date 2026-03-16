package tools

import (
	"context"
	"encoding/json"
	"fmt"
	"strings"

	"github.com/zanfridau/marketminded/internal/ai"
	"github.com/zanfridau/marketminded/internal/search"
)

func NewSearchTool() ai.Tool {
	return ai.Tool{
		Type: "function",
		Function: ai.ToolFunction{
			Name:        "web_search",
			Description: "Search the web for information. Use this to research topics, find competitors, discover trends, or look up anything the user mentions.",
			Parameters:  json.RawMessage(`{"type":"object","properties":{"query":{"type":"string","description":"The search query"}},"required":["query"]}`),
		},
	}
}

type searchArgs struct {
	Query string `json:"query"`
}

func NewSearchExecutor(braveClient *search.BraveClient) func(ctx context.Context, argsJSON string) (string, error) {
	return func(ctx context.Context, argsJSON string) (string, error) {
		var args searchArgs
		if err := json.Unmarshal([]byte(argsJSON), &args); err != nil {
			return "", fmt.Errorf("invalid arguments: %w", err)
		}

		results, err := braveClient.Search(ctx, args.Query, 5)
		if err != nil {
			return "", fmt.Errorf("search failed: %w", err)
		}

		var b strings.Builder
		for i, r := range results {
			fmt.Fprintf(&b, "%d. %s (%s)\n   %s\n\n", i+1, r.Title, r.URL, r.Description)
		}
		return b.String(), nil
	}
}

func SearchSummary(argsJSON string) string {
	var args searchArgs
	if err := json.Unmarshal([]byte(argsJSON), &args); err != nil {
		return "Searching..."
	}
	return fmt.Sprintf("Searched: %s", args.Query)
}
