package store

import "testing"

func TestPipelineRunLifecycle(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	run, err := q.CreatePipelineRun(p.ID)
	if err != nil {
		t.Fatalf("create: %v", err)
	}
	if run.Status != "ideating" {
		t.Errorf("expected ideating, got %s", run.Status)
	}

	err = q.AdvancePipelineRun(run.ID, "creating_pillar")
	if err != nil {
		t.Fatalf("advance: %v", err)
	}

	got, _ := q.GetPipelineRun(run.ID)
	if got.Status != "creating_pillar" {
		t.Errorf("expected creating_pillar, got %s", got.Status)
	}
}
