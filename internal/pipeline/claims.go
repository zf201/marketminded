package pipeline

import (
	"encoding/json"
	"fmt"
	"strings"
)

// ClaimSource is a source entry inside a claims payload. It has an ID
// so claims can reference it without dragging URL strings around.
type ClaimSource struct {
	ID      string `json:"id"`
	URL     string `json:"url"`
	Title   string `json:"title,omitempty"`
	Summary string `json:"summary,omitempty"`
	Date    string `json:"date,omitempty"`
}

// Claim is a single declarative factual statement extracted from research.
type Claim struct {
	ID        string   `json:"id"`
	Text      string   `json:"text"`
	Type      string   `json:"type"` // stat | quote | fact | date | price
	SourceIDs []string `json:"source_ids"`
}

// ClaimsPayload is the structured output emitted by research, brand_enricher,
// and claim_verifier steps.
type ClaimsPayload struct {
	Sources []ClaimSource `json:"sources"`
	Claims  []Claim       `json:"claims"`
	Brief   string        `json:"brief,omitempty"`

	// Optional fields used by specific steps:
	EnrichedBrief  string          `json:"enriched_brief,omitempty"`  // brand_enricher
	VerifiedClaims []VerifiedClaim `json:"verified_claims,omitempty"` // claim_verifier
}

// VerifiedClaim is the audit-trail entry the claim_verifier emits per checked claim.
type VerifiedClaim struct {
	ID            string `json:"id"`
	Verdict       string `json:"verdict"` // confirmed | corrected | unverifiable
	Note          string `json:"note,omitempty"`
	CorrectedText string `json:"corrected_text,omitempty"`
}

// ParseClaimsPayload parses a JSON step output into a ClaimsPayload.
// Empty input is allowed and returns a zero-value payload (no error).
func ParseClaimsPayload(raw string) (ClaimsPayload, error) {
	var p ClaimsPayload
	if strings.TrimSpace(raw) == "" {
		return p, nil
	}
	if err := json.Unmarshal([]byte(raw), &p); err != nil {
		return p, fmt.Errorf("parse claims payload: %w", err)
	}
	return p, nil
}

// ValidateClaimsPayload checks structural integrity:
//   - claim IDs unique
//   - source IDs unique
//   - every claim cites at least one source
//   - every cited source ID exists in sources[]
func ValidateClaimsPayload(p ClaimsPayload) error {
	sourceIDs := make(map[string]bool, len(p.Sources))
	for _, s := range p.Sources {
		if s.ID == "" {
			return fmt.Errorf("source missing id (url=%q)", s.URL)
		}
		if sourceIDs[s.ID] {
			return fmt.Errorf("duplicate source id %q", s.ID)
		}
		sourceIDs[s.ID] = true
	}

	claimIDs := make(map[string]bool, len(p.Claims))
	for _, c := range p.Claims {
		if c.ID == "" {
			return fmt.Errorf("claim missing id (text=%q)", c.Text)
		}
		if claimIDs[c.ID] {
			return fmt.Errorf("duplicate claim id %q", c.ID)
		}
		claimIDs[c.ID] = true

		if c.Text == "" {
			return fmt.Errorf("claim %q has empty text", c.ID)
		}
		if len(c.SourceIDs) == 0 {
			return fmt.Errorf("claim %q must cite at least one source", c.ID)
		}
		for _, sid := range c.SourceIDs {
			if !sourceIDs[sid] {
				return fmt.Errorf("claim %q references unknown source id %q", c.ID, sid)
			}
		}
	}
	return nil
}

