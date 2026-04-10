package pipeline_test

import (
	"strings"
	"testing"

	"github.com/zanfridau/marketminded/internal/pipeline"
)

func TestParseClaimsPayload_Valid(t *testing.T) {
	raw := `{
		"sources": [{"id":"s1","url":"https://a.com","title":"A","summary":"x"}],
		"claims":  [{"id":"c1","text":"Foo is bar.","type":"fact","source_ids":["s1"]}],
		"brief":   "n"
	}`
	p, err := pipeline.ParseClaimsPayload(raw)
	if err != nil {
		t.Fatalf("ParseClaimsPayload: %v", err)
	}
	if len(p.Claims) != 1 || p.Claims[0].ID != "c1" {
		t.Errorf("unexpected claims: %+v", p.Claims)
	}
	if len(p.Sources) != 1 || p.Sources[0].ID != "s1" {
		t.Errorf("unexpected sources: %+v", p.Sources)
	}
}

func TestParseClaimsPayload_Empty(t *testing.T) {
	p, err := pipeline.ParseClaimsPayload("")
	if err != nil {
		t.Fatalf("ParseClaimsPayload empty: %v", err)
	}
	if len(p.Claims) != 0 || len(p.Sources) != 0 {
		t.Errorf("expected empty payload, got %+v", p)
	}
}

func TestValidateClaimsPayload_OK(t *testing.T) {
	p := pipeline.ClaimsPayload{
		Sources: []pipeline.ClaimSource{{ID: "s1", URL: "https://a"}},
		Claims:  []pipeline.Claim{{ID: "c1", Text: "x", Type: "fact", SourceIDs: []string{"s1"}}},
	}
	if err := pipeline.ValidateClaimsPayload(p); err != nil {
		t.Errorf("expected valid, got %v", err)
	}
}

func TestValidateClaimsPayload_DuplicateClaimID(t *testing.T) {
	p := pipeline.ClaimsPayload{
		Sources: []pipeline.ClaimSource{{ID: "s1", URL: "https://a"}},
		Claims: []pipeline.Claim{
			{ID: "c1", Text: "a", Type: "fact", SourceIDs: []string{"s1"}},
			{ID: "c1", Text: "b", Type: "fact", SourceIDs: []string{"s1"}},
		},
	}
	err := pipeline.ValidateClaimsPayload(p)
	if err == nil || !strings.Contains(err.Error(), "duplicate claim id") {
		t.Errorf("expected duplicate claim id error, got %v", err)
	}
}

func TestValidateClaimsPayload_DuplicateSourceID(t *testing.T) {
	p := pipeline.ClaimsPayload{
		Sources: []pipeline.ClaimSource{
			{ID: "s1", URL: "https://a"},
			{ID: "s1", URL: "https://b"},
		},
	}
	err := pipeline.ValidateClaimsPayload(p)
	if err == nil || !strings.Contains(err.Error(), "duplicate source id") {
		t.Errorf("expected duplicate source id error, got %v", err)
	}
}

func TestValidateClaimsPayload_BrokenSourceRef(t *testing.T) {
	p := pipeline.ClaimsPayload{
		Sources: []pipeline.ClaimSource{{ID: "s1", URL: "https://a"}},
		Claims: []pipeline.Claim{
			{ID: "c1", Text: "x", Type: "fact", SourceIDs: []string{"s99"}},
		},
	}
	err := pipeline.ValidateClaimsPayload(p)
	if err == nil || !strings.Contains(err.Error(), "unknown source id") {
		t.Errorf("expected unknown source id error, got %v", err)
	}
}

func TestValidateClaimsPayload_NoSourceIDs(t *testing.T) {
	p := pipeline.ClaimsPayload{
		Sources: []pipeline.ClaimSource{{ID: "s1", URL: "https://a"}},
		Claims: []pipeline.Claim{
			{ID: "c1", Text: "x", Type: "fact", SourceIDs: nil},
		},
	}
	err := pipeline.ValidateClaimsPayload(p)
	if err == nil || !strings.Contains(err.Error(), "must cite at least one source") {
		t.Errorf("expected at-least-one-source error, got %v", err)
	}
}

func TestValidatePreservesPriorClaims_OK(t *testing.T) {
	prior := pipeline.ClaimsPayload{
		Sources: []pipeline.ClaimSource{{ID: "s1", URL: "https://a"}},
		Claims:  []pipeline.Claim{{ID: "c1", Text: "x", Type: "fact", SourceIDs: []string{"s1"}}},
	}
	next := pipeline.ClaimsPayload{
		Sources: []pipeline.ClaimSource{
			{ID: "s1", URL: "https://a"},
			{ID: "s2", URL: "https://b"},
		},
		Claims: []pipeline.Claim{
			{ID: "c1", Text: "x", Type: "fact", SourceIDs: []string{"s1"}},
			{ID: "c2", Text: "y", Type: "price", SourceIDs: []string{"s2"}},
		},
	}
	if err := pipeline.ValidatePreservesPriorClaims(prior, next); err != nil {
		t.Errorf("expected preservation OK, got %v", err)
	}
}

