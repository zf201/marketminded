package pipeline_test

import (
	"strings"
	"testing"

	"github.com/zanfridau/marketminded/internal/pipeline"
)

func TestParseAudienceSelection_Persona(t *testing.T) {
	raw := `{
		"mode": "persona",
		"persona_id": 7,
		"persona_label": "Professional chef",
		"persona_summary": "A working chef who buys knives for the job.",
		"reasoning": "Topic is about mid-tier chef knives.",
		"guidance_for_writer": "Recommend mid-tier, avoid the cheapest SKU."
	}`
	sel, err := pipeline.ParseAudienceSelection(raw)
	if err != nil {
		t.Fatalf("parse: %v", err)
	}
	if sel.Mode != "persona" {
		t.Errorf("mode: want persona, got %q", sel.Mode)
	}
	if sel.PersonaID == nil || *sel.PersonaID != 7 {
		t.Errorf("persona_id: want 7, got %v", sel.PersonaID)
	}
	if sel.PersonaLabel != "Professional chef" {
		t.Errorf("persona_label mismatch: %q", sel.PersonaLabel)
	}
}

func TestParseAudienceSelection_Educational(t *testing.T) {
	raw := `{
		"mode": "educational",
		"persona_id": null,
		"reasoning": "Topic is a how-X-works piece.",
		"guidance_for_writer": "Speak to a curious learner, no sales tone."
	}`
	sel, err := pipeline.ParseAudienceSelection(raw)
	if err != nil {
		t.Fatalf("parse: %v", err)
	}
	if sel.Mode != "educational" {
		t.Errorf("mode: %q", sel.Mode)
	}
	if sel.PersonaID != nil {
		t.Errorf("expected nil persona_id, got %v", *sel.PersonaID)
	}
}

func TestParseAudienceSelection_Commentary(t *testing.T) {
	raw := `{
		"mode": "commentary",
		"persona_id": null,
		"reasoning": "Industry reaction piece.",
		"guidance_for_writer": "Informed reader, commentary register."
	}`
	sel, err := pipeline.ParseAudienceSelection(raw)
	if err != nil {
		t.Fatalf("parse: %v", err)
	}
	if sel.Mode != "commentary" {
		t.Errorf("mode: %q", sel.Mode)
	}
}

func TestParseAudienceSelection_MissingMode(t *testing.T) {
	_, err := pipeline.ParseAudienceSelection(`{"reasoning":"x","guidance_for_writer":"y"}`)
	if err == nil {
		t.Fatal("expected error for missing mode")
	}
}

func TestParseAudienceSelection_InvalidMode(t *testing.T) {
	_, err := pipeline.ParseAudienceSelection(`{"mode":"marketing","reasoning":"x","guidance_for_writer":"y"}`)
	if err == nil {
		t.Fatal("expected error for invalid mode")
	}
}

func TestParseAudienceSelection_PersonaMissingID(t *testing.T) {
	_, err := pipeline.ParseAudienceSelection(`{"mode":"persona","persona_id":null,"reasoning":"x","guidance_for_writer":"y"}`)
	if err == nil {
		t.Fatal("expected error: mode=persona requires persona_id")
	}
}

func TestParseAudienceSelection_EducationalWithPersonaID(t *testing.T) {
	_, err := pipeline.ParseAudienceSelection(`{"mode":"educational","persona_id":3,"reasoning":"x","guidance_for_writer":"y"}`)
	if err == nil {
		t.Fatal("expected error: off-mode must not have persona_id")
	}
}

func TestParseAudienceSelection_EmptyGuidance(t *testing.T) {
	_, err := pipeline.ParseAudienceSelection(`{"mode":"educational","persona_id":null,"reasoning":"x","guidance_for_writer":""}`)
	if err == nil {
		t.Fatal("expected error for empty guidance_for_writer")
	}
}

func TestFormatAudienceBlock_Nil(t *testing.T) {
	if got := pipeline.FormatAudienceBlock(nil); got != "" {
		t.Errorf("nil selection should format to empty, got %q", got)
	}
}

func TestFormatAudienceBlock_Persona(t *testing.T) {
	pid := int64(7)
	sel := &pipeline.AudienceSelection{
		Mode:              "persona",
		PersonaID:         &pid,
		PersonaLabel:      "Professional chef",
		PersonaSummary:    "A working chef who buys knives for the job.",
		Reasoning:         "Topic is about mid-tier chef knives.",
		GuidanceForWriter: "Recommend mid-tier, avoid the cheapest SKU.",
	}
	got := pipeline.FormatAudienceBlock(sel)
	if !strings.Contains(got, "## Audience target") {
		t.Errorf("missing header")
	}
	if !strings.Contains(got, "Professional chef") {
		t.Errorf("missing label")
	}
	if !strings.Contains(got, "A working chef who buys knives for the job.") {
		t.Errorf("missing summary")
	}
	if !strings.Contains(got, "Recommend mid-tier, avoid the cheapest SKU.") {
		t.Errorf("missing guidance")
	}
}

func TestFormatAudienceBlock_Educational(t *testing.T) {
	sel := &pipeline.AudienceSelection{
		Mode:              "educational",
		Reasoning:         "How-it-works piece.",
		GuidanceForWriter: "Speak to a curious learner.",
	}
	got := pipeline.FormatAudienceBlock(sel)
	if !strings.Contains(got, "## Audience target") {
		t.Errorf("missing header")
	}
	if !strings.Contains(got, "educational") {
		t.Errorf("missing mode label")
	}
	if strings.Contains(got, "Persona:") {
		t.Errorf("should not render persona field in off-mode")
	}
	if !strings.Contains(got, "Speak to a curious learner.") {
		t.Errorf("missing guidance")
	}
}

func TestFormatAudienceBlock_Commentary(t *testing.T) {
	sel := &pipeline.AudienceSelection{
		Mode:              "commentary",
		Reasoning:         "Industry reaction piece.",
		GuidanceForWriter: "Informed reader, commentary register.",
	}
	got := pipeline.FormatAudienceBlock(sel)
	if !strings.Contains(got, "## Audience target") {
		t.Errorf("missing header")
	}
	if !strings.Contains(got, "commentary") {
		t.Errorf("missing mode label")
	}
	if strings.Contains(got, "Persona:") {
		t.Errorf("should not render persona field in off-mode")
	}
	if !strings.Contains(got, "Informed reader, commentary register.") {
		t.Errorf("missing guidance")
	}
}
