package pipeline

import (
	"encoding/json"
	"fmt"
	"strings"
)

// AudienceSelection is the step output of the audience_picker step. It tells
// downstream steps who the post addresses and how to tailor the piece.
type AudienceSelection struct {
	Mode              string `json:"mode"` // persona | educational | commentary
	PersonaID         *int64 `json:"persona_id,omitempty"`
	PersonaLabel      string `json:"persona_label,omitempty"`
	PersonaSummary    string `json:"persona_summary,omitempty"`
	Reasoning         string `json:"reasoning"`
	GuidanceForWriter string `json:"guidance_for_writer"`
}

// ParseAudienceSelection parses the audience_picker step output JSON into a
// struct and enforces the cross-field rules the tool executor should have
// caught. Returns a descriptive error on any violation.
func ParseAudienceSelection(raw string) (*AudienceSelection, error) {
	if strings.TrimSpace(raw) == "" {
		return nil, fmt.Errorf("empty audience selection")
	}
	var sel AudienceSelection
	if err := json.Unmarshal([]byte(raw), &sel); err != nil {
		return nil, fmt.Errorf("parse audience selection: %w", err)
	}
	switch sel.Mode {
	case "persona":
		if sel.PersonaID == nil {
			return nil, fmt.Errorf("mode=persona requires non-null persona_id")
		}
	case "educational", "commentary":
		if sel.PersonaID != nil {
			return nil, fmt.Errorf("mode=%s must not have persona_id", sel.Mode)
		}
	default:
		return nil, fmt.Errorf("invalid mode %q (want persona | educational | commentary)", sel.Mode)
	}
	if strings.TrimSpace(sel.GuidanceForWriter) == "" {
		return nil, fmt.Errorf("guidance_for_writer must not be empty")
	}
	return &sel, nil
}

// FormatAudienceBlock renders an audience selection as a prompt block. Returns
// an empty string for a nil selection, which downstream prompt builders use to
// omit the section entirely when the audience_picker step was skipped.
func FormatAudienceBlock(sel *AudienceSelection) string {
	if sel == nil {
		return ""
	}
	var b strings.Builder
	b.WriteString("\n## Audience target\n")
	switch sel.Mode {
	case "persona":
		fmt.Fprintf(&b, "Mode: persona\n")
		if sel.PersonaLabel != "" {
			fmt.Fprintf(&b, "Persona: %s\n", sel.PersonaLabel)
		}
		if sel.PersonaSummary != "" {
			fmt.Fprintf(&b, "Summary: %s\n", sel.PersonaSummary)
		}
	case "educational":
		b.WriteString("Mode: educational (no persona — speak to a curious learner of the topic)\n")
	case "commentary":
		b.WriteString("Mode: commentary (no persona — speak to an informed reader of this space)\n")
	}
	if sel.Reasoning != "" {
		fmt.Fprintf(&b, "Reasoning: %s\n", sel.Reasoning)
	}
	fmt.Fprintf(&b, "Writer guidance: %s\n", sel.GuidanceForWriter)
	return b.String()
}
