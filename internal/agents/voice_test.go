package agents

import (
	"context"
	"testing"

	"github.com/zanfridau/marketminded/internal/types"
)

// mockAI is a test double shared across all agent tests in this package
type mockAI struct {
	response string
}

func (m *mockAI) Complete(ctx context.Context, model string, msgs []types.Message) (string, error) {
	return m.response, nil
}

func (m *mockAI) Stream(ctx context.Context, model string, msgs []types.Message, fn types.StreamFunc) (string, error) {
	fn(m.response)
	return m.response, nil
}

func testModel() string { return "test-model" }

func TestVoiceAgent_BuildProfile(t *testing.T) {
	agent := NewVoiceAgent(&mockAI{response: `{"tone":"professional","vocabulary":"technical"}`}, testModel)

	samples := []string{
		"We build scalable web applications using modern frameworks.",
		"Our team focuses on clean code and test-driven development.",
	}

	profile, err := agent.BuildProfile(context.Background(), samples)
	if err != nil {
		t.Fatalf("build profile: %v", err)
	}
	if profile == "" {
		t.Fatal("expected non-empty profile")
	}
}
