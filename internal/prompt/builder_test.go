package prompt_test

import (
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/zanfridau/marketminded/internal/prompt"
)

func TestBuilder_LoadPrompts(t *testing.T) {
	dir := t.TempDir()
	typesDir := filepath.Join(dir, "types")
	os.MkdirAll(typesDir, 0o755)
	os.WriteFile(filepath.Join(typesDir, "blog_post.md"), []byte("Write a blog post."), 0o644)

	b, err := prompt.NewBuilder(dir)
	if err != nil {
		t.Fatal(err)
	}

	p := b.ContentPrompt("blog_post")
	if p != "Write a blog post." {
		t.Errorf("expected prompt content, got: %q", p)
	}
}

func TestBuilder_ContentPrompt_Missing(t *testing.T) {
	dir := t.TempDir()
	os.MkdirAll(filepath.Join(dir, "types"), 0o755)

	b, _ := prompt.NewBuilder(dir)
	p := b.ContentPrompt("nonexistent")
	if p != "" {
		t.Errorf("expected empty string for missing prompt, got: %q", p)
	}
}

func TestBuilder_AntiAIRules(t *testing.T) {
	dir := t.TempDir()
	os.MkdirAll(filepath.Join(dir, "types"), 0o755)
	b, _ := prompt.NewBuilder(dir)

	rules := b.AntiAIRules()
	if rules == "" {
		t.Error("expected non-empty anti-AI rules")
	}
}

func TestBuilder_ForClaimVerifier(t *testing.T) {
	dir := t.TempDir()
	os.MkdirAll(filepath.Join(dir, "types"), 0o755)
	b, _ := prompt.NewBuilder(dir)

	out := b.ForClaimVerifier(`{"claims":[],"sources":[]}`)
	if out == "" {
		t.Error("expected non-empty claim_verifier prompt")
	}
	if !strings.Contains(out, "submit_claim_verification") {
		t.Errorf("expected prompt to mention submit_claim_verification, got:\n%s", out)
	}
}

func TestForAudiencePicker_IncludesRequiredSections(t *testing.T) {
	dir := t.TempDir()
	os.MkdirAll(filepath.Join(dir, "types"), 0o755)
	b, _ := prompt.NewBuilder(dir)

	personas := `1. [id=5] Professional chef
   description: a working chef who cooks every day
   pain_points: dull knives slow down the line
   push: wants tools that hold an edge
2. [id=7] Home cook
   description: cooks on weekends
   pain_points: wants something that looks nice`
	out := b.ForAudiencePicker(
		"chef knives under $100",
		"roundup of mid-tier chef knives",
		"Acme Cutlery — sells knives in three tiers",
		`{"claims":[],"sources":[]}`,
		personas,
	)
	checks := []string{
		"Today's date:",
		"audience strategist",
		"chef knives under $100",
		"roundup of mid-tier chef knives",
		"Acme Cutlery",
		"Professional chef",
		"Home cook",
		"do not recommend",
		"submit_audience_selection",
	}
	for _, want := range checks {
		if !strings.Contains(out, want) {
			t.Errorf("ForAudiencePicker output missing %q", want)
		}
	}
}

func TestForStyleReference_IncludesRequiredSections(t *testing.T) {
	dir := t.TempDir()
	os.MkdirAll(filepath.Join(dir, "types"), 0o755)
	b, _ := prompt.NewBuilder(dir)

	out := b.ForStyleReference("https://brand.example/blog", "chef knives under $100")
	checks := []string{
		"Today's date:",
		"style scout",
		"https://brand.example/blog",
		"chef knives under $100",
		"Do NOT include",
		"server-side",
		"submit_style_reference",
	}
	for _, want := range checks {
		if !strings.Contains(out, want) {
			t.Errorf("ForStyleReference output missing %q", want)
		}
	}
}

func TestForBrandEnricher_NoAudienceBlock(t *testing.T) {
	b, err := prompt.NewBuilder(t.TempDir())
	if err != nil {
		t.Fatalf("NewBuilder: %v", err)
	}
	out := b.ForBrandEnricher("profile", "research output", "urls", "")
	if strings.Contains(out, "## Audience target") {
		t.Error("empty audienceBlock should not produce audience section")
	}
}

