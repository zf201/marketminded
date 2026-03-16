package store

import (
	"strings"
	"testing"
)

func TestProfileSectionUpsert(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	err := q.UpsertProfileSection(p.ID, "voice", "Bold and confident voice")
	if err != nil {
		t.Fatalf("upsert: %v", err)
	}

	section, err := q.GetProfileSection(p.ID, "voice")
	if err != nil {
		t.Fatalf("get: %v", err)
	}
	if section.Content != "Bold and confident voice" {
		t.Errorf("unexpected content: %s", section.Content)
	}

	// Update existing
	q.UpsertProfileSection(p.ID, "voice", "Confident and irreverent")
	section, _ = q.GetProfileSection(p.ID, "voice")
	if section.Content != "Confident and irreverent" {
		t.Errorf("expected updated content, got: %s", section.Content)
	}
}

func TestListProfileSections(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	q.UpsertProfileSection(p.ID, "voice", "Bold voice")
	q.UpsertProfileSection(p.ID, "tone", "Casual tone")

	sections, err := q.ListProfileSections(p.ID)
	if err != nil {
		t.Fatalf("list: %v", err)
	}
	if len(sections) != 2 {
		t.Errorf("expected 2, got %d", len(sections))
	}
}

func TestBuildProfileString(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	q.UpsertProfileSection(p.ID, "voice", "Bold voice")
	q.UpsertProfileSection(p.ID, "tone", "") // empty, should be skipped

	profile, _ := q.BuildProfileString(p.ID)
	if !strings.Contains(profile, "Bold voice") {
		t.Errorf("expected voice content in profile string")
	}
	if strings.Contains(profile, "Tone") {
		t.Errorf("empty tone should be skipped")
	}
}
