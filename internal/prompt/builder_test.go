package prompt_test

import (
	"os"
	"path/filepath"
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
