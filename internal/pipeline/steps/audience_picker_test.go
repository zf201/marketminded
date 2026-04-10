package steps

import (
	"strings"
	"testing"

	"github.com/zanfridau/marketminded/internal/store"
)

func TestHydrateAudienceSelection_Persona(t *testing.T) {
	personas := []store.AudiencePersona{
		{ID: 5, Label: "Professional chef", Description: "Works the line daily", PainPoints: "dull knives slow the line", Push: "wants edge retention", Pull: "premium steel", Anxiety: "cost", Habit: "reuses old tools"},
		{ID: 7, Label: "Home cook", Description: "weekend cooking"},
	}
	raw := `{"mode":"persona","persona_id":5,"reasoning":"mid-tier fits the pros","guidance_for_writer":"do not recommend the cheapest knife to pros"}`
	out, err := hydrateAudienceSelection(raw, personas)
	if err != nil {
		t.Fatalf("hydrate: %v", err)
	}
	if !strings.Contains(out, `"persona_label":"Professional chef"`) {
		t.Errorf("expected persona_label in output, got %s", out)
	}
	if !strings.Contains(out, "Works the line daily") {
		t.Errorf("expected persona_summary in output, got %s", out)
	}
}

func TestHydrateAudienceSelection_PersonaIDNotFound(t *testing.T) {
	personas := []store.AudiencePersona{{ID: 5, Label: "Professional chef"}}
	raw := `{"mode":"persona","persona_id":99,"reasoning":"x","guidance_for_writer":"y"}`
	if _, err := hydrateAudienceSelection(raw, personas); err == nil {
		t.Fatal("expected error: persona_id 99 not in list")
	}
}

func TestHydrateAudienceSelection_Educational(t *testing.T) {
	personas := []store.AudiencePersona{{ID: 5, Label: "Professional chef"}}
	raw := `{"mode":"educational","persona_id":null,"reasoning":"how-it-works","guidance_for_writer":"teach the topic"}`
	out, err := hydrateAudienceSelection(raw, personas)
	if err != nil {
		t.Fatalf("hydrate: %v", err)
	}
	if strings.Contains(out, "persona_label") {
		t.Errorf("off-mode should not hydrate persona_label, got %s", out)
	}
	if !strings.Contains(out, `"mode":"educational"`) {
		t.Errorf("mode not preserved, got %s", out)
	}
}

func TestFormatPersonasBlock(t *testing.T) {
	personas := []store.AudiencePersona{
		{ID: 5, Label: "Chef", Description: "works the line", PainPoints: "dull knives", Push: "edge retention", Pull: "premium steel", Anxiety: "cost", Habit: "reuses tools"},
		{ID: 7, Label: "Home cook", Description: "cooks weekends", PainPoints: "aesthetics", Push: "gift shopping", Pull: "looks nice", Anxiety: "complexity", Habit: "uses one knife"},
	}
	got := formatPersonasBlock(personas)
	if !strings.Contains(got, "[id=5] Chef") {
		t.Errorf("missing chef id tag, got %s", got)
	}
	if !strings.Contains(got, "[id=7] Home cook") {
		t.Errorf("missing home cook id tag, got %s", got)
	}
	if !strings.Contains(got, "dull knives") {
		t.Errorf("missing pain points, got %s", got)
	}
}
