package store

import "testing"

func TestAgentRunCreate(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	run, err := q.CreateAgentRun(p.ID, nil, "profile", "Analyze profile inputs", "Profile: formal, technical", nil)
	if err != nil {
		t.Fatalf("create: %v", err)
	}
	if run.AgentType != "profile" {
		t.Errorf("expected profile, got %s", run.AgentType)
	}
}
