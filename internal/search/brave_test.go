package search

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/zanfridau/marketminded/internal/types"
)

func TestSearch(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Query().Get("q") != "AI content marketing" {
			t.Errorf("unexpected query: %s", r.URL.Query().Get("q"))
		}
		if r.Header.Get("X-Subscription-Token") != "test-key" {
			t.Error("missing subscription token")
		}
		resp := braveResponse{
			Web: webResults{
				Results: []webResult{
					{Title: "AI Marketing Guide", URL: "https://example.com", Description: "A guide to AI marketing"},
				},
			},
		}
		json.NewEncoder(w).Encode(resp)
	}))
	defer server.Close()

	c := NewBraveClient("test-key", WithBraveBaseURL(server.URL))
	results, err := c.Search(context.Background(), "AI content marketing", 5)
	if err != nil {
		t.Fatalf("search: %v", err)
	}
	if len(results) != 1 {
		t.Fatalf("expected 1 result, got %d", len(results))
	}
	if results[0].Title != "AI Marketing Guide" {
		t.Errorf("unexpected title: %s", results[0].Title)
	}
}

func TestSearchError(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusForbidden)
		w.Write([]byte("invalid key"))
	}))
	defer server.Close()

	c := NewBraveClient("bad-key", WithBraveBaseURL(server.URL))
	_, err := c.Search(context.Background(), "test", 5)
	if err == nil {
		t.Fatal("expected error")
	}
}

// Verify BraveClient implements types.Searcher at compile time
var _ types.Searcher = (*BraveClient)(nil)