func TestValidatePreservesPriorClaims_DroppedClaim(t *testing.T) {
	prior := pipeline.ClaimsPayload{
		Sources: []pipeline.ClaimSource{{ID: "s1"}},
		Claims:  []pipeline.Claim{{ID: "c1", Text: "x", SourceIDs: []string{"s1"}}},
	}
	next := pipeline.ClaimsPayload{
		Sources: []pipeline.ClaimSource{{ID: "s1"}},
		Claims:  nil,
	}
	err := pipeline.ValidatePreservesPriorClaims(prior, next)
	if err == nil || !strings.Contains(err.Error(), "missing prior claim") {
		t.Errorf("expected missing-prior-claim error, got %v", err)
	}
}

func TestValidatePreservesPriorClaims_MutatedText(t *testing.T) {
	prior := pipeline.ClaimsPayload{
		Sources: []pipeline.ClaimSource{{ID: "s1"}},
		Claims:  []pipeline.Claim{{ID: "c1", Text: "original", SourceIDs: []string{"s1"}}},
	}
	next := pipeline.ClaimsPayload{
		Sources: []pipeline.ClaimSource{{ID: "s1"}},
		Claims:  []pipeline.Claim{{ID: "c1", Text: "mutated", SourceIDs: []string{"s1"}}},
	}
	err := pipeline.ValidatePreservesPriorClaims(prior, next)
	if err == nil || !strings.Contains(err.Error(), "mutated") {
		t.Errorf("expected mutated-claim error, got %v", err)
	}
}

func TestFormatClaimsBlock(t *testing.T) {
	p := pipeline.ClaimsPayload{
		Sources: []pipeline.ClaimSource{
			{ID: "s1", URL: "https://a", Title: "A"},
			{ID: "s2", URL: "https://b", Title: "B"},
		},
		Claims: []pipeline.Claim{
			{ID: "c1", Text: "Foo is bar.", Type: "fact", SourceIDs: []string{"s1", "s2"}},
		},
	}
	out := pipeline.FormatClaimsBlock(p)
	if !strings.Contains(out, "[c1]") || !strings.Contains(out, "Foo is bar.") {
		t.Errorf("missing claim line:\n%s", out)
	}
	if !strings.Contains(out, "s1") || !strings.Contains(out, "s2") {
		t.Errorf("missing source refs:\n%s", out)
	}
	if !strings.Contains(out, "https://a") {
		t.Errorf("missing source url in legend:\n%s", out)
	}
	if !strings.Contains(out, "[sources:") {
		t.Errorf("missing sources legend marker:\n%s", out)
	}
}

func TestValidatePreservesPriorClaims_MutatedSourceIDs(t *testing.T) {
	prior := pipeline.ClaimsPayload{
		Sources: []pipeline.ClaimSource{
			{ID: "s1", URL: "https://a"},
			{ID: "s2", URL: "https://b"},
		},
		Claims: []pipeline.Claim{
			{ID: "c1", Text: "x", Type: "fact", SourceIDs: []string{"s1"}},
		},
	}
	next := pipeline.ClaimsPayload{
		Sources: []pipeline.ClaimSource{
			{ID: "s1", URL: "https://a"},
			{ID: "s2", URL: "https://b"},
		},
		Claims: []pipeline.Claim{
			{ID: "c1", Text: "x", Type: "fact", SourceIDs: []string{"s2"}},
		},
	}
	err := pipeline.ValidatePreservesPriorClaims(prior, next)
	if err == nil || !strings.Contains(err.Error(), "source_ids changed") {
		t.Errorf("expected source_ids changed error, got %v", err)
	}
}

func TestValidatePreservesPriorClaims_MutatedSourceURL(t *testing.T) {
	prior := pipeline.ClaimsPayload{
		Sources: []pipeline.ClaimSource{{ID: "s1", URL: "https://original"}},
	}
	next := pipeline.ClaimsPayload{
		Sources: []pipeline.ClaimSource{{ID: "s1", URL: "https://mutated"}},
	}
	err := pipeline.ValidatePreservesPriorClaims(prior, next)
	if err == nil || !strings.Contains(err.Error(), "url changed") {
		t.Errorf("expected source url changed error, got %v", err)
	}
}

func TestValidateClaimsPayload_EmptyText(t *testing.T) {
	p := pipeline.ClaimsPayload{
		Sources: []pipeline.ClaimSource{{ID: "s1", URL: "https://a"}},
		Claims: []pipeline.Claim{
			{ID: "c1", Text: "", Type: "fact", SourceIDs: []string{"s1"}},
		},
	}
	err := pipeline.ValidateClaimsPayload(p)
	if err == nil || !strings.Contains(err.Error(), "empty text") {
		t.Errorf("expected empty text error, got %v", err)
	}
}

