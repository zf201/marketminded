package store

import (
	"fmt"
	"strings"
	"testing"
)

func TestProfileSectionUpsert(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	err := q.UpsertProfileSection(p.ID, "product_and_positioning", "We build web apps for startups")
	if err != nil {
		t.Fatalf("upsert: %v", err)
	}

	section, err := q.GetProfileSection(p.ID, "product_and_positioning")
	if err != nil {
		t.Fatalf("get: %v", err)
	}
	if section.Content != "We build web apps for startups" {
		t.Errorf("unexpected content: %s", section.Content)
	}

	// Update existing
	q.UpsertProfileSection(p.ID, "product_and_positioning", "We build scalable Go apps")
	section, _ = q.GetProfileSection(p.ID, "product_and_positioning")
	if section.Content != "We build scalable Go apps" {
		t.Errorf("expected updated content, got: %s", section.Content)
	}
}

func TestListProfileSections(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	q.UpsertProfileSection(p.ID, "product_and_positioning", "Web dev agency")
	q.UpsertProfileSection(p.ID, "audience", "CTOs at startups")

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

	q.UpsertProfileSection(p.ID, "voice_and_tone", "Bold voice, casual tone")
	q.UpsertProfileSection(p.ID, "audience", "") // empty, should be skipped

	profile, _ := q.BuildProfileString(p.ID)
	if !strings.Contains(profile, "Bold voice") {
		t.Errorf("expected voice content in profile string")
	}
	if strings.Contains(profile, "Audience") {
		t.Errorf("empty audience should be skipped")
	}
}

func TestSaveProfileVersion(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	err := q.SaveProfileVersion(p.ID, "product_and_positioning", "Version 1 content")
	if err != nil {
		t.Fatalf("save version: %v", err)
	}

	versions, err := q.ListProfileVersions(p.ID, "product_and_positioning")
	if err != nil {
		t.Fatalf("list: %v", err)
	}
	if len(versions) != 1 {
		t.Fatalf("expected 1, got %d", len(versions))
	}
	if versions[0].Content != "Version 1 content" {
		t.Errorf("unexpected content: %s", versions[0].Content)
	}
}

func TestProfileVersionCappedAt5(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	for i := 1; i <= 7; i++ {
		q.SaveProfileVersion(p.ID, "audience", fmt.Sprintf("Version %d", i))
	}

	versions, _ := q.ListProfileVersions(p.ID, "audience")
	if len(versions) != 5 {
		t.Fatalf("expected 5 (capped), got %d", len(versions))
	}
	if versions[0].Content != "Version 7" {
		t.Errorf("expected newest first, got: %s", versions[0].Content)
	}
	if versions[4].Content != "Version 3" {
		t.Errorf("expected oldest kept to be Version 3, got: %s", versions[4].Content)
	}
}
