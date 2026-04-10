package pipeline_test

import (
	"context"
	"fmt"
	"strings"
	"testing"

	"github.com/zanfridau/marketminded/internal/pipeline"
)

func bodyOfLen(n int) string {
	b := strings.Builder{}
	for b.Len() < n {
		b.WriteString("lorem ipsum dolor sit amet, consectetur adipiscing elit. ")
	}
	return b.String()[:n]
}

func TestParseStyleReference_TwoExamples(t *testing.T) {
	raw := `{
		"examples": [
			{"url":"https://brand.example/a","title":"A","why_chosen":"sharp voice"},
			{"url":"https://brand.example/b","title":"B","why_chosen":"tight structure"}
		],
		"reasoning": "Two best-written posts on the blog."
	}`
	ref, err := pipeline.ParseStyleReference(raw)
	if err != nil {
		t.Fatalf("parse: %v", err)
	}
	if len(ref.Examples) != 2 {
		t.Errorf("want 2 examples, got %d", len(ref.Examples))
	}
}

func TestParseStyleReference_ThreeExamples(t *testing.T) {
	raw := `{
		"examples": [
			{"url":"https://brand.example/a","title":"A","why_chosen":"a"},
			{"url":"https://brand.example/b","title":"B","why_chosen":"b"},
			{"url":"https://brand.example/c","title":"C","why_chosen":"c"}
		],
		"reasoning": "top three"
	}`
	ref, err := pipeline.ParseStyleReference(raw)
	if err != nil {
		t.Fatalf("parse: %v", err)
	}
	if len(ref.Examples) != 3 {
		t.Errorf("want 3 examples, got %d", len(ref.Examples))
	}
}

func TestParseStyleReference_RejectsOne(t *testing.T) {
	raw := `{"examples":[{"url":"u","title":"t","why_chosen":"w"}],"reasoning":"x"}`
	if _, err := pipeline.ParseStyleReference(raw); err == nil {
		t.Fatal("expected error: only 1 example")
	}
}

func TestParseStyleReference_RejectsFour(t *testing.T) {
	raw := `{
		"examples": [
			{"url":"a","title":"A","why_chosen":"a"},
			{"url":"b","title":"B","why_chosen":"b"},
			{"url":"c","title":"C","why_chosen":"c"},
			{"url":"d","title":"D","why_chosen":"d"}
		],
		"reasoning": "too many"
	}`
	if _, err := pipeline.ParseStyleReference(raw); err == nil {
		t.Fatal("expected error: 4 examples")
	}
}

func TestParseStyleReference_MissingField(t *testing.T) {
	raw := `{
		"examples": [
			{"url":"a","why_chosen":"a"},
			{"url":"b","title":"B","why_chosen":"b"}
		],
		"reasoning": "x"
	}`
	if _, err := pipeline.ParseStyleReference(raw); err == nil {
		t.Fatal("expected error: missing title on example[0]")
	}
}

func TestFormatStyleReferenceBlock_Nil(t *testing.T) {
	if got := pipeline.FormatStyleReferenceBlock(nil); got != "" {
		t.Errorf("nil ref should format to empty, got %q", got)
	}
}

func TestFormatStyleReferenceBlock_FullRender(t *testing.T) {
	body1 := bodyOfLen(500)
	body2 := bodyOfLen(500)
	ref := &pipeline.StyleReference{
		Examples: []pipeline.StyleReferenceExample{
			{URL: "https://brand.example/a", Title: "Alpha", Body: body1, WhyChosen: "sharp"},
			{URL: "https://brand.example/b", Title: "Beta", Body: body2, WhyChosen: "tight"},
		},
		Reasoning: "top two",
	}
	got := pipeline.FormatStyleReferenceBlock(ref)
	if !strings.Contains(got, "## Style reference") {
		t.Errorf("missing header")
	}
	if !strings.Contains(got, "Do NOT copy sentences, facts, or structure") {
		t.Errorf("missing do-not-copy instruction")
	}
	if !strings.Contains(got, "### Example 1: Alpha") {
		t.Errorf("missing example 1 header")
	}
	if !strings.Contains(got, "### Example 2: Beta") {
		t.Errorf("missing example 2 header")
	}
	if !strings.Contains(got, body1) {
		t.Errorf("body 1 not embedded verbatim")
	}
	if !strings.Contains(got, body2) {
		t.Errorf("body 2 not embedded verbatim")
	}
}

func TestParseStyleReference_Empty(t *testing.T) {
	if _, err := pipeline.ParseStyleReference(""); err == nil {
		t.Fatal("expected error for empty input")
	}
	if _, err := pipeline.ParseStyleReference("   "); err == nil {
		t.Fatal("expected error for whitespace-only input")
	}
}

