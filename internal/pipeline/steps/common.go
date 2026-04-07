package steps

import (
	"context"
	"encoding/json"
	"fmt"
	"strings"

	"time"

	"github.com/zanfridau/marketminded/internal/ai"
	"github.com/zanfridau/marketminded/internal/applog"
	"github.com/zanfridau/marketminded/internal/pipeline"
	"github.com/zanfridau/marketminded/internal/tools"
)

// RunWithTools is the common pattern for streaming a step with tool calling.
// logPrefix identifies the caller in logs, e.g. "pipeline run=5 step=12 type=research"
func RunWithTools(
	ctx context.Context,
	aiClient *ai.Client,
	model string,
	systemPrompt string,
	userPrompt string,
	toolList []ai.Tool,
	registry *tools.Registry,
	submitToolName string,
	stream pipeline.StepStream,
	temp float64,
	maxIter int,
	logPrefix string,
) (pipeline.StepResult, error) {
	// Each step's prompt builder owns its own workflow/budget guidance.
	// Server-side searches do NOT count against maxIter (they're capped by
	// max_total_results in the registry); only function-tool calls do.
	aiMsgs := []ai.Message{
		{Role: "system", Content: systemPrompt},
		{Role: "user", Content: userPrompt},
	}

	var thinkingBuf strings.Builder
	var chunkBuf strings.Builder
	var savedOutput string
	var toolCallsList []pipeline.ToolCallRecord

	executor := func(ctx context.Context, name, args string) (string, error) {
		if name == submitToolName {
			savedOutput = args
			return "Saved successfully.", ai.ErrToolDone
		}
		return registry.Execute(ctx, name, args)
	}

	onToolEvent := func(event ai.ToolEvent) {
		switch event.Type {
		case "tool_start":
			if event.Tool == submitToolName {
				return
			}
			summary := ""
			switch event.Tool {
			case "fetch_url":
				summary = tools.FetchSummary(event.Args)
				var args struct{ URL string `json:"url"` }
				if json.Unmarshal([]byte(event.Args), &args) == nil && args.URL != "" {
					toolCallsList = append(toolCallsList, pipeline.ToolCallRecord{Type: "fetch", Value: args.URL})
				}
			}
			evt := map[string]string{"type": "tool_start", "tool": event.Tool, "summary": summary}
			if event.Tool == "fetch_url" {
				var a struct{ URL string `json:"url"` }
				if json.Unmarshal([]byte(event.Args), &a) == nil {
					evt["url"] = a.URL
				}
			}
			stream.SendEvent(evt)
		case "tool_result":
			summary := event.Summary
			if len(summary) > 200 {
				summary = summary[:200] + "..."
			}
			stream.SendEvent(map[string]string{"type": "tool_result", "tool": event.Tool, "summary": summary})
		}
	}

	sendChunk := func(chunk string) error {
		chunkBuf.WriteString(chunk)
		return stream.SendChunk(chunk)
	}

	sendThinking := func(chunk string) error {
		thinkingBuf.WriteString(chunk)
		return stream.SendThinking(chunk)
	}

	temperature := temp
	if logPrefix == "" {
		logPrefix = submitToolName
	}
	start := time.Now()
	applog.Info("%s: model=%s starting (submit=%s, maxIter=%d)", logPrefix, model, submitToolName, maxIter)

	_, usage, err := aiClient.StreamWithTools(ctx, model, aiMsgs, toolList, executor, onToolEvent, sendChunk, sendThinking, &temperature, submitToolName, maxIter)

	duration := time.Since(start)
	var usageJSON string
	if usage != nil {
		if uj, err := json.Marshal(usage); err == nil {
			usageJSON = string(uj)
		}
	}

	result := pipeline.StepResult{
		Output:    savedOutput,
		Thinking:  thinkingBuf.String(),
		ToolCalls: pipeline.ToolCallsJSON(toolCallsList),
		UsageJSON: usageJSON,
	}

	if err != nil {
		applog.Error("%s: model=%s failed after %s: %s", logPrefix, model, duration, err.Error())
		if result.Output == "" {
			result.Output = chunkBuf.String()
		}
		return result, err
	}

	if savedOutput == "" {
		applog.Error("%s: model=%s completed in %s but no result submitted", logPrefix, model, duration)
		result.Output = chunkBuf.String()
		return result, fmt.Errorf("step did not submit results via tool call")
	}

	applog.Info("%s: model=%s completed in %s, output=%d bytes, tools=%d", logPrefix, model, duration, len(savedOutput), len(toolCallsList))
	return result, nil
}