func TestForBrandEnricher_WithAudienceBlock(t *testing.T) {
	b, err := prompt.NewBuilder(t.TempDir())
	if err != nil {
		t.Fatalf("NewBuilder: %v", err)
	}
	audience := "\n## Audience target\nPersona: Professional chef\nWriter guidance: do not recommend the cheapest knife\n"
	out := b.ForBrandEnricher("profile", "research output", "urls", audience)
	if !strings.Contains(out, "## Audience target") {
		t.Error("expected audience section in output")
	}
	if !strings.Contains(out, "Professional chef") {
		t.Error("expected persona label in output")
	}
	if !strings.Contains(out, "do not recommend the cheapest knife") {
		t.Error("expected writer guidance in output")
	}
	if !strings.Contains(out, "prefer products, plans, and SKUs appropriate for this audience") {
		t.Error("expected audience instruction line in output")
	}
}

func TestForEditor_NoAudienceBlock(t *testing.T) {
	b, err := prompt.NewBuilder(t.TempDir())
	if err != nil {
		t.Fatalf("NewBuilder: %v", err)
	}
	out := b.ForEditor("profile", "brief", "claims", "framework", "", "")
	if strings.Contains(out, "## Audience target") {
		t.Error("empty audienceBlock should not produce audience section")
	}
}

func TestForEditor_WithAudienceBlock(t *testing.T) {
	b, err := prompt.NewBuilder(t.TempDir())
	if err != nil {
		t.Fatalf("NewBuilder: %v", err)
	}
	audience := "\n## Audience target\nPersona: Professional chef\nWriter guidance: do not recommend the cheapest knife\n"
	out := b.ForEditor("profile", "brief", "claims", "framework", audience, "")
	if !strings.Contains(out, "Professional chef") {
		t.Error("expected persona label")
	}
	if !strings.Contains(out, "The angle and claim selection must serve this audience") {
		t.Error("expected audience instruction")
	}
}

func TestForEditor_MinClaimRule_Present(t *testing.T) {
	b, err := prompt.NewBuilder(t.TempDir())
	if err != nil {
		t.Fatalf("NewBuilder: %v", err)
	}
	out := b.ForEditor("profile", "brief", "claims", "framework", "", "")
	if !strings.Contains(out, "Every section must include at least one claim_id") {
		t.Error("min-claim rule missing from editor prompt")
	}
}

func TestForEditor_RetryFeedback(t *testing.T) {
	b, err := prompt.NewBuilder(t.TempDir())
	if err != nil {
		t.Fatalf("NewBuilder: %v", err)
	}
	out := b.ForEditor("profile", "brief", "claims", "framework", "", "section[5] has empty claim_ids")
	if !strings.Contains(out, "Previous attempt rejected") {
		t.Error("retry feedback section missing")
	}
	if !strings.Contains(out, "section[5] has empty claim_ids") {
		t.Error("retry feedback text not embedded")
	}
}

func TestForWriter_NoNewBlocks(t *testing.T) {
	b, err := prompt.NewBuilder(t.TempDir())
	if err != nil {
		t.Fatalf("NewBuilder: %v", err)
	}
	out := b.ForWriter("blog_post", "profile", "outline", "claims", "", "", "")
	if strings.Contains(out, "## Audience target") {
		t.Error("empty audienceBlock should not produce audience section")
	}
	if strings.Contains(out, "## Style reference") {
		t.Error("empty styleReferenceBlock should not produce style section")
	}
}

func TestForWriter_WithAudienceBlock(t *testing.T) {
	b, err := prompt.NewBuilder(t.TempDir())
	if err != nil {
		t.Fatalf("NewBuilder: %v", err)
	}
	audience := "\n## Audience target\nPersona: Professional chef\nWriter guidance: do not recommend the cheapest knife\n"
	out := b.ForWriter("blog_post", "profile", "outline", "claims", "", audience, "")
	if !strings.Contains(out, "Professional chef") {
		t.Error("expected persona label")
	}
	if !strings.Contains(out, "Honor the writer guidance literally") {
		t.Error("expected writer audience instruction")
	}
}

func TestForWriter_WithStyleReferenceBlock(t *testing.T) {
	b, err := prompt.NewBuilder(t.TempDir())
	if err != nil {
		t.Fatalf("NewBuilder: %v", err)
	}
	style := "\n## Style reference — match this voice\nDo NOT copy sentences, facts, or structure from these examples.\n\n### Example 1: Foo\nbody body body\n"
	out := b.ForWriter("blog_post", "profile", "outline", "claims", "", "", style)
	if !strings.Contains(out, "## Style reference") {
		t.Error("expected style reference section")
	}
	if !strings.Contains(out, "Do NOT copy sentences, facts, or structure") {
		t.Error("expected do-not-copy rule")
	}
}
