package store

import "testing"

func TestContentPieceCRUD(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")
	run, _ := q.CreatePipelineRun(p.ID, "test topic")

	piece, err := q.CreateContentPiece(p.ID, run.ID, "blog", "post", "My Blog Post", 0, nil)
	if err != nil {
		t.Fatalf("create: %v", err)
	}
	if piece.Status != "pending" {
		t.Errorf("expected pending, got %s", piece.Status)
	}
	if piece.Platform != "blog" {
		t.Errorf("expected blog, got %s", piece.Platform)
	}
}

func TestTrySetGenerating(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")
	run, _ := q.CreatePipelineRun(p.ID, "test topic")
	piece, _ := q.CreateContentPiece(p.ID, run.ID, "blog", "post", "", 0, nil)

	ok, _ := q.TrySetGenerating(piece.ID)
	if !ok {
		t.Error("expected first TrySetGenerating to succeed")
	}

	ok2, _ := q.TrySetGenerating(piece.ID)
	if ok2 {
		t.Error("expected second TrySetGenerating to fail (already generating)")
	}
}

func TestListContentByPipelineRunOrder(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")
	run, _ := q.CreatePipelineRun(p.ID, "test topic")

	q.CreateContentPiece(p.ID, run.ID, "blog", "post", "Cornerstone", 0, nil)
	q.CreateContentPiece(p.ID, run.ID, "linkedin", "post", "LinkedIn", 2, nil)
	q.CreateContentPiece(p.ID, run.ID, "instagram", "post", "Insta", 1, nil)

	pieces, _ := q.ListContentByPipelineRun(run.ID)
	if len(pieces) != 3 {
		t.Fatalf("expected 3, got %d", len(pieces))
	}
	if pieces[0].Title != "Cornerstone" {
		t.Errorf("expected Cornerstone first, got %s", pieces[0].Title)
	}
	if pieces[1].Title != "Insta" {
		t.Errorf("expected Insta second, got %s", pieces[1].Title)
	}
}

func TestAllPiecesApproved(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")
	run, _ := q.CreatePipelineRun(p.ID, "test topic")

	piece, _ := q.CreateContentPiece(p.ID, run.ID, "blog", "post", "", 0, nil)

	done, _ := q.AllPiecesApproved(run.ID)
	if done {
		t.Error("should not be done with pending pieces")
	}

	q.SetContentPieceStatus(piece.ID, "approved")
	done, _ = q.AllPiecesApproved(run.ID)
	if !done {
		t.Error("should be done when all approved")
	}
}
