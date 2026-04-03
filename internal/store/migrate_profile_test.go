package store

import (
	"encoding/json"
	"testing"
)

func TestMigrateSettingsToSourceURLs(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	q.SetProjectSetting(p.ID, "company_website", "https://example.com, https://example.com/about")
	q.SetProjectSetting(p.ID, "website_notes", "Use for value prop")
	q.SetProjectSetting(p.ID, "company_pricing", "https://example.com/pricing")
	q.SetProjectSetting(p.ID, "pricing_notes", "Reference tiers")

	err := q.MigrateSettingsToSourceURLs()
	if err != nil {
		t.Fatalf("migrate: %v", err)
	}

	section, err := q.GetProfileSection(p.ID, "product_and_positioning")
	if err != nil {
		t.Fatalf("get section: %v", err)
	}

	var urls []SourceURL
	if err := json.Unmarshal([]byte(section.SourceURLs), &urls); err != nil {
		t.Fatalf("unmarshal: %v", err)
	}

	if len(urls) != 3 {
		t.Fatalf("expected 3 URLs, got %d", len(urls))
	}
	if urls[0].URL != "https://example.com" || urls[0].Notes != "Use for value prop" {
		t.Errorf("unexpected first URL: %+v", urls[0])
	}
	if urls[2].URL != "https://example.com/pricing" || urls[2].Notes != "Reference tiers" {
		t.Errorf("unexpected pricing URL: %+v", urls[2])
	}

	settings, _ := q.AllProjectSettings(p.ID)
	if _, ok := settings["company_website"]; ok {
		t.Error("company_website setting should have been deleted")
	}
	if _, ok := settings["company_pricing"]; ok {
		t.Error("company_pricing setting should have been deleted")
	}
}

func TestMigrateSettingsToSourceURLs_NoSettings(t *testing.T) {
	q := testDB(t)

	err := q.MigrateSettingsToSourceURLs()
	if err != nil {
		t.Fatalf("migrate: %v", err)
	}
}
