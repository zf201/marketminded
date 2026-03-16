package agents

import (
	"context"
	"testing"
)

func TestProfileAgent_Analyze(t *testing.T) {
	mockResponse := `[
		{"section":"voice","content":{"personality":"bold","vocabulary":"technical"}},
		{"section":"audience","content":{"demographics":"developers","pain_points":["scaling","hiring"]}}
	]`
	agent := NewProfileAgent(&mockAI{response: mockResponse}, testModel)

	proposals, err := agent.Analyze(context.Background(), ProfileAnalysisInput{
		Inputs:           []string{"We build scalable web apps. Our clients are CTOs."},
		ExistingSections: map[string]string{},
		Rejections:       []string{},
	})
	if err != nil {
		t.Fatalf("analyze: %v", err)
	}
	if len(proposals) == 0 {
		t.Fatal("expected proposals")
	}
}
