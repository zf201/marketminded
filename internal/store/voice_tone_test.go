package store

import (
	"strings"
	"testing"
)

func TestUpsertVoiceToneProfile(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	vt := VoiceToneProfile{
		VoiceAnalysis:    "Conversational and direct.",
		ContentTypes:     "Educational, how-to guides.",
		ShouldAvoid:      "Jargon, em dashes.",
		ShouldUse:        "Short sentences, active voice.",
		StyleInspiration: "Punchy, newsletter-style writing.",
	}

	err := q.UpsertVoiceToneProfile(p.ID, vt)
	if err != nil {
		t.Fatalf("upsert: %v", err)
	}

	got, err := q.GetVoiceToneProfile(p.ID)
	if err != nil {
		t.Fatalf("get: %v", err)
	}
	if got.VoiceAnalysis != "Conversational and direct." {
		t.Errorf("unexpected voice_analysis: %s", got.VoiceAnalysis)
	}

	vt.VoiceAnalysis = "Formal and authoritative."
	q.UpsertVoiceToneProfile(p.ID, vt)
	got, _ = q.GetVoiceToneProfile(p.ID)
	if got.VoiceAnalysis != "Formal and authoritative." {
		t.Errorf("expected updated value, got: %s", got.VoiceAnalysis)
	}
}

func TestGetVoiceToneProfile_NotFound(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	_, err := q.GetVoiceToneProfile(p.ID)
	if err == nil {
		t.Fatal("expected error for non-existent profile")
	}
}

func TestBuildVoiceToneString(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	q.UpsertVoiceToneProfile(p.ID, VoiceToneProfile{
		VoiceAnalysis:    "Direct and warm.",
		ContentTypes:     "Educational.",
		ShouldAvoid:      "Buzzwords.",
		ShouldUse:        "Simple words.",
		StyleInspiration: "Newsletter style.",
	})

	s, err := q.BuildVoiceToneString(p.ID)
	if err != nil {
		t.Fatalf("build: %v", err)
	}
	if !strings.Contains(s, "Direct and warm.") {
		t.Errorf("expected voice analysis in string")
	}
	if !strings.Contains(s, "Buzzwords.") {
		t.Errorf("expected should_avoid in string")
	}
}

func TestBuildVoiceToneString_Empty(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	s, err := q.BuildVoiceToneString(p.ID)
	if err != nil {
		t.Fatalf("build: %v", err)
	}
	if s != "" {
		t.Errorf("expected empty string, got: %s", s)
	}
}
