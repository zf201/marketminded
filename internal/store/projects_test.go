package store

import (
	"testing"
)

func TestCreateAndGetProject(t *testing.T) {
	q := testDB(t)

	p, err := q.CreateProject("Test Client", "A test project")
	if err != nil {
		t.Fatalf("create: %v", err)
	}
	if p.ID == 0 {
		t.Fatal("expected non-zero ID")
	}
	if p.Name != "Test Client" {
		t.Errorf("expected 'Test Client', got %q", p.Name)
	}

	got, err := q.GetProject(p.ID)
	if err != nil {
		t.Fatalf("get: %v", err)
	}
	if got.Name != "Test Client" {
		t.Errorf("expected 'Test Client', got %q", got.Name)
	}
}

func TestListProjects(t *testing.T) {
	q := testDB(t)

	q.CreateProject("A", "first")
	q.CreateProject("B", "second")

	projects, err := q.ListProjects()
	if err != nil {
		t.Fatalf("list: %v", err)
	}
	if len(projects) != 2 {
		t.Errorf("expected 2 projects, got %d", len(projects))
	}
}
