package types

import "context"

// Message represents a chat message for LLM calls.
type Message struct {
	Role    string `json:"role"`
	Content string `json:"content"`
}

// StreamFunc is called for each chunk during streaming LLM responses.
type StreamFunc func(chunk string) error

// AIClient abstracts the LLM client for agent testability.
type AIClient interface {
	Complete(ctx context.Context, model string, messages []Message) (string, error)
	Stream(ctx context.Context, model string, messages []Message, fn StreamFunc) (string, error)
}

// SearchResult represents a single web search result.
type SearchResult struct {
	Title       string
	URL         string
	Description string
}

// Searcher abstracts web search for agent testability.
type Searcher interface {
	Search(ctx context.Context, query string, count int) ([]SearchResult, error)
}