func TestParseStyleReference_NoBodyLengthEnforcement(t *testing.T) {
	// Parser must NOT reject examples with short or missing bodies —
	// bodies are populated server-side after submission.
	raw := `{
		"examples": [
			{"url":"https://brand.example/a","title":"A","why_chosen":"a"},
			{"url":"https://brand.example/b","title":"B","why_chosen":"b","body":"too short"}
		],
		"reasoning": "body length not checked here"
	}`
	if _, err := pipeline.ParseStyleReference(raw); err != nil {
		t.Fatalf("parser must accept examples without bodies, got error: %v", err)
	}
}

func TestPopulateStyleReferenceBodies_HappyPath(t *testing.T) {
	ref := &pipeline.StyleReference{
		Examples: []pipeline.StyleReferenceExample{
			{URL: "https://brand.example/a", Title: "A", WhyChosen: "sharp"},
			{URL: "https://brand.example/b", Title: "B", WhyChosen: "tight"},
		},
		Reasoning: "two picks",
	}
	fakeFetcher := func(ctx context.Context, url string) (string, error) {
		return strings.Repeat("lorem ipsum dolor sit amet ", 50), nil
	}
	if err := pipeline.PopulateStyleReferenceBodies(context.Background(), ref, fakeFetcher); err != nil {
		t.Fatalf("populate: %v", err)
	}
	if len(ref.Examples) != 2 {
		t.Errorf("want 2 examples, got %d", len(ref.Examples))
	}
	for i, ex := range ref.Examples {
		if len(ex.Body) < 400 {
			t.Errorf("example[%d] body too short: %d", i, len(ex.Body))
		}
	}
}

func TestPopulateStyleReferenceBodies_DropsShortBodies(t *testing.T) {
	ref := &pipeline.StyleReference{
		Examples: []pipeline.StyleReferenceExample{
			{URL: "https://example.com/post-alpha", Title: "A", WhyChosen: "sharp"},
			{URL: "https://example.com/post-bravo", Title: "B", WhyChosen: "tight"},
			{URL: "https://example.com/post-charlie", Title: "C", WhyChosen: "ok"},
		},
		Reasoning: "three picks",
	}
	fakeFetcher := func(ctx context.Context, url string) (string, error) {
		if strings.HasSuffix(url, "/post-bravo") {
			return "too short", nil
		}
		return strings.Repeat("x", 500), nil
	}
	if err := pipeline.PopulateStyleReferenceBodies(context.Background(), ref, fakeFetcher); err != nil {
		t.Fatalf("populate: %v", err)
	}
	if len(ref.Examples) != 2 {
		t.Errorf("want 2 examples after dropping short body, got %d", len(ref.Examples))
	}
}

func TestPopulateStyleReferenceBodies_DropsFetchErrors(t *testing.T) {
	ref := &pipeline.StyleReference{
		Examples: []pipeline.StyleReferenceExample{
			{URL: "https://example.com/post-alpha", Title: "A", WhyChosen: "sharp"},
			{URL: "https://example.com/post-bravo", Title: "B", WhyChosen: "tight"},
			{URL: "https://example.com/post-charlie", Title: "C", WhyChosen: "ok"},
		},
		Reasoning: "three picks",
	}
	fakeFetcher := func(ctx context.Context, url string) (string, error) {
		if strings.HasSuffix(url, "/post-bravo") {
			return "", fmt.Errorf("simulated fetch failure")
		}
		return strings.Repeat("x", 500), nil
	}
	if err := pipeline.PopulateStyleReferenceBodies(context.Background(), ref, fakeFetcher); err != nil {
		t.Fatalf("populate: %v", err)
	}
	if len(ref.Examples) != 2 {
		t.Errorf("want 2 examples after dropping fetch error, got %d", len(ref.Examples))
	}
}

func TestPopulateStyleReferenceBodies_FailsBelowTwo(t *testing.T) {
	ref := &pipeline.StyleReference{
		Examples: []pipeline.StyleReferenceExample{
			{URL: "https://example.com/post-alpha", Title: "A", WhyChosen: "sharp"},
			{URL: "https://example.com/post-bravo", Title: "B", WhyChosen: "tight"},
		},
		Reasoning: "two picks",
	}
	fakeFetcher := func(ctx context.Context, url string) (string, error) {
		if strings.HasSuffix(url, "/post-alpha") {
			return "short", nil
		}
		return "", fmt.Errorf("nope")
	}
	if err := pipeline.PopulateStyleReferenceBodies(context.Background(), ref, fakeFetcher); err == nil {
		t.Fatal("expected error: zero examples passed")
	}
}
