package pipeline

import (
	"context"

	"github.com/zanfridau/marketminded/internal/ai"
)

// StepRunner executes a single pipeline step type.
type StepRunner interface {
	Type() string
	Run(ctx context.Context, input StepInput, stream StepStream) (StepResult, error)
}

// StepInput is everything a step needs to execute.
type StepInput struct {
	ProjectID    int64
	RunID        int64
	StepID       int64
	Topic        string
	Brief        string
	Profile      string
	PriorOutputs map[string]string
}

// StepResult is what a step returns after execution.
type StepResult struct {
	Output    string
	Thinking  string
	ToolCalls string
	Usage     *ai.StreamUsage
}

// StepStream abstracts SSE output so steps don't depend on HTTP.
type StepStream interface {
	SendChunk(chunk string) error
	SendThinking(chunk string) error
	SendEvent(v any)
	SendError(msg string)
	SendDone()
}

// ToolCallRecord tracks tool usage for UI display.
type ToolCallRecord struct {
	Type  string `json:"type"`
	Value string `json:"value"`
}
