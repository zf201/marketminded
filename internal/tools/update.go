package tools

import (
	"context"
	"encoding/json"
	"fmt"

	"github.com/zanfridau/marketminded/internal/ai"
)

var validSections = map[string]bool{
	"business": true, "audience": true, "voice": true, "tone": true,
	"strategy": true, "pillars": true, "guidelines": true,
	"competitors": true, "inspiration": true, "offers": true,
}

func NewUpdateSectionTool() ai.Tool {
	return ai.Tool{
		Type: "function",
		Function: ai.ToolFunction{
			Name:        "update_section",
			Description: "Propose an update to a profile section. The user will be asked to accept or reject. Write the full new content for the section as clear, natural prose.",
			Parameters: json.RawMessage(`{"type":"object","properties":{"section":{"type":"string","enum":["business","audience","voice","tone","strategy","pillars","guidelines","competitors","inspiration","offers"],"description":"The profile section to update"},"content":{"type":"string","description":"The full new content for this section. Write natural prose, not JSON."}},"required":["section","content"]}`),
		},
	}
}

type UpdateArgs struct {
	Section string `json:"section"`
	Content string `json:"content"`
}

func ExecuteUpdateSection(ctx context.Context, argsJSON string) (string, error) {
	var args UpdateArgs
	if err := json.Unmarshal([]byte(argsJSON), &args); err != nil {
		return "", fmt.Errorf("invalid arguments: %w", err)
	}
	if !validSections[args.Section] {
		return fmt.Sprintf("Error: '%s' is not a valid section. Valid sections: business, audience, voice, tone, strategy, pillars, guidelines, competitors, inspiration, offers.", args.Section), nil
	}
	return fmt.Sprintf("Proposed update to %s section. Waiting for user approval.", args.Section), nil
}

func ParseUpdateArgs(argsJSON string) (UpdateArgs, error) {
	var args UpdateArgs
	err := json.Unmarshal([]byte(argsJSON), &args)
	return args, err
}