// ValidatePreservesPriorClaims checks that next contains every claim from prior
// with the same text and source_ids, and every source from prior with the same URL.
// Used by brand_enricher and claim_verifier to enforce the "do not mutate" rule.
//
// claim_verifier is allowed to update text via the corrected_text path; for that
// case the caller should pass the corrections-applied claims as `next`. The
// preservation check still uses the next.Claims text — so if a verifier patches
// c7's text in place, that patched text becomes the new "expected" downstream.
// Brand_enricher is strict: any text change is an error.
func ValidatePreservesPriorClaims(prior, next ClaimsPayload) error {
	nextClaims := make(map[string]Claim, len(next.Claims))
	for _, c := range next.Claims {
		nextClaims[c.ID] = c
	}
	for _, pc := range prior.Claims {
		nc, ok := nextClaims[pc.ID]
		if !ok {
			return fmt.Errorf("missing prior claim %q", pc.ID)
		}
		if nc.Text != pc.Text {
			return fmt.Errorf("prior claim %q was mutated (was %q, now %q)", pc.ID, pc.Text, nc.Text)
		}
		if !stringSlicesEqual(nc.SourceIDs, pc.SourceIDs) {
			return fmt.Errorf("prior claim %q source_ids changed (was %v, now %v)", pc.ID, pc.SourceIDs, nc.SourceIDs)
		}
	}

	nextSources := make(map[string]ClaimSource, len(next.Sources))
	for _, s := range next.Sources {
		nextSources[s.ID] = s
	}
	for _, ps := range prior.Sources {
		ns, ok := nextSources[ps.ID]
		if !ok {
			return fmt.Errorf("missing prior source %q", ps.ID)
		}
		if ns.URL != ps.URL {
			return fmt.Errorf("prior source %q url changed (was %q, now %q)", ps.ID, ps.URL, ns.URL)
		}
	}
	return nil
}

// ValidateVerifiedClaims checks the audit trail the claim_verifier step emits:
//   - every verified id references an existing claim in p.Claims
//   - verdict is in the enum {confirmed, corrected, unverifiable}
//   - corrected_text is non-empty when verdict == "corrected"
//
// Called by the claim_verifier step after ValidateClaimsPayload.
func ValidateVerifiedClaims(p ClaimsPayload) error {
	claimsByID := make(map[string]Claim, len(p.Claims))
	for _, c := range p.Claims {
		claimsByID[c.ID] = c
	}
	for _, vc := range p.VerifiedClaims {
		if _, ok := claimsByID[vc.ID]; !ok {
			return fmt.Errorf("verified_claims entry %q references unknown claim id", vc.ID)
		}
		switch vc.Verdict {
		case "confirmed", "corrected", "unverifiable":
			// ok
		default:
			return fmt.Errorf("verified_claims entry %q has invalid verdict %q", vc.ID, vc.Verdict)
		}
		if vc.Verdict == "corrected" && vc.CorrectedText == "" {
			return fmt.Errorf("verified_claims entry %q is verdict=corrected but corrected_text is empty", vc.ID)
		}
		if vc.Verdict == "corrected" && claimsByID[vc.ID].Text != vc.CorrectedText {
			return fmt.Errorf("verified_claims entry %q is verdict=corrected but claim text was not updated to match corrected_text", vc.ID)
		}
	}
	return nil
}

// FormatClaimsBlock renders a claims payload as a labeled block for prompt injection.
// The format is one line per claim with type, text, and source IDs, followed by a
// short legend mapping source IDs to URLs/titles. Editor and writer prompts both
// consume this format.
func FormatClaimsBlock(p ClaimsPayload) string {
	if len(p.Claims) == 0 && len(p.Sources) == 0 {
		return ""
	}
	var b strings.Builder
	b.WriteString("\n## Claims (factual atoms — every fact in your output MUST come from this list)\n")
	for _, c := range p.Claims {
		fmt.Fprintf(&b, "[%s] (%s) %s  [sources: %s]\n", c.ID, c.Type, c.Text, strings.Join(c.SourceIDs, ", "))
	}
	b.WriteString("\n## Sources (referenced by id from the claims above)\n")
	for _, s := range p.Sources {
		title := s.Title
		if title == "" {
			title = s.URL
		}
		fmt.Fprintf(&b, "[%s] %s — %s\n", s.ID, title, s.URL)
	}
	return b.String()
}

// FindLatestClaims returns the most recent ClaimsPayload from a step output map,
// preferring claim_verifier > brand_enricher > research. The second return value
// is the step type that produced the payload, or "" if none was found.
func FindLatestClaims(priorOutputs map[string]string) (ClaimsPayload, string) {
	for _, step := range []string{"claim_verifier", "brand_enricher", "research"} {
		raw, ok := priorOutputs[step]
		if !ok || strings.TrimSpace(raw) == "" {
			continue
		}
		p, err := ParseClaimsPayload(raw)
		if err != nil {
			continue
		}
		if len(p.Claims) > 0 || len(p.Sources) > 0 {
			return p, step
		}
	}
	return ClaimsPayload{}, ""
}

func stringSlicesEqual(a, b []string) bool {
	if len(a) != len(b) {
		return false
	}
	for i := range a {
		if a[i] != b[i] {
			return false
		}
	}
	return true
}
