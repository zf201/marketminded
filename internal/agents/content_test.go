package agents

import (
	"context"
	"testing"
)

func TestContentAgent_WritePillar(t *testing.T) {
	ai := &mockAI{response: "# How to Scale Your Agency\n\nGreat blog post content here..."}
	agent := NewContentAgent(ai, "test-model")

	result, err := agent.WritePillar(context.Background(), PillarInput{
		Topic:        "How to scale your web development agency",
		VoiceProfile: `{"tone":"professional"}`,
		ToneProfile:  `{"formality":"high"}`,
		ContentLog:   []string{},
	})
	if err != nil {
		t.Fatalf("write pillar: %v", err)
	}
	if result == "" {
		t.Fatal("expected non-empty result")
	}
}

func TestContentAgent_WriteSocialPost(t *testing.T) {
	ai := &mockAI{response: "Scaling your agency? Here are 3 lessons we learned..."}
	agent := NewContentAgent(ai, "test-model")

	result, err := agent.WriteSocialPost(context.Background(), SocialInput{
		PillarContent: "# How to Scale\n\nFull blog post...",
		Platform:      "linkedin",
		VoiceProfile:  `{"tone":"professional"}`,
		ToneProfile:   `{"formality":"high"}`,
	})
	if err != nil {
		t.Fatalf("write social: %v", err)
	}
	if result == "" {
		t.Fatal("expected non-empty result")
	}
}

func TestPlatformGuidelines(t *testing.T) {
	tests := []struct {
		platform string
	}{
		{"linkedin"},
		{"instagram"},
		{"facebook"},
		{"unknown"},
	}
	for _, tt := range tests {
		g := platformGuidelines(tt.platform)
		if g == "" {
			t.Errorf("empty guidelines for %s", tt.platform)
		}
	}
}
