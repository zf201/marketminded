package pipeline

import (
	"context"
	"fmt"
)

var validTransitions = map[string][]string{
	"ideating":        {"creating_pillar", "abandoned"},
	"creating_pillar": {"waterfalling", "abandoned"},
	"waterfalling":    {"complete", "abandoned"},
}

// Store abstracts the database operations the pipeline needs.
type Store interface {
	GetPipelineRun(id int64) (*Run, error)
	AdvancePipelineRun(id int64, status string) error
	SetPipelineTopic(id int64, topic string) error
}

// Run represents a pipeline run's current state.
type Run struct {
	ID            int64
	ProjectID     int64
	Status        string
	SelectedTopic *string
}

// Pipeline orchestrates the waterfall state machine.
type Pipeline struct {
	store Store
}

func New(store Store) *Pipeline {
	return &Pipeline{store: store}
}

func ValidateTransition(from, to string) error {
	allowed, ok := validTransitions[from]
	if !ok {
		return fmt.Errorf("no transitions from state %q", from)
	}
	for _, a := range allowed {
		if a == to {
			return nil
		}
	}
	return fmt.Errorf("invalid transition: %s → %s", from, to)
}

func (p *Pipeline) Advance(ctx context.Context, runID int64, newStatus string) error {
	run, err := p.store.GetPipelineRun(runID)
	if err != nil {
		return fmt.Errorf("get run: %w", err)
	}
	if err := ValidateTransition(run.Status, newStatus); err != nil {
		return err
	}
	return p.store.AdvancePipelineRun(runID, newStatus)
}

func (p *Pipeline) SetTopic(ctx context.Context, runID int64, topic string) error {
	return p.store.SetPipelineTopic(runID, topic)
}
