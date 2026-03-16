package store

import "testing"

func TestCreateAndListKnowledge(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	item, err := q.CreateKnowledgeItem(p.ID, "voice_sample", "Sample 1", "This is how we talk.", "")
	if err != nil {
		t.Fatalf("create: %v", err)
	}
	if item.ID == 0 {
		t.Fatal("expected non-zero ID")
	}

	items, err := q.ListKnowledgeItems(p.ID, "")
	if err != nil {
		t.Fatalf("list: %v", err)
	}
	if len(items) != 1 {
		t.Errorf("expected 1 item, got %d", len(items))
	}
}

func TestListKnowledgeByType(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	q.CreateKnowledgeItem(p.ID, "voice_sample", "V1", "voice content", "")
	q.CreateKnowledgeItem(p.ID, "brand_doc", "B1", "brand content", "")

	items, err := q.ListKnowledgeItems(p.ID, "voice_sample")
	if err != nil {
		t.Fatalf("list: %v", err)
	}
	if len(items) != 1 {
		t.Errorf("expected 1, got %d", len(items))
	}
}
