package tools

import (
	"context"
	"encoding/json"
	"fmt"

	"github.com/zanfridau/marketminded/internal/ai"
	"github.com/zanfridau/marketminded/internal/content"
)

// Per-step OpenRouter web_search caps. max_total_results is enforced
// server-side across the whole turn — these are the safety net, not the
// number we want the model to actually hit. Keep prompt guidance well below.
const (
	ResearchSearchCap      = 30
	ClaimVerifierSearchCap = 15
	TopicExploreSearchCap  = 25

	// StyleReferenceMaxFetches caps how many fetch_url calls the
	// style_reference step can make in total (index + candidate posts).
	StyleReferenceMaxFetches = 6
)

// Registry holds tool definitions and executors for each pipeline step.
type Registry struct {
	stepTools map[string][]ai.Tool
}

// NewRegistry creates a Registry with tool definitions for each step type.
func NewRegistry() *Registry {
	r := &Registry{
		stepTools: make(map[string][]ai.Tool),
	}

	fetchTool := NewFetchTool()

	// Server tool search limits: max_results per query, max_total_results across all queries.
	researchSearch := ai.ServerTool("openrouter:web_search", json.RawMessage(fmt.Sprintf(`{"max_results":5,"max_total_results":%d}`, ResearchSearchCap)))
	verifierSearch := ai.ServerTool("openrouter:web_search", json.RawMessage(fmt.Sprintf(`{"max_results":3,"max_total_results":%d}`, ClaimVerifierSearchCap)))
	topicSearch := ai.ServerTool("openrouter:web_search", json.RawMessage(fmt.Sprintf(`{"max_results":5,"max_total_results":%d}`, TopicExploreSearchCap)))

	r.stepTools["research"] = []ai.Tool{fetchTool, researchSearch, submitTool(
		"submit_research",
		"Submit your research findings as structured claims with source attribution. Call when you have gathered enough material.",
		`{"type":"object","properties":{"sources":{"type":"array","description":"List of sources found during research. Each gets a stable id like s1, s2.","items":{"type":"object","properties":{"id":{"type":"string","description":"Stable id, format s1, s2, ..."},"url":{"type":"string"},"title":{"type":"string"},"summary":{"type":"string","description":"What this source contributes"},"date":{"type":"string","description":"Publication date if known"}},"required":["id","url","title","summary"]}},"claims":{"type":"array","description":"Factual atoms extracted from the research. Each is a single declarative sentence stating one verifiable fact, with at least one source_id citation.","items":{"type":"object","properties":{"id":{"type":"string","description":"Stable id, format c1, c2, ..."},"text":{"type":"string","description":"Single declarative sentence stating one verifiable fact"},"type":{"type":"string","enum":["stat","quote","fact","date","price"]},"source_ids":{"type":"array","items":{"type":"string"},"description":"IDs of sources backing this claim. At least one required."}},"required":["id","text","type","source_ids"]}},"brief":{"type":"string","description":"Short narrative direction (3-6 sentences). Story, angles, what's surprising. Do NOT repeat facts here — those belong in claims."}},"required":["sources","claims","brief"]}`,
	)}

	r.stepTools["brand_enricher"] = []ai.Tool{fetchTool, submitTool(
		"submit_brand_enrichment",
		"Submit the merged claims and sources after fetching brand URLs. Append-only: existing claims and sources are immutable.",
		`{"type":"object","properties":{"sources":{"type":"array","description":"FULL merged sources list: original research sources unchanged + new brand sources appended with new ids continuing the sequence","items":{"type":"object","properties":{"id":{"type":"string"},"url":{"type":"string"},"title":{"type":"string"},"summary":{"type":"string"},"date":{"type":"string"}},"required":["id","url","title"]}},"claims":{"type":"array","description":"FULL merged claims list: original research claims unchanged + new brand claims appended with new ids continuing the sequence","items":{"type":"object","properties":{"id":{"type":"string"},"text":{"type":"string"},"type":{"type":"string","enum":["stat","quote","fact","date","price"]},"source_ids":{"type":"array","items":{"type":"string"}}},"required":["id","text","type","source_ids"]}},"enriched_brief":{"type":"string","description":"Updated narrative direction weaving the brand into the story. Short, narrative, NOT a place to repeat facts."}},"required":["sources","claims","enriched_brief"]}`,
	)}

	r.stepTools["claim_verifier"] = []ai.Tool{fetchTool, verifierSearch, submitTool(
		"submit_claim_verification",
		"Submit your verification results. Patches the claims array in place; preserves all claim ids; may append new sources.",
		`{"type":"object","properties":{"verified_claims":{"type":"array","description":"Per-claim verdict for the 3-5 high-risk claims you verified. Audit trail for the UI.","items":{"type":"object","properties":{"id":{"type":"string","description":"Echo of the claim id you verified"},"verdict":{"type":"string","enum":["confirmed","corrected","unverifiable"]},"corrected_text":{"type":"string","description":"Required when verdict=corrected. The replacement claim text."},"note":{"type":"string","description":"Short justification with the source you checked"}},"required":["id","verdict"]}},"claims":{"type":"array","description":"FULL claims array with corrections applied in place. Preserve every claim id from the input. Do NOT add or remove claims.","items":{"type":"object","properties":{"id":{"type":"string"},"text":{"type":"string"},"type":{"type":"string","enum":["stat","quote","fact","date","price"]},"source_ids":{"type":"array","items":{"type":"string"}}},"required":["id","text","type","source_ids"]}},"sources":{"type":"array","description":"FULL sources list. May append new sources you used during verification. Preserve every existing source id and url.","items":{"type":"object","properties":{"id":{"type":"string"},"url":{"type":"string"},"title":{"type":"string"},"summary":{"type":"string"},"date":{"type":"string"}},"required":["id","url","title"]}}},"required":["verified_claims","claims","sources"]}`,
	)}

	r.stepTools["editor"] = []ai.Tool{submitTool(
		"submit_editorial_outline",
		"Submit the structured editorial outline. Each section references the specific claim ids it leans on.",
		`{"type":"object","properties":{"angle":{"type":"string","description":"The core narrative angle in one sentence"},"sections":{"type":"array","description":"Ordered sections of the article","items":{"type":"object","properties":{"heading":{"type":"string"},"framework_beat":{"type":"string","description":"Storytelling framework beat this maps to, if any"},"key_points":{"type":"array","items":{"type":"string"},"description":"Specific points to make"},"claim_ids":{"type":"array","items":{"type":"string"},"description":"Claim ids (e.g. c3, c7) from the claims array that back this section. The writer reads these specific claims for this section."},"editorial_notes":{"type":"string","description":"Tone and approach guidance for this section"}},"required":["heading","key_points","claim_ids"]}},"conclusion_strategy":{"type":"string","description":"How to close: what ties back, what CTA, what feeling to leave"}},"required":["angle","sections","conclusion_strategy"]}`,
	)}

	r.stepTools["style_reference"] = []ai.Tool{fetchTool, submitTool(
		"submit_style_reference",
		"Submit 2-3 chosen blog posts as voice reference for the writer. Submit URLs only — the system fetches bodies server-side.",
		`{"type":"object","properties":{"examples":{"type":"array","minItems":2,"maxItems":3,"description":"2-3 high-quality posts from the brand's blog. Submit URLs only — the system will fetch the full post bodies server-side after you submit.","items":{"type":"object","properties":{"url":{"type":"string"},"title":{"type":"string"},"why_chosen":{"type":"string","description":"One sentence on what makes this post a strong style exemplar."}},"required":["url","title","why_chosen"]}},"reasoning":{"type":"string","description":"Brief note on how the candidates were narrowed down."}},"required":["examples","reasoning"]}`,
	)}

	r.stepTools["audience_picker"] = []ai.Tool{submitTool(
		"submit_audience_selection",
		"Submit the audience selection. Call on your first response.",
		`{"type":"object","properties":{"mode":{"type":"string","enum":["persona","educational","commentary"],"description":"Pick persona when a real persona fits; off-modes only when nothing fits."},"persona_id":{"type":["integer","null"],"description":"Required when mode=persona, must match an existing persona id. Null otherwise."},"reasoning":{"type":"string","description":"1-3 sentences: why this target, and which competing options were rejected and why."},"guidance_for_writer":{"type":"string","description":"2-4 sentences of concrete guidance downstream steps must honor. When the topic involves a product recommendation, include at least one explicit 'do not recommend X to Y' constraint."}},"required":["mode","reasoning","guidance_for_writer"]}`,
	)}

	r.stepTools["topic_explore"] = []ai.Tool{fetchTool, topicSearch, submitTool(
		"submit_topics",
		"Submit your discovered topic candidates. Call this when you have 3-5 well-researched topics ready.",
		`{"type":"object","properties":{"topics":{"type":"array","description":"3-5 topic candidates","items":{"type":"object","properties":{"title":{"type":"string","description":"Topic title — specific and compelling"},"angle":{"type":"string","description":"Why this topic fits the brand and what angle to take, 1-2 sentences"},"evidence":{"type":"string","description":"What research supports this topic — trends, gaps, audience interest"}},"required":["title","angle","evidence"]}}},"required":["topics"]}`,
	)}

	r.stepTools["topic_review"] = []ai.Tool{submitTool(
		"submit_review",
		"Submit your review of the proposed topics. Approve or reject each one with clear reasoning.",
		`{"type":"object","properties":{"reviews":{"type":"array","description":"One review per proposed topic","items":{"type":"object","properties":{"title":{"type":"string","description":"Echo back the topic title exactly"},"verdict":{"type":"string","enum":["approved","rejected"],"description":"Whether this topic passes the common sense check"},"reasoning":{"type":"string","description":"Why this topic was approved or rejected"}},"required":["title","verdict","reasoning"]}}},"required":["reviews"]}`,
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
	default:
		return "", fmt.Errorf("unknown tool: %s", name)
	}
}

func submitTool(name, description, paramsJSON string) ai.Tool {
	return ai.Tool{
		Type: "function",
		Function: &ai.ToolFunction{
			Name:        name,
			Description: description,
			Parameters:  json.RawMessage(paramsJSON),
		},
	}
}
