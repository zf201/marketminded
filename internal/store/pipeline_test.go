package store

import "testing"

func TestPipelineRunCRUD(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	run, err := q.CreatePipelineRun(p.ID, "5 pricing mistakes")
	if err != nil {
		t.Fatalf("create: %v", err)
	}
	if run.Topic == "" {
		t.Error("expected topic to be auto-generated, got empty")
	}
	if run.Brief != "5 pricing mistakes" {
		t.Errorf("expected brief '5 pricing mistakes', got %s", run.Brief)
	}
	if run.Status != "planning" {
		t.Errorf("expected planning, got %s", run.Status)
	}

	q.UpdatePipelinePlan(run.ID, `{"cornerstone":{"platform":"blog"}}`)
	q.UpdatePipelineStatus(run.ID, "producing")

	got, _ := q.GetPipelineRun(run.ID)
	if got.Status != "producing" {
		t.Errorf("expected producing, got %s", got.Status)
	}
	if got.Plan == "" {
		t.Error("expected plan to be set")
	}
}
