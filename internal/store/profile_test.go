package store

import "testing"

func TestProfileSectionUpsert(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	err := q.UpsertProfileSection(p.ID, "voice", `{"personality":"bold"}`)
	if err != nil {
		t.Fatalf("upsert: %v", err)
	}

	section, err := q.GetProfileSection(p.ID, "voice")
	if err != nil {
		t.Fatalf("get: %v", err)
	}
	if section.Content != `{"personality":"bold"}` {
		t.Errorf("unexpected content: %s", section.Content)
	}

	// Update existing
	q.UpsertProfileSection(p.ID, "voice", `{"personality":"confident"}`)
	section, _ = q.GetProfileSection(p.ID, "voice")
	if section.Content != `{"personality":"confident"}` {
		t.Errorf("expected updated content, got: %s", section.Content)
	}
}

func TestListProfileSections(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	q.UpsertProfileSection(p.ID, "voice", `{"personality":"bold"}`)
	q.UpsertProfileSection(p.ID, "tone", `{"formality":"casual"}`)

	sections, err := q.ListProfileSections(p.ID)
	if err != nil {
		t.Fatalf("list: %v", err)
	}
	if len(sections) != 2 {
		t.Errorf("expected 2, got %d", len(sections))
	}
}

func TestSectionInputsCRUD(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	input, err := q.CreateSectionInput(p.ID, "voice", "Blog sample", "We build great things.", "")
	if err != nil {
		t.Fatalf("create: %v", err)
	}
	if input.ID == 0 {
		t.Fatal("expected non-zero ID")
	}

	inputs, _ := q.ListSectionInputs(p.ID, "voice")
	if len(inputs) != 1 {
		t.Errorf("expected 1, got %d", len(inputs))
	}

	// General inputs (nil section)
	q.CreateSectionInput(p.ID, "", "General note", "Some general content", "")
	allInputs, _ := q.ListSectionInputs(p.ID, "")
	if len(allInputs) != 2 {
		t.Errorf("expected 2 total, got %d", len(allInputs))
	}
}

func TestProposalWorkflow(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	prop, err := q.CreateProposal(p.ID, "voice", `{"personality":"bold"}`)
	if err != nil {
		t.Fatalf("create: %v", err)
	}
	if prop.Status != "pending" {
		t.Errorf("expected pending, got %s", prop.Status)
	}

	err = q.ApproveProposal(prop.ID)
	if err != nil {
		t.Fatalf("approve: %v", err)
	}

	got, _ := q.GetProposal(prop.ID)
	if got.Status != "approved" {
		t.Errorf("expected approved, got %s", got.Status)
	}

	// Verify section was updated
	section, _ := q.GetProfileSection(p.ID, "voice")
	if section.Content != `{"personality":"bold"}` {
		t.Errorf("section not updated: %s", section.Content)
	}
}

func TestRejectProposal(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	prop, _ := q.CreateProposal(p.ID, "voice", `{"personality":"boring"}`)
	err := q.RejectProposal(prop.ID, "Too generic, we're more edgy")
	if err != nil {
		t.Fatalf("reject: %v", err)
	}

	got, _ := q.GetProposal(prop.ID)
	if got.Status != "rejected" {
		t.Errorf("expected rejected, got %s", got.Status)
	}
	if got.RejectionReason != "Too generic, we're more edgy" {
		t.Errorf("unexpected reason: %s", got.RejectionReason)
	}
}

func TestProjectReferences(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	ref, err := q.CreateReference(p.ID, "Good blog", "Content here", "https://example.com", "user")
	if err != nil {
		t.Fatalf("create: %v", err)
	}
	if ref.ID == 0 {
		t.Fatal("expected non-zero ID")
	}

	refs, _ := q.ListReferences(p.ID)
	if len(refs) != 1 {
		t.Errorf("expected 1, got %d", len(refs))
	}
}
