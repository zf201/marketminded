package agents

import (
	"context"

	"github.com/zanfridau/marketminded/internal/types"
)

type mockAI struct {
	response string
}

func (m *mockAI) Complete(ctx context.Context, model string, msgs []types.Message) (string, error) {
	return m.response, nil
}

func (m *mockAI) Stream(ctx context.Context, model string, msgs []types.Message, fn types.StreamFunc) (string, error) {
	fn(m.response)
	return m.response, nil
}

func testModel() string { return "test-model" }

type mockSearcher struct {
	results []types.SearchResult
}

func (m *mockSearcher) Search(ctx context.Context, query string, count int) ([]types.SearchResult, error) {
	return m.results, nil
}