func TestValidateVerifiedClaims_OK(t *testing.T) {
	p := pipeline.ClaimsPayload{
		Claims: []pipeline.Claim{
			{ID: "c1", Text: "x", Type: "fact", SourceIDs: []string{"s1"}},
			{ID: "c2", Text: "y'", Type: "stat", SourceIDs: []string{"s1"}},
		},
		VerifiedClaims: []pipeline.VerifiedClaim{
			{ID: "c1", Verdict: "confirmed", Note: "matches fed data"},
			{ID: "c2", Verdict: "corrected", CorrectedText: "y'", Note: "updated"},
		},
	}
	if err := pipeline.ValidateVerifiedClaims(p); err != nil {
		t.Errorf("expected ok, got %v", err)
	}
}

func TestValidateVerifiedClaims_CorrectedTextMismatch(t *testing.T) {
	p := pipeline.ClaimsPayload{
		Claims: []pipeline.Claim{
			{ID: "c1", Text: "old value", Type: "fact", SourceIDs: []string{"s1"}},
		},
		VerifiedClaims: []pipeline.VerifiedClaim{
			{ID: "c1", Verdict: "corrected", CorrectedText: "new value"},
		},
	}
	err := pipeline.ValidateVerifiedClaims(p)
	if err == nil || !strings.Contains(err.Error(), "claim text was not updated") {
		t.Errorf("expected corrected text mismatch error, got %v", err)
	}
}

func TestValidateVerifiedClaims_UnknownID(t *testing.T) {
	p := pipeline.ClaimsPayload{
		Claims: []pipeline.Claim{
			{ID: "c1", Text: "x", Type: "fact", SourceIDs: []string{"s1"}},
		},
		VerifiedClaims: []pipeline.VerifiedClaim{
			{ID: "c99", Verdict: "confirmed"},
		},
	}
	err := pipeline.ValidateVerifiedClaims(p)
	if err == nil || !strings.Contains(err.Error(), "unknown claim id") {
		t.Errorf("expected unknown claim id error, got %v", err)
	}
}

func TestValidateVerifiedClaims_BadVerdict(t *testing.T) {
	p := pipeline.ClaimsPayload{
		Claims: []pipeline.Claim{{ID: "c1", Text: "x", Type: "fact", SourceIDs: []string{"s1"}}},
		VerifiedClaims: []pipeline.VerifiedClaim{
			{ID: "c1", Verdict: "bogus"},
		},
	}
	err := pipeline.ValidateVerifiedClaims(p)
	if err == nil || !strings.Contains(err.Error(), "invalid verdict") {
		t.Errorf("expected invalid verdict error, got %v", err)
	}
}

func TestValidateVerifiedClaims_CorrectedRequiresText(t *testing.T) {
	p := pipeline.ClaimsPayload{
		Claims: []pipeline.Claim{{ID: "c1", Text: "x", Type: "fact", SourceIDs: []string{"s1"}}},
		VerifiedClaims: []pipeline.VerifiedClaim{
			{ID: "c1", Verdict: "corrected"},
		},
	}
	err := pipeline.ValidateVerifiedClaims(p)
	if err == nil || !strings.Contains(err.Error(), "corrected_text is empty") {
		t.Errorf("expected corrected_text empty error, got %v", err)
	}
}

func TestFindLatestClaims_PrefersClaimVerifier(t *testing.T) {
	prior := map[string]string{
		"research":       `{"claims":[{"id":"c1","text":"r","type":"fact","source_ids":["s1"]}],"sources":[{"id":"s1","url":"r"}]}`,
		"brand_enricher": `{"claims":[{"id":"c2","text":"b","type":"fact","source_ids":["s2"]}],"sources":[{"id":"s2","url":"b"}]}`,
		"claim_verifier": `{"claims":[{"id":"c3","text":"v","type":"fact","source_ids":["s3"]}],"sources":[{"id":"s3","url":"v"}]}`,
	}
	p, source := pipeline.FindLatestClaims(prior)
	if source != "claim_verifier" {
		t.Errorf("want source claim_verifier, got %q", source)
	}
	if len(p.Claims) != 1 || p.Claims[0].ID != "c3" {
		t.Errorf("want c3, got %+v", p.Claims)
	}
}

func TestFindLatestClaims_FallsBackToBrandEnricher(t *testing.T) {
	prior := map[string]string{
		"research":       `{"claims":[{"id":"c1","text":"r","type":"fact","source_ids":["s1"]}],"sources":[{"id":"s1","url":"r"}]}`,
		"brand_enricher": `{"claims":[{"id":"c2","text":"b","type":"fact","source_ids":["s2"]}],"sources":[{"id":"s2","url":"b"}]}`,
	}
	_, source := pipeline.FindLatestClaims(prior)
	if source != "brand_enricher" {
		t.Errorf("want source brand_enricher, got %q", source)
	}
}

func TestFindLatestClaims_FallsBackToResearch(t *testing.T) {
	prior := map[string]string{
		"research": `{"claims":[{"id":"c1","text":"r","type":"fact","source_ids":["s1"]}],"sources":[{"id":"s1","url":"r"}]}`,
	}
	_, source := pipeline.FindLatestClaims(prior)
	if source != "research" {
		t.Errorf("want source research, got %q", source)
	}
}
