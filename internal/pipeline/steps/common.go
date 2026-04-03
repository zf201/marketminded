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
	"github.com/zanfridau/marketminded/internal/types"
)

// runWithTools is the common pattern for streaming a step with tool calling.
func runWithTools(
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
) (pipeline.StepResult, error) {
	aiMsgs := []types.Message{
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
			case "web_search":
				summary = tools.SearchSummary(event.Args)
				var args struct{ Query string `json:"query"` }
				if json.Unmarshal([]byte(event.Args), &args) == nil && args.Query != "" {
					toolCallsList = append(toolCallsList, pipeline.ToolCallRecord{Type: "search", Value: args.Query})
				}
			}
			evt := map[string]string{"type": "tool_start", "tool": event.Tool, "summary": summary}
			if event.Tool == "fetch_url" {
				var a struct{ URL string `json:"url"` }
				if json.Unmarshal([]byte(event.Args), &a) == nil {
					evt["url"] = a.URL
				}
			} else if event.Tool == "web_search" {
				var a struct{ Query string `json:"query"` }
				if json.Unmarshal([]byte(event.Args), &a) == nil {
					evt["query"] = a.Query
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
	start := time.Now()
	applog.Info("pipeline step %s: model=%s starting (submit=%s, maxIter=%d)", submitToolName, model, submitToolName, maxIter)

	_, err := aiClient.StreamWithTools(ctx, model, aiMsgs, toolList, executor, onToolEvent, sendChunk, sendThinking, &temperature, maxIter)

	duration := time.Since(start)
	result := pipeline.StepResult{
		Output:    savedOutput,
		Thinking:  thinkingBuf.String(),
		ToolCalls: pipeline.ToolCallsJSON(toolCallsList),
	}

	if err != nil {
		applog.Error("pipeline step %s: model=%s failed after %s: %s", submitToolName, model, duration, err.Error())
		if result.Output == "" {
			result.Output = chunkBuf.String()
		}
		return result, err
	}

	if savedOutput == "" {
		applog.Error("pipeline step %s: model=%s completed in %s but no result submitted", submitToolName, model, duration)
		result.Output = chunkBuf.String()
		return result, fmt.Errorf("step did not submit results via tool call")
	}

	applog.Info("pipeline step %s: model=%s completed in %s, output=%d bytes, tools=%d", submitToolName, model, duration, len(savedOutput), len(toolCallsList))
	return result, nil
}
