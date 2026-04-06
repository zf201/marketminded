package pipeline

import (
	"context"
	"fmt"

	"github.com/zanfridau/marketminded/internal/store"
)

// StepDependencies returns the dependency map: step type -> required prior step types.
func StepDependencies() map[string][]string {
	return map[string][]string{
		"research":       {},
		"brand_enricher": {"research"},
		"factcheck":      {"brand_enricher"},
		"editor":         {"factcheck"},
		"write":          {"editor"},
	}
}

// Orchestrator manages step dependencies and dispatching.
type Orchestrator struct {
	steps map[string]StepRunner
	store store.PipelineStore
}

// NewOrchestrator creates an Orchestrator with the given step runners.
func NewOrchestrator(pipelineStore store.PipelineStore, runners ...StepRunner) *Orchestrator {
	steps := make(map[string]StepRunner, len(runners))
	for _, r := range runners {
		steps[r.Type()] = r
	}
	return &Orchestrator{steps: steps, store: pipelineStore}
}

// RunStep resolves dependencies, builds input, and dispatches to the appropriate StepRunner.
func (o *Orchestrator) RunStep(ctx context.Context, stepID int64, run *store.PipelineRun, profile string, stream StepStream) error {
	step, err := o.store.GetPipelineStep(stepID)
	if err != nil {
		return fmt.Errorf("step not found: %w", err)
	}

	runner, ok := o.steps[step.StepType]
	if !ok {
		return fmt.Errorf("unknown step type: %s", step.StepType)
	}

	// Resolve dependencies
	steps, err := o.store.ListPipelineSteps(step.PipelineRunID)
	if err != nil {
		return fmt.Errorf("failed to list steps: %w", err)
	}

	deps := StepDependencies()
	priorOutputs := make(map[string]string)
	for _, s := range steps {
		if s.Status == "completed" && s.Output != "" {
			priorOutputs[s.StepType] = s.Output
		}
	}

	for _, dep := range deps[step.StepType] {
		if _, ok := priorOutputs[dep]; !ok {
			return fmt.Errorf("%s step not completed yet", dep)
		}
	}

	input := StepInput{
		ProjectID:    run.ProjectID,
		RunID:        run.ID,
		StepID:       stepID,
		Topic:        run.Topic,
		Brief:        run.Brief,
		Profile:      profile,
		PriorOutputs: priorOutputs,
	}

	result, runErr := runner.Run(ctx, input, stream)

	if runErr != nil {
		// On failure: clear output unless the AI returned a meaningful error
		errOutput := ""
		if result.Output != "" && ctx.Err() == nil {
			errOutput = result.Output
		}
		o.store.UpdatePipelineStepOutput(stepID, errOutput, "")
		o.store.UpdatePipelineStepToolCalls(stepID, "")
		o.store.UpdatePipelineStepStatus(stepID, "failed")
		return runErr
	}

	o.store.UpdatePipelineStepOutput(stepID, result.Output, result.Thinking)
	if result.ToolCalls != "" {
		o.store.UpdatePipelineStepToolCalls(stepID, result.ToolCalls)
	}
	if result.UsageJSON != "" {
		o.store.UpdatePipelineStepUsage(stepID, result.UsageJSON)
	}
	o.store.UpdatePipelineStepStatus(stepID, "completed")
	return nil
}
