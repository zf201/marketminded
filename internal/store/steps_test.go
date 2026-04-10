package store

import "testing"

func TestPipelineStepCRUD(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")
	run, _ := q.CreatePipelineRun(p.ID, "test topic")

	step, err := q.CreatePipelineStep(run.ID, "research", 0)
	if err != nil {
		t.Fatalf("create step: %v", err)
	}
	if step.StepType != "research" {
		t.Errorf("expected research, got %s", step.StepType)
	}
	if step.Status != "pending" {
		t.Errorf("expected pending, got %s", step.Status)
	}
	if step.SortOrder != 0 {
		t.Errorf("expected sort_order 0, got %d", step.SortOrder)
	}
}

func TestListPipelineStepsOrder(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")
	run, _ := q.CreatePipelineRun(p.ID, "test topic")

	q.CreatePipelineStep(run.ID, "research", 0)
	q.CreatePipelineStep(run.ID, "claim_verifier", 1)
	q.CreatePipelineStep(run.ID, "write", 2)

	steps, err := q.ListPipelineSteps(run.ID)
	if err != nil {
		t.Fatalf("list: %v", err)
	}
	if len(steps) != 3 {
		t.Fatalf("expected 3 steps, got %d", len(steps))
	}
	if steps[0].StepType != "research" {
		t.Errorf("expected research first, got %s", steps[0].StepType)
	}
	if steps[2].StepType != "write" {
		t.Errorf("expected write last, got %s", steps[2].StepType)
	}
}

func TestCreateDefaultPipelineSteps_ClaimVerifierDisabled(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")
	run, _ := q.CreatePipelineRun(p.ID, "test topic")

	// Setting unset → claim_verifier omitted from default sequence.
	if err := q.CreateDefaultPipelineSteps(run.ID); err != nil {
		t.Fatalf("CreateDefaultPipelineSteps: %v", err)
	}

	steps, _ := q.ListPipelineSteps(run.ID)
	if len(steps) != 4 {
		t.Fatalf("expected 4 default steps when claim_verifier disabled, got %d", len(steps))
	}
	for _, s := range steps {
		if s.StepType == "claim_verifier" || s.StepType == "factcheck" {
			t.Fatalf("did not expect verifier step, got %s", s.StepType)
		}
	}
}

func TestCreateDefaultPipelineSteps_ClaimVerifierEnabled(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")
	run, _ := q.CreatePipelineRun(p.ID, "test topic")

	if err := q.SetSetting("claim_verifier_enabled", "true"); err != nil {
		t.Fatalf("SetSetting: %v", err)
	}

	if err := q.CreateDefaultPipelineSteps(run.ID); err != nil {
		t.Fatalf("CreateDefaultPipelineSteps: %v", err)
	}

	steps, _ := q.ListPipelineSteps(run.ID)
	if len(steps) != 5 {
		t.Fatalf("expected 5 default steps when claim_verifier enabled, got %d", len(steps))
	}

	want := []string{"research", "brand_enricher", "claim_verifier", "editor", "write"}
	for i, s := range steps {
		if s.StepType != want[i] {
			t.Errorf("step[%d]: want %q, got %q", i, want[i], s.StepType)
		}
	}
}

func TestCreateDefaultPipelineSteps_NoPersonas_SkipsAudience(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")
	run, _ := q.CreatePipelineRun(p.ID, "test topic")

	if err := q.CreateDefaultPipelineSteps(run.ID); err != nil {
		t.Fatalf("CreateDefaultPipelineSteps: %v", err)
	}

	steps, _ := q.ListPipelineSteps(run.ID)
	for _, s := range steps {
		if s.StepType == "audience_picker" {
			t.Errorf("audience_picker should be skipped when no personas exist")
		}
	}
}

func TestCreateDefaultPipelineSteps_WithPersonas_IncludesAudience(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")
	run, _ := q.CreatePipelineRun(p.ID, "test topic")

	if _, err := q.CreateAudiencePersona(p.ID, AudiencePersona{
		Label: "Pro chef", Description: "d", PainPoints: "pp", Push: "pu", Pull: "pl", Anxiety: "a", Habit: "h",
	}); err != nil {
		t.Fatalf("seed persona: %v", err)
	}

	if err := q.CreateDefaultPipelineSteps(run.ID); err != nil {
		t.Fatalf("CreateDefaultPipelineSteps: %v", err)
	}

	steps, _ := q.ListPipelineSteps(run.ID)
	var foundAudience bool
	var audIdx, brandIdx int
	for i, s := range steps {
		if s.StepType == "audience_picker" {
			foundAudience = true
			audIdx = i
		}
		if s.StepType == "brand_enricher" {
			brandIdx = i
		}
	}
	if !foundAudience {
		t.Fatalf("audience_picker missing when personas exist, got %+v", steps)
	}
	if audIdx >= brandIdx {
		t.Errorf("audience_picker must come before brand_enricher, got audience at %d, brand at %d", audIdx, brandIdx)
	}
}

func TestCreateDefaultPipelineSteps_NoBlogURL_SkipsStyleReference(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")
	run, _ := q.CreatePipelineRun(p.ID, "test topic")

	if err := q.CreateDefaultPipelineSteps(run.ID); err != nil {
		t.Fatalf("CreateDefaultPipelineSteps: %v", err)
	}

	steps, _ := q.ListPipelineSteps(run.ID)
	for _, s := range steps {
		if s.StepType == "style_reference" {
			t.Errorf("style_reference should be skipped when blog_url is unset")
		}
	}
}

func TestCreateDefaultPipelineSteps_WithBlogURL_IncludesStyleReference(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")
	run, _ := q.CreatePipelineRun(p.ID, "test topic")

	if err := q.SetProjectSetting(p.ID, "blog_url", "https://brand.example/blog"); err != nil {
		t.Fatalf("set blog_url: %v", err)
	}

	if err := q.CreateDefaultPipelineSteps(run.ID); err != nil {
		t.Fatalf("CreateDefaultPipelineSteps: %v", err)
	}

	steps, _ := q.ListPipelineSteps(run.ID)
	var foundStyle bool
	var styleIdx, writeIdx int
	for i, s := range steps {
		if s.StepType == "style_reference" {
			foundStyle = true
			styleIdx = i
		}
		if s.StepType == "write" {
			writeIdx = i
		}
	}
	if !foundStyle {
		t.Fatalf("style_reference missing when blog_url is set, got %+v", steps)
	}
	if styleIdx >= writeIdx {
		t.Errorf("style_reference must come before write, got style at %d, write at %d", styleIdx, writeIdx)
	}
}

func TestUpdatePipelineStepOutput(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")
	run, _ := q.CreatePipelineRun(p.ID, "test topic")
	step, _ := q.CreatePipelineStep(run.ID, "research", 0)

	q.UpdatePipelineStepOutput(step.ID, `{"brief":"test"}`, "thinking chain")
	got, _ := q.GetPipelineStep(step.ID)
	if got.Output != `{"brief":"test"}` {
		t.Errorf("expected output to be set, got %s", got.Output)
	}
	if got.Thinking != "thinking chain" {
		t.Errorf("expected thinking to be set, got %s", got.Thinking)
	}
}
