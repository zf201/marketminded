package tools

import (
	"context"
	"encoding/json"
	"fmt"

	"github.com/zanfridau/marketminded/internal/ai"
)

var validSections = map[string]bool{
	"product_and_positioning": true, "audience": true, "voice_and_tone": true,
	"content_strategy": true, "guidelines": true, "waterfalls": true,
}

func NewUpdateSectionTool() ai.Tool {
	return ai.Tool{
		Type: "function",
		Function: ai.ToolFunction{
			Name:        "update_section",
			Description: "Propose an update to a profile section. The user will accept or reject it. Write thorough, specific prose about this client — not generic marketing advice.",
			Parameters: json.RawMessage(`{"type":"object","properties":{"section":{"type":"string","enum":["product_and_positioning","audience","voice_and_tone","content_strategy","guidelines","waterfalls"],"description":"The profile section to update"},"content":{"type":"string","description":"The full new content for this section. Must be specific to this client. For waterfalls, use JSON format."}},"required":["section","content"]}`),
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
		return fmt.Sprintf("Error: '%s' is not a valid section. Valid sections: product_and_positioning, audience, voice_and_tone, content_strategy, guidelines, waterfalls.", args.Section), nil
	}
	return fmt.Sprintf("Proposed update to %s section. Waiting for user approval.", args.Section), nil
}

func ParseUpdateArgs(argsJSON string) (UpdateArgs, error) {
	var args UpdateArgs
	err := json.Unmarshal([]byte(argsJSON), &args)
	return args, err
}
