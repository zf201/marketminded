package pipeline

import (
	"context"
	"fmt"

	"github.com/zanfridau/marketminded/internal/store"
)

// StepDependencies returns the static dependency map: step type -> required prior step types.
// The editor's runtime dep on claim_verifier (when present in a run) is handled
// dynamically inside RunStep.
func StepDependencies() map[string][]string {
	return map[string][]string{
		"research":        {},
		"audience_picker": {"research"},
		"brand_enricher":  {"research"},
		"claim_verifier":  {"brand_enricher"},
		"editor":          {"brand_enricher"},
		"style_reference": {"editor"},
		"write":           {"editor"},
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
	required := append([]string(nil), deps[step.StepType]...)

	// Editor must wait for claim_verifier whenever the run includes one.
	if step.StepType == "editor" {
		for _, s := range steps {
			if s.StepType == "claim_verifier" {
				required = append(required, "claim_verifier")
				break
			}
		}
	}

	// brand_enricher must wait for audience_picker whenever the run includes one.
	if step.StepType == "brand_enricher" {
		for _, s := range steps {
			if s.StepType == "audience_picker" {
				required = append(required, "audience_picker")
				break
			}
		}
	}

	// write must wait for style_reference whenever the run includes one.
	if step.StepType == "write" {
		for _, s := range steps {
			if s.StepType == "style_reference" {
				required = append(required, "style_reference")
				break
			}
		}
	}

	priorOutputs := make(map[string]string)
	for _, s := range steps {
		if s.Status == "completed" && s.Output != "" {
			priorOutputs[s.StepType] = s.Output
		}
	}

	for _, dep := range required {
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
