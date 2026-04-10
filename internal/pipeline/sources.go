package pipeline

import "encoding/json"

// ToolCallsJSON serializes tool call records to JSON string.
func ToolCallsJSON(calls []ToolCallRecord) string {
	if len(calls) == 0 {
		return ""
	}
	data, _ := json.Marshal(calls)
	return string(data)
}
