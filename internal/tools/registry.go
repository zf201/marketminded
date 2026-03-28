package tools

import (
	"context"
	"encoding/json"
	"fmt"

	"github.com/zanfridau/marketminded/internal/ai"
	"github.com/zanfridau/marketminded/internal/content"
	"github.com/zanfridau/marketminded/internal/search"
)

// Registry holds tool definitions and executors for each pipeline step.
type Registry struct {
	braveClient *search.BraveClient
	stepTools   map[string][]ai.Tool
}

// NewRegistry creates a Registry with tool definitions for each step type.
func NewRegistry(braveClient *search.BraveClient) *Registry {
	r := &Registry{
		braveClient: braveClient,
		stepTools:   make(map[string][]ai.Tool),
	}

	fetchTool := NewFetchTool()
	searchTool := NewSearchTool()

	r.stepTools["research"] = []ai.Tool{fetchTool, searchTool, submitTool(
		"submit_research",
		"Submit your research findings. Call this when you have gathered sufficient sources and are ready to write the research brief.",
		`{"type":"object","properties":{"sources":{"type":"array","description":"List of sources found during research","items":{"type":"object","properties":{"url":{"type":"string","description":"Source URL"},"title":{"type":"string","description":"Source title"},"summary":{"type":"string","description":"What this source contributes"},"date":{"type":"string","description":"Publication date if known"}},"required":["url","title","summary"]}},"brief":{"type":"string","description":"A comprehensive research brief synthesizing all findings. Include key facts, angles, statistics, and anything the writer needs to produce an authoritative piece."}},"required":["sources","brief"]}`,
	)}

	r.stepTools["brand_enricher"] = []ai.Tool{fetchTool, submitTool(
		"submit_brand_enrichment",
		"Submit the enriched research brief. You MUST call this tool to deliver your results.",
		`{"type":"object","properties":{"enriched_brief":{"type":"string","description":"The complete research brief rewritten with brand context woven in — product names, pricing, features, messaging. Include everything the writer needs."},"sources":{"type":"array","description":"ALL sources: original research sources plus brand URLs you fetched","items":{"type":"object","properties":{"url":{"type":"string"},"title":{"type":"string"},"summary":{"type":"string"}},"required":["url","title"]}}},"required":["enriched_brief","sources"]}`,
	)}

	r.stepTools["factcheck"] = []ai.Tool{fetchTool, searchTool, submitTool(
		"submit_factcheck",
		"Submit your fact-check results. Call this when you have verified the research and are ready to provide the enriched brief.",
		`{"type":"object","properties":{"issues_found":{"type":"array","description":"List of issues found during fact-checking (may be empty if everything checks out)","items":{"type":"object","properties":{"claim":{"type":"string","description":"The claim that was checked"},"problem":{"type":"string","description":"What is wrong or uncertain"},"resolution":{"type":"string","description":"How to address this in the final content"}},"required":["claim","problem","resolution"]}},"enriched_brief":{"type":"string","description":"The research brief, corrected and enriched with any additional context from fact-checking. This is what the writer will use."},"sources":{"type":"array","description":"Verified sources to cite in the final piece","items":{"type":"object","properties":{"url":{"type":"string"},"title":{"type":"string"},"summary":{"type":"string"},"date":{"type":"string"}},"required":["url","title","summary"]}}},"required":["issues_found","enriched_brief","sources"]}`,
	)}

	r.stepTools["tone_analyzer"] = []ai.Tool{fetchTool, submitTool(
		"submit_tone_analysis",
		"Submit the tone and style guide based on the company's existing blog posts.",
		`{"type":"object","properties":{"tone_guide":{"type":"string","description":"A concise guide describing the writing tone, voice, style patterns, sentence structure, vocabulary level, and formatting conventions observed in the blog posts. The writer will use this to match the brand's voice."},"posts":{"type":"array","description":"The blog posts that were analyzed","items":{"type":"object","properties":{"title":{"type":"string"},"url":{"type":"string"},"excerpt":{"type":"string","description":"A short excerpt showing the post's typical writing style"}},"required":["title","url"]}}},"required":["tone_guide","posts"]}`,
	)}

	r.stepTools["editor"] = []ai.Tool{submitTool(
		"submit_editorial_outline",
		"Submit the structured editorial outline for the writer. Call this when you have determined the narrative structure.",
		`{"type":"object","properties":{"angle":{"type":"string","description":"The core narrative angle in one sentence"},"sections":{"type":"array","description":"Ordered sections of the article","items":{"type":"object","properties":{"heading":{"type":"string","description":"Suggested section heading"},"framework_beat":{"type":"string","description":"Storytelling framework beat this maps to, if any"},"key_points":{"type":"array","items":{"type":"string"},"description":"Specific points to make, with data/stats where relevant"},"sources_to_use":{"type":"array","items":{"type":"string"},"description":"Source URLs that back the points in this section"},"editorial_notes":{"type":"string","description":"Tone and approach guidance for this section"}},"required":["heading","key_points"]}},"conclusion_strategy":{"type":"string","description":"How to close: what ties back, what CTA, what feeling to leave"}},"required":["angle","sections","conclusion_strategy"]}`,
	)}

	// Writer step uses the blog_post content type tool
	ct, ok := content.LookupType("blog", "post")
	if ok {
		r.stepTools["write"] = []ai.Tool{ct.Tool}
	}

	return r
}

// ForStep returns the tool definitions for a given step type.
func (r *Registry) ForStep(stepType string) []ai.Tool {
	return r.stepTools[stepType]
}

// Execute runs a base tool (fetch_url or web_search) by name.
// Submit tools are NOT handled here — each step's executor handles those.
func (r *Registry) Execute(ctx context.Context, name, argsJSON string) (string, error) {
	switch name {
	case "fetch_url":
		return ExecuteFetch(ctx, argsJSON)
	case "web_search":
		if r.braveClient == nil {
			return "", fmt.Errorf("web_search not available: no Brave client configured")
		}
		exec := NewSearchExecutor(r.braveClient)
		return exec(ctx, argsJSON)
	default:
		return "", fmt.Errorf("unknown tool: %s", name)
	}
}

func submitTool(name, description, paramsJSON string) ai.Tool {
	return ai.Tool{
		Type: "function",
		Function: ai.ToolFunction{
			Name:        name,
			Description: description,
			Parameters:  json.RawMessage(paramsJSON),
		},
	}
}
