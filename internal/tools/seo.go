package tools

import (
	"context"
	"encoding/json"
	"fmt"
	"strings"

	"github.com/zanfridau/marketminded/internal/ai"
	"github.com/zanfridau/marketminded/internal/seo"
)

// --- Tool definitions ---

func NewKeywordResearchTool() ai.Tool {
	return ai.Tool{
		Type: "function",
		Function: &ai.ToolFunction{
			Name:        "keyword_research",
			Description: "Look up search volume, CPC, and competition data for specific keywords. COSTS MONEY — use sparingly.",
			Parameters: json.RawMessage(`{"type":"object","properties":{"keywords":{"type":"array","items":{"type":"string"},"description":"Keywords to research (max 5)"},"location":{"type":"string","description":"Target location (e.g. \"United States\"). Defaults to United States if omitted."}},"required":["keywords"]}`),
		},
	}
}

func NewKeywordSuggestionsTool() ai.Tool {
	return ai.Tool{
		Type: "function",
		Function: &ai.ToolFunction{
			Name:        "keyword_suggestions",
			Description: "Get related keyword suggestions for a seed keyword, including search volume and difficulty. COSTS MONEY — use sparingly.",
			Parameters: json.RawMessage(`{"type":"object","properties":{"seed_keyword":{"type":"string","description":"The seed keyword to find related keywords for"},"location":{"type":"string","description":"Target location (e.g. \"United States\"). Defaults to United States if omitted."}},"required":["seed_keyword"]}`),
		},
	}
}

func NewDomainKeywordsTool() ai.Tool {
	return ai.Tool{
		Type: "function",
		Function: &ai.ToolFunction{
			Name:        "domain_keywords",
			Description: "Get the top keywords a domain ranks for, including position, search volume, and difficulty. COSTS MONEY — use sparingly.",
			Parameters: json.RawMessage(`{"type":"object","properties":{"domain":{"type":"string","description":"The domain to check rankings for (e.g. \"example.com\")"},"location":{"type":"string","description":"Target location (e.g. \"United States\"). Defaults to United States if omitted."}},"required":["domain"]}`),
		},
	}
}

// --- Executor ---

// NewSEOExecutor returns an executor function that dispatches SEO tool calls
// to the appropriate seo.Client method.
func NewSEOExecutor(client *seo.Client) func(ctx context.Context, name, argsJSON string) (string, error) {
	return func(ctx context.Context, name, argsJSON string) (string, error) {
		switch name {
		case "keyword_research":
			return execKeywordResearch(ctx, client, argsJSON)
		case "keyword_suggestions":
			return execKeywordSuggestions(ctx, client, argsJSON)
		case "domain_keywords":
			return execDomainKeywords(ctx, client, argsJSON)
		default:
			return "", fmt.Errorf("unknown SEO tool: %s", name)
		}
	}
}

// --- Exec functions ---

type keywordResearchArgs struct {
	Keywords []string `json:"keywords"`
	Location string   `json:"location"`
}

func execKeywordResearch(ctx context.Context, client *seo.Client, argsJSON string) (string, error) {
	var args keywordResearchArgs
	if err := json.Unmarshal([]byte(argsJSON), &args); err != nil {
		return "", fmt.Errorf("invalid arguments: %w", err)
	}

	metrics, err := client.SearchVolume(ctx, args.Keywords, args.Location)
	if err != nil {
		return "", fmt.Errorf("keyword research failed: %w", err)
	}

	if len(metrics) == 0 {
		return "No data found for the given keywords.", nil
	}

	var b strings.Builder
	for _, m := range metrics {
		fmt.Fprintf(&b, "- %s: %d searches/mo, $%.2f CPC, competition: %s (index: %.0f)\n",
			m.Keyword, m.SearchVolume, m.CPC, m.Competition, m.CompetitionIndex)
	}
	return b.String(), nil
}

type keywordSuggestionsArgs struct {
	SeedKeyword string `json:"seed_keyword"`
	Location    string `json:"location"`
}

func execKeywordSuggestions(ctx context.Context, client *seo.Client, argsJSON string) (string, error) {
	var args keywordSuggestionsArgs
	if err := json.Unmarshal([]byte(argsJSON), &args); err != nil {
		return "", fmt.Errorf("invalid arguments: %w", err)
	}

	suggestions, err := client.RelatedKeywords(ctx, args.SeedKeyword, args.Location)
	if err != nil {
		return "", fmt.Errorf("keyword suggestions failed: %w", err)
	}

	if len(suggestions) == 0 {
		return "No related keywords found.", nil
	}

	var b strings.Builder
	for _, s := range suggestions {
		fmt.Fprintf(&b, "- %s: %d searches/mo, difficulty: %.0f/100, $%.2f CPC, competition: %s\n",
			s.Keyword, s.SearchVolume, s.KeywordDifficulty, s.CPC, s.Competition)
	}
	return b.String(), nil
}

type domainKeywordsArgs struct {
	Domain   string `json:"domain"`
	Location string `json:"location"`
}

func execDomainKeywords(ctx context.Context, client *seo.Client, argsJSON string) (string, error) {
	var args domainKeywordsArgs
	if err := json.Unmarshal([]byte(argsJSON), &args); err != nil {
		return "", fmt.Errorf("invalid arguments: %w", err)
	}

	ranked, err := client.RankedKeywords(ctx, args.Domain, args.Location)
	if err != nil {
		return "", fmt.Errorf("domain keywords failed: %w", err)
	}

	if len(ranked) == 0 {
		return "No ranked keywords found for this domain.", nil
	}

	var b strings.Builder
	for _, r := range ranked {
		fmt.Fprintf(&b, "- %s: position #%d, %d searches/mo, difficulty: %.0f/100, $%.2f CPC\n  URL: %s\n",
			r.Keyword, r.Position, r.SearchVolume, r.KeywordDifficulty, r.CPC, r.URL)
	}
	return b.String(), nil
}

// --- Summary ---

// SEOToolSummary returns a human-readable summary for the streaming UI.
func SEOToolSummary(name, argsJSON string) string {
	switch name {
	case "keyword_research":
		var args keywordResearchArgs
		if err := json.Unmarshal([]byte(argsJSON), &args); err != nil {
			return "Researching keywords..."
		}
		return fmt.Sprintf("Researching keywords: %s", strings.Join(args.Keywords, ", "))
	case "keyword_suggestions":
		var args keywordSuggestionsArgs
		if err := json.Unmarshal([]byte(argsJSON), &args); err != nil {
			return "Finding related keywords..."
		}
		return fmt.Sprintf("Finding keywords related to: %s", args.SeedKeyword)
	case "domain_keywords":
		var args domainKeywordsArgs
		if err := json.Unmarshal([]byte(argsJSON), &args); err != nil {
			return "Checking domain rankings..."
		}
		return fmt.Sprintf("Checking rankings for: %s", args.Domain)
	default:
		return "Running SEO tool..."
	}
}
