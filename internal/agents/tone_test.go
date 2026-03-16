package agents

import (
	"context"
	"testing"
)

func TestToneAgent_BuildProfile(t *testing.T) {
	agent := NewToneAgent(&mockAI{response: `{"formality":"high","humor":"low"}`}, testModel)

	samples := []string{"Professional content sample."}
	brandDocs := []string{"Brand guidelines: formal, no slang."}

	profile, err := agent.BuildProfile(context.Background(), samples, brandDocs)
	if err != nil {
		t.Fatalf("build profile: %v", err)
	}
	if profile == "" {
		t.Fatal("expected non-empty profile")
	}
}
