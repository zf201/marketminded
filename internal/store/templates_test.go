package store

import "testing"

func TestTemplateCRUD(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	tmpl, err := q.CreateTemplate(p.ID, "Insta Post", "instagram", "<div>{{.Title}}</div><p>{{.Body}}</p>")
	if err != nil {
		t.Fatalf("create: %v", err)
	}
	if tmpl.Platform != "instagram" {
		t.Errorf("expected instagram, got %s", tmpl.Platform)
	}

	list, _ := q.ListTemplates(p.ID)
	if len(list) != 1 {
		t.Errorf("expected 1, got %d", len(list))
	}
}

func TestTemplateValidation(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	// Missing required slots
	_, err := q.CreateTemplate(p.ID, "Bad", "instagram", "<div>no slots</div>")
	if err == nil {
		t.Fatal("expected validation error")
	}
}

func TestListTemplatesByPlatform(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	q.CreateTemplate(p.ID, "Insta", "instagram", "<div>{{.Title}}</div><p>{{.Body}}</p>")
	q.CreateTemplate(p.ID, "LinkedIn", "linkedin", "<div>{{.Title}}</div><p>{{.Body}}</p>")

	insta, _ := q.ListTemplatesByPlatform(p.ID, "instagram")
	if len(insta) != 1 {
		t.Errorf("expected 1 instagram template, got %d", len(insta))
	}
}
