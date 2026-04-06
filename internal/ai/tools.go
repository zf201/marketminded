package ai

import (
	"bufio"
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"strings"

)

// Tool definitions for the API request
type Tool struct {
	Type       string          `json:"type"`
	Function   *ToolFunction   `json:"function,omitempty"`
	Parameters json.RawMessage `json:"parameters,omitempty"` // server tool config
}

// ServerTool creates an OpenRouter server tool with optional parameters.
func ServerTool(toolType string, params ...json.RawMessage) Tool {
	t := Tool{Type: toolType}
	if len(params) > 0 {
		t.Parameters = params[0]
	}
	return t
}

type ToolFunction struct {
	Name        string          `json:"name"`
	Description string          `json:"description"`
	Parameters  json.RawMessage `json:"parameters"`
}

// Tool calls from the API response
type ToolCall struct {
	ID       string           `json:"id"`
	Type     string           `json:"type"`
	Function ToolCallFunction `json:"function"`
}

type ToolCallFunction struct {
	Name      string `json:"name"`
	Arguments string `json:"arguments"`
}

// ChatMessage supports tool calls and tool results
type ChatMessage struct {
	Role       string     `json:"role"`
	Content    string     `json:"content,omitempty"`
	ToolCalls  []ToolCall `json:"tool_calls,omitempty"`
	ToolCallID string     `json:"tool_call_id,omitempty"`
}

// Streaming response types
type toolChatRequest struct {
	Model       string        `json:"model"`
	Messages    []ChatMessage `json:"messages"`
	Stream      bool          `json:"stream,omitempty"`
	Temperature *float64      `json:"temperature,omitempty"`
	Tools       []Tool        `json:"tools,omitempty"`
	ToolChoice  any           `json:"tool_choice,omitempty"`
}

type streamChoice struct {
	Delta        streamDelta `json:"delta"`
	FinishReason *string     `json:"finish_reason"`
}

type streamDelta struct {
	Role             string `json:"role,omitempty"`
	Content          string `json:"content,omitempty"`
	Reasoning        string `json:"reasoning,omitempty"`
	ReasoningDetails []struct {
		Type string `json:"type"`
		Text string `json:"text"`
	} `json:"reasoning_details,omitempty"`
	ToolCalls []struct {
		Index    int              `json:"index"`
		ID       string           `json:"id,omitempty"`
		Type     string           `json:"type,omitempty"`
		Function ToolCallFunction `json:"function"`
	} `json:"tool_calls,omitempty"`
}

type streamResponse struct {
	Choices []streamChoice `json:"choices"`
}

// ErrToolDone signals that the executor has captured its final output
// and StreamWithTools should stop after emitting the tool_result event.
var ErrToolDone = fmt.Errorf("tool done")

// Callback types
type ToolExecutor func(ctx context.Context, toolName string, arguments string) (string, error)

type ToolEventFn func(event ToolEvent)

type ToolEvent struct {
	Type    string // "tool_start", "tool_result", "proposal"
	Tool    string
	Args    string
	Summary string
	Section string
	Content string
}

// StreamWithTools streams a chat completion with tool calling support.
// It handles the multi-turn loop: stream text -> detect tool calls -> execute -> send results -> continue.
// submitToolName is the name of the final submit tool — used to force the model to call it
// when it tries to respond with text instead of a tool call.
// Returns the final accumulated text and any error.
func (c *Client) StreamWithTools(
	ctx context.Context,
	model string,
	messages []Message,
	tools []Tool,
	executor ToolExecutor,
	onToolEvent ToolEventFn,
	onChunk StreamFunc,
	onReasoning StreamFunc,
	temperature *float64,
	submitToolName string,
	maxIterations ...int,
) (string, error) {
	limit := 20
	if len(maxIterations) > 0 && maxIterations[0] > 0 {
		limit = maxIterations[0]
	}

	// Build the forced tool_choice for when the model tries to skip tool calls
	var forceSubmit any
	if submitToolName != "" {
		forceSubmit = map[string]any{
			"type":     "function",
			"function": map[string]string{"name": submitToolName},
		}
	}

	// Convert Message to ChatMessage
	chatMsgs := make([]ChatMessage, len(messages))
	for i, m := range messages {
		chatMsgs[i] = ChatMessage{Role: m.Role, Content: m.Content}
	}

	var fullText strings.Builder
	forceToolCall := false

	for iteration := 0; iteration < limit; iteration++ {
		var toolChoice any
		if forceToolCall {
			toolChoice = forceSubmit
		}
		text, toolCalls, err := c.streamOneTurn(ctx, model, chatMsgs, tools, onChunk, onReasoning, temperature, toolChoice)
		if err != nil {
			return fullText.String(), err
		}
		fullText.WriteString(text)

		if len(toolCalls) == 0 {
			if submitToolName == "" || forceSubmit == nil {
				return fullText.String(), nil
			}
			// Model responded with text instead of a tool call.
			// Add its text to history, nudge it, and force the specific submit tool next turn.
			chatMsgs = append(chatMsgs,
				ChatMessage{Role: "assistant", Content: text},
				ChatMessage{Role: "user", Content: "You must call the " + submitToolName + " tool now to deliver your results. Do not respond with text."},
			)
			forceToolCall = true
			continue
		}
		forceToolCall = false

		// Append assistant message with tool calls
		assistantMsg := ChatMessage{
			Role:      "assistant",
			ToolCalls: toolCalls,
		}
		if text != "" {
			assistantMsg.Content = text
		}
		chatMsgs = append(chatMsgs, assistantMsg)

		// Execute each tool call
		done := false
		for _, tc := range toolCalls {
			// Emit tool_start
			onToolEvent(ToolEvent{
				Type: "tool_start",
				Tool: tc.Function.Name,
				Args: tc.Function.Arguments,
			})

			// Execute
			result, execErr := executor(ctx, tc.Function.Name, tc.Function.Arguments)
			if errors.Is(execErr, ErrToolDone) {
				// Final submit/write tool — emit result and stop
				onToolEvent(ToolEvent{
					Type:    "tool_result",
					Tool:    tc.Function.Name,
					Args:    tc.Function.Arguments,
					Summary: result,
				})
				done = true
				break
			}
			if execErr != nil {
				result = "Error: " + execErr.Error()
			}

			// Emit tool_result
			onToolEvent(ToolEvent{
				Type:    "tool_result",
				Tool:    tc.Function.Name,
				Args:    tc.Function.Arguments,
				Summary: result,
			})

			// Append tool result message
			chatMsgs = append(chatMsgs, ChatMessage{
				Role:       "tool",
				Content:    result,
				ToolCallID: tc.ID,
			})
		}
		if done {
			return fullText.String(), nil
		}
	}

	return fullText.String(), fmt.Errorf("max tool call iterations reached")
}

