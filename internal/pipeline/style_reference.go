package pipeline

import (
	"context"
	"encoding/json"
	"fmt"
	"strings"
)

const styleReferenceMinBodyChars = 400

// StyleReferenceExample is one verbatim post the style scout picked.
type StyleReferenceExample struct {
	URL       string `json:"url"`
	Title     string `json:"title"`
	Body      string `json:"body"`
	WhyChosen string `json:"why_chosen"`
}

// StyleReference is the step output of the style_reference step.
type StyleReference struct {
	Examples  []StyleReferenceExample `json:"examples"`
	Reasoning string                  `json:"reasoning"`
}

// ParseStyleReference parses the style_reference step output JSON and enforces
// the invariants the tool executor should already have caught (2-3 examples,
// min body length, all required fields present).
func ParseStyleReference(raw string) (*StyleReference, error) {
	if strings.TrimSpace(raw) == "" {
		return nil, fmt.Errorf("empty style reference")
	}
	var ref StyleReference
	if err := json.Unmarshal([]byte(raw), &ref); err != nil {
		return nil, fmt.Errorf("parse style reference: %w", err)
	}
	if n := len(ref.Examples); n < 2 || n > 3 {
		return nil, fmt.Errorf("style reference must have 2-3 examples, got %d", n)
	}
	for i, ex := range ref.Examples {
		if strings.TrimSpace(ex.URL) == "" {
			return nil, fmt.Errorf("example[%d] missing url", i)
		}
		if strings.TrimSpace(ex.Title) == "" {
			return nil, fmt.Errorf("example[%d] missing title", i)
		}
		if strings.TrimSpace(ex.WhyChosen) == "" {
			return nil, fmt.Errorf("example[%d] missing why_chosen", i)
		}
	}
	return &ref, nil
}

// PopulateStyleReferenceBodies fetches each example URL and populates its
// Body field. Examples whose body comes back shorter than the minimum after
// fetch are dropped. Requires at least 2 examples to remain after filtering.
//
// fetcher is a function that takes a URL and returns the extracted body text.
// In production, callers pass tools.ExecuteFetch wrapped in a closure that
// builds the {"url":"..."} args JSON. In tests, callers pass a fake.
func PopulateStyleReferenceBodies(ctx context.Context, ref *StyleReference, fetcher func(ctx context.Context, url string) (string, error)) error {
	if ref == nil {
		return fmt.Errorf("nil style reference")
	}
	var kept []StyleReferenceExample
	for _, ex := range ref.Examples {
		body, err := fetcher(ctx, ex.URL)
		if err != nil {
			// log and drop
			continue
		}
		if len(body) < styleReferenceMinBodyChars {
			continue
		}
		ex.Body = body
		kept = append(kept, ex)
	}
	if len(kept) < 2 {
		return fmt.Errorf("style reference: only %d examples passed body fetch (need at least 2)", len(kept))
	}
	ref.Examples = kept
	return nil
}

// FormatStyleReferenceBlock renders the style reference as a prompt block for
// the writer. Returns empty string for a nil ref (step was skipped). Each
// example body is included verbatim — this block is the whole value of the
// step.
func FormatStyleReferenceBlock(ref *StyleReference) string {
	if ref == nil {
		return ""
	}
	var b strings.Builder
	b.WriteString("\n## Style reference — match this voice\n")
	b.WriteString("The following posts are real, previously published pieces from this brand's blog. They are the ground truth for how this brand sounds. When writing the new post below, match their rhythm, sentence length, opener patterns, register, and overall feel. The reader should not be able to tell which post was written by AI.\n\n")
	b.WriteString("Do NOT copy sentences, facts, or structure from these examples. They are voice reference only. The new post's content comes from the claims block above.\n\n")
	for i, ex := range ref.Examples {
		fmt.Fprintf(&b, "### Example %d: %s\n", i+1, ex.Title)
		fmt.Fprintf(&b, "%s\n\n", ex.Body)
	}
	return b.String()
}
