package store

import (
	"strings"
	"testing"
)

func TestCreateAudiencePersona(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	persona, err := q.CreateAudiencePersona(p.ID, AudiencePersona{
		Label: "Startup CTO", Description: "Technical leader at an early-stage SaaS company.",
		PainPoints: "Can't find engineers.", Push: "Frustrated with current process.",
		Pull: "Wants automation.", Anxiety: "Worried about cost.", Habit: "Using spreadsheets.",
		Role: "CTO", SortOrder: 0,
	})
	if err != nil {
		t.Fatalf("create: %v", err)
	}
	if persona.ID == 0 {
		t.Fatal("expected non-zero ID")
	}
	if persona.Label != "Startup CTO" {
		t.Errorf("expected 'Startup CTO', got %q", persona.Label)
	}
}

func TestListAudiencePersonas(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	q.CreateAudiencePersona(p.ID, AudiencePersona{Label: "CTO", Description: "d", PainPoints: "p", Push: "pu", Pull: "pl", Anxiety: "a", Habit: "h", SortOrder: 1})
	q.CreateAudiencePersona(p.ID, AudiencePersona{Label: "Developer", Description: "d", PainPoints: "p", Push: "pu", Pull: "pl", Anxiety: "a", Habit: "h", SortOrder: 0})

	personas, err := q.ListAudiencePersonas(p.ID)
	if err != nil {
		t.Fatalf("list: %v", err)
	}
	if len(personas) != 2 {
		t.Fatalf("expected 2, got %d", len(personas))
	}
	if personas[0].Label != "Developer" {
		t.Errorf("expected Developer first (sort_order 0), got %q", personas[0].Label)
	}
}

func TestUpdateAudiencePersona(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	persona, _ := q.CreateAudiencePersona(p.ID, AudiencePersona{Label: "CTO", Description: "d", PainPoints: "p", Push: "pu", Pull: "pl", Anxiety: "a", Habit: "h"})

	err := q.UpdateAudiencePersona(persona.ID, AudiencePersona{Label: "VP Engineering", Description: "updated", PainPoints: "p", Push: "pu", Pull: "pl", Anxiety: "a", Habit: "h"})
	if err != nil {
		t.Fatalf("update: %v", err)
	}

	updated, _ := q.GetAudiencePersona(persona.ID)
	if updated.Label != "VP Engineering" {
		t.Errorf("expected 'VP Engineering', got %q", updated.Label)
	}
}

func TestDeleteAudiencePersona(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	persona, _ := q.CreateAudiencePersona(p.ID, AudiencePersona{Label: "CTO", Description: "d", PainPoints: "p", Push: "pu", Pull: "pl", Anxiety: "a", Habit: "h"})

	err := q.DeleteAudiencePersona(persona.ID)
	if err != nil {
		t.Fatalf("delete: %v", err)
	}

	personas, _ := q.ListAudiencePersonas(p.ID)
	if len(personas) != 0 {
		t.Errorf("expected 0 after delete, got %d", len(personas))
	}
}

func TestBuildAudienceString(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	q.CreateAudiencePersona(p.ID, AudiencePersona{
		Label: "Startup CTO", Description: "Technical leader.", PainPoints: "Hiring.",
		Push: "Frustrated.", Pull: "Wants speed.", Anxiety: "Cost.", Habit: "Spreadsheets.",
		Role: "CTO", SortOrder: 0,
	})

	s, err := q.BuildAudienceString(p.ID)
	if err != nil {
		t.Fatalf("build: %v", err)
	}
	if s == "" {
		t.Fatal("expected non-empty audience string")
	}
	if !strings.Contains(s, "Startup CTO") || !strings.Contains(s, "Hiring") || !strings.Contains(s, "CTO") {
		t.Errorf("expected persona content in string, got: %s", s)
	}
}
