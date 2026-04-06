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
	q.CreatePipelineStep(run.ID, "factcheck", 1)
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
