package store

import "testing"

func TestAgentRunCreate(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	run, err := q.CreateAgentRun(p.ID, nil, "voice", "Analyze voice samples", "Voice profile: formal, technical", nil)
	if err != nil {
		t.Fatalf("create: %v", err)
	}
	if run.AgentType != "voice" {
		t.Errorf("expected voice, got %s", run.AgentType)
	}
}
