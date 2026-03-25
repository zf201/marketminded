package ai

import (
	"context"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/zanfridau/marketminded/internal/types"
)

func TestStreamWithTools_NoToolCalls(t *testing.T) {
	// Server that returns a simple text response with no tool calls
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "text/event-stream")
		fmt.Fprintln(w, `data: {"choices":[{"delta":{"content":"Hello "},"finish_reason":null}]}`)
		fmt.Fprintln(w, `data: {"choices":[{"delta":{"content":"world!"},"finish_reason":"stop"}]}`)
		fmt.Fprintln(w, `data: [DONE]`)
	}))
	defer server.Close()

	c := NewClient("test-key", WithBaseURL(server.URL))
	var chunks []string
	text, err := c.StreamWithTools(
		context.Background(), "test-model",
		[]types.Message{{Role: "user", Content: "hi"}},
		nil, // no tools
		func(ctx context.Context, name, args string) (string, error) { return "", nil },
		func(event ToolEvent) {},
		func(chunk string) error { chunks = append(chunks, chunk); return nil },
		func(chunk string) error { return nil }, // onReasoning
		nil,
	)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if text != "Hello world!" {
		t.Errorf("expected 'Hello world!', got %q", text)
	}
	if len(chunks) != 2 {
		t.Errorf("expected 2 chunks, got %d", len(chunks))
	}
}

func TestStreamWithTools_WithToolCall(t *testing.T) {
	callCount := 0
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		callCount++
		w.Header().Set("Content-Type", "text/event-stream")
		if callCount == 1 {
			// First call: AI wants to call a tool
			fmt.Fprintln(w, `data: {"choices":[{"delta":{"content":"Let me check. "},"finish_reason":null}]}`)
			fmt.Fprintln(w, `data: {"choices":[{"delta":{"tool_calls":[{"index":0,"id":"call_1","type":"function","function":{"name":"web_search","arguments":""}}]},"finish_reason":null}]}`)
			fmt.Fprintln(w, `data: {"choices":[{"delta":{"tool_calls":[{"index":0,"function":{"arguments":"{\"query\":\"test\"}"}}]},"finish_reason":"tool_calls"}]}`)
			fmt.Fprintln(w, `data: [DONE]`)
		} else {
			// Second call: AI responds with the tool result
			fmt.Fprintln(w, `data: {"choices":[{"delta":{"content":"Found some results!"},"finish_reason":"stop"}]}`)
			fmt.Fprintln(w, `data: [DONE]`)
		}
	}))
	defer server.Close()

	c := NewClient("test-key", WithBaseURL(server.URL))
	var events []ToolEvent
	text, err := c.StreamWithTools(
		context.Background(), "test-model",
		[]types.Message{{Role: "user", Content: "search for test"}},
		[]Tool{{Type: "function", Function: ToolFunction{Name: "web_search", Description: "search"}}},
		func(ctx context.Context, name, args string) (string, error) {
			return "Search results here", nil
		},
		func(event ToolEvent) { events = append(events, event) },
		func(chunk string) error { return nil },
		func(chunk string) error { return nil }, // onReasoning
		nil,
	)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if !strings.Contains(text, "Found some results!") {
		t.Errorf("expected final text, got %q", text)
	}
	if callCount != 2 {
		t.Errorf("expected 2 API calls, got %d", callCount)
	}
	if len(events) < 2 {
		t.Fatalf("expected at least 2 events, got %d", len(events))
	}
	if events[0].Type != "tool_start" {
		t.Errorf("expected tool_start, got %s", events[0].Type)
	}
	if events[1].Type != "tool_result" {
		t.Errorf("expected tool_result, got %s", events[1].Type)
	}
}
