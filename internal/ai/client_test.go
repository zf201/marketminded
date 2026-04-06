package ai

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"

)

func TestComplete(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer test-key" {
			t.Errorf("missing auth header")
		}

		resp := chatResponse{
			Choices: []choice{
				{Message: Message{Role: "assistant", Content: "Hello back!"}},
			},
		}
		json.NewEncoder(w).Encode(resp)
	}))
	defer server.Close()

	c := NewClient("test-key", WithBaseURL(server.URL))
	resp, err := c.Complete(context.Background(), "test-model", []Message{
		{Role: "user", Content: "Hello"},
	})
	if err != nil {
		t.Fatalf("complete: %v", err)
	}
	if resp != "Hello back!" {
		t.Errorf("expected 'Hello back!', got %q", resp)
	}
}

func TestCompleteError(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusTooManyRequests)
		w.Write([]byte("rate limited"))
	}))
	defer server.Close()

	c := NewClient("test-key", WithBaseURL(server.URL))
	_, err := c.Complete(context.Background(), "test-model", []Message{
		{Role: "user", Content: "Hello"},
	})
	if err == nil {
		t.Fatal("expected error")
	}
}

