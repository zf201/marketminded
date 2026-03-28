package sse_test

import (
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/zanfridau/marketminded/internal/sse"
)

func TestStream_Send(t *testing.T) {
	w := httptest.NewRecorder()
	s, err := sse.New(w)
	if err != nil {
		t.Fatal(err)
	}

	s.Send("chunk", `{"type":"chunk","chunk":"hello"}`)
	s.Close()

	body := w.Body.String()
	if !strings.Contains(body, `event: chunk`) {
		t.Errorf("expected event line, got: %s", body)
	}
	if !strings.Contains(body, `data: {"type":"chunk","chunk":"hello"}`) {
		t.Errorf("expected data line, got: %s", body)
	}
}

func TestStream_SendJSON(t *testing.T) {
	w := httptest.NewRecorder()
	s, err := sse.New(w)
	if err != nil {
		t.Fatal(err)
	}

	s.SendJSON("tool_start", map[string]string{"tool": "fetch_url"})
	s.Close()

	body := w.Body.String()
	if !strings.Contains(body, `"tool":"fetch_url"`) {
		t.Errorf("expected JSON payload, got: %s", body)
	}
}

func TestStream_Headers(t *testing.T) {
	w := httptest.NewRecorder()
	_, err := sse.New(w)
	if err != nil {
		t.Fatal(err)
	}

	ct := w.Header().Get("Content-Type")
	if ct != "text/event-stream" {
		t.Errorf("expected text/event-stream, got: %s", ct)
	}
	cc := w.Header().Get("Cache-Control")
	if cc != "no-cache" {
		t.Errorf("expected no-cache, got: %s", cc)
	}
}
