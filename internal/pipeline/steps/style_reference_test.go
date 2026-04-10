package steps

import (
	"strings"
	"testing"

	"github.com/zanfridau/marketminded/internal/pipeline"
)

func TestStyleReferenceValidate_HappyPath(t *testing.T) {
	body := strings.Repeat("lorem ipsum dolor sit amet, ", 30) // well over 400 chars
	raw := `{
		"examples": [
			{"url":"https://brand.example/a","title":"A","body":"` + body + `","why_chosen":"sharp"},
			{"url":"https://brand.example/b","title":"B","body":"` + body + `","why_chosen":"tight"}
		],
		"reasoning":"top two"
	}`
	ref, err := pipeline.ParseStyleReference(raw)
	if err != nil {
		t.Fatalf("parse: %v", err)
	}
	if len(ref.Examples) != 2 {
		t.Fatalf("want 2, got %d", len(ref.Examples))
	}
}

func TestStyleReferenceValidate_AcceptsNoBody(t *testing.T) {
	// Parser no longer validates body length — bodies are populated server-side.
	raw := `{
		"examples":[
			{"url":"a","title":"A","why_chosen":"a"},
			{"url":"b","title":"B","why_chosen":"b"}
		],
		"reasoning":"x"
	}`
	if _, err := pipeline.ParseStyleReference(raw); err != nil {
		t.Fatalf("parser must accept examples without bodies: %v", err)
	}
}
