package store

import "testing"

func TestContentPieceLifecycle(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	piece, err := q.CreateContentPiece(p.ID, nil, "blog", "My Post", "Post body here", nil)
	if err != nil {
		t.Fatalf("create: %v", err)
	}
	if piece.Status != "draft" {
		t.Errorf("expected draft, got %s", piece.Status)
	}

	err = q.ApproveContentPiece(piece.ID)
	if err != nil {
		t.Fatalf("approve: %v", err)
	}

	got, _ := q.GetContentPiece(piece.ID)
	if got.Status != "approved" {
		t.Errorf("expected approved, got %s", got.Status)
	}
}

func TestContentLogSummaries(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	q.CreateContentPiece(p.ID, nil, "blog", "Draft Post", "not approved", nil)
	piece, _ := q.CreateContentPiece(p.ID, nil, "blog", "Good Post", "approved body", nil)
	q.ApproveContentPiece(piece.ID)

	summaries, err := q.ContentLogSummaries(p.ID, 10)
	if err != nil {
		t.Fatalf("summaries: %v", err)
	}
	if len(summaries) != 1 {
		t.Errorf("expected 1 approved, got %d", len(summaries))
	}
}
