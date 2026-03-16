package pipeline

import (
	"context"
	"testing"
)

type mockStore struct {
	run *Run
}

func (m *mockStore) GetPipelineRun(id int64) (*Run, error) { return m.run, nil }
func (m *mockStore) AdvancePipelineRun(id int64, status string) error {
	m.run.Status = status
	return nil
}
func (m *mockStore) SetPipelineTopic(id int64, topic string) error { return nil }

func TestValidTransitions(t *testing.T) {
	tests := []struct {
		from, to string
		valid    bool
	}{
		{"ideating", "creating_pillar", true},
		{"creating_pillar", "waterfalling", true},
		{"waterfalling", "complete", true},
		{"ideating", "abandoned", true},
		{"creating_pillar", "abandoned", true},
		{"waterfalling", "abandoned", true},
		{"ideating", "complete", false},
		{"complete", "ideating", false},
		{"waterfalling", "ideating", false},
	}

	for _, tt := range tests {
		err := ValidateTransition(tt.from, tt.to)
		if tt.valid && err != nil {
			t.Errorf("%s → %s should be valid, got: %v", tt.from, tt.to, err)
		}
		if !tt.valid && err == nil {
			t.Errorf("%s → %s should be invalid", tt.from, tt.to)
		}
	}
}

func TestAdvance(t *testing.T) {
	ms := &mockStore{run: &Run{ID: 1, ProjectID: 1, Status: "ideating"}}
	p := New(ms)

	err := p.Advance(context.Background(), 1, "creating_pillar")
	if err != nil {
		t.Fatalf("advance: %v", err)
	}
	if ms.run.Status != "creating_pillar" {
		t.Errorf("expected creating_pillar, got %s", ms.run.Status)
	}

	// Invalid transition
	err = p.Advance(context.Background(), 1, "complete")
	if err == nil {
		t.Fatal("expected error for invalid transition")
	}
}