// streamOneTurn sends one request and streams the response.
// Returns accumulated text content, any tool calls, and error.
func (c *Client) streamOneTurn(
	ctx context.Context,
	model string,
	messages []ChatMessage,
	tools []Tool,
	onChunk StreamFunc,
	onReasoning StreamFunc,
	temperature *float64,
	toolChoice any,
) (string, []ToolCall, error) {
	body, _ := json.Marshal(toolChatRequest{
		Model:       model,
		Messages:    messages,
		Stream:      true,
		Tools:       tools,
		Temperature: temperature,
		ToolChoice:  toolChoice,
	})

	req, err := http.NewRequestWithContext(ctx, "POST", c.baseURL+"/chat/completions", bytes.NewReader(body))
	if err != nil {
		return "", nil, err
	}
	req.Header.Set("Authorization", "Bearer "+c.apiKey)
	req.Header.Set("Content-Type", "application/json")

	resp, err := c.http.Do(req)
	if err != nil {
		return "", nil, err
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		b, _ := io.ReadAll(resp.Body)
		return "", nil, fmt.Errorf("openrouter: %d: %s", resp.StatusCode, string(b))
	}

	var textContent strings.Builder
	pendingToolCalls := make(map[int]*ToolCall)

	scanner := bufio.NewScanner(resp.Body)
	for scanner.Scan() {
		line := scanner.Text()
		if !strings.HasPrefix(line, "data: ") {
			continue
		}
		data := strings.TrimPrefix(line, "data: ")
		if data == "[DONE]" {
			break
		}

		var sr streamResponse
		if err := json.Unmarshal([]byte(data), &sr); err != nil {
			continue
		}
		if len(sr.Choices) == 0 {
			continue
		}
		choice := sr.Choices[0]

		// Accumulate text content
		if choice.Delta.Content != "" {
			textContent.WriteString(choice.Delta.Content)
			if err := onChunk(choice.Delta.Content); err != nil {
				return textContent.String(), nil, err
			}
		}

		// Handle reasoning — prefer simple string field, fall back to details array
		if choice.Delta.Reasoning != "" {
			onReasoning(choice.Delta.Reasoning)
		} else {
			for _, rd := range choice.Delta.ReasoningDetails {
				if rd.Type == "reasoning.text" && rd.Text != "" {
					onReasoning(rd.Text)
				}
			}
		}

		// Accumulate tool call deltas
		for _, tc := range choice.Delta.ToolCalls {
			existing, ok := pendingToolCalls[tc.Index]
			if !ok {
				existing = &ToolCall{
					ID:   tc.ID,
					Type: tc.Type,
					Function: ToolCallFunction{
						Name: tc.Function.Name,
					},
				}
				pendingToolCalls[tc.Index] = existing
			}
			// Append arguments fragment
			existing.Function.Arguments += tc.Function.Arguments
		}
	}

	if err := scanner.Err(); err != nil {
		return textContent.String(), nil, err
	}

	// Collect tool calls in order
	var toolCalls []ToolCall
	for i := 0; i < len(pendingToolCalls); i++ {
		if tc, ok := pendingToolCalls[i]; ok {
			toolCalls = append(toolCalls, *tc)
		}
	}

	return textContent.String(), toolCalls, nil
}
