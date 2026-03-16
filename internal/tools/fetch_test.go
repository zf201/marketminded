package tools

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestExecuteFetch(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("User-Agent") == "" {
			t.Error("missing user agent")
		}
		w.Header().Set("Content-Type", "text/html")
		w.Write([]byte(`<html><head><title>Test Page</title></head><body><nav>nav</nav><main><p>Hello world</p><p>Content here</p></main><script>bad</script></body></html>`))
	}))
	defer server.Close()

	args, _ := json.Marshal(fetchArgs{URL: server.URL})
	result, err := ExecuteFetch(context.Background(), string(args))
	if err != nil {
		t.Fatalf("fetch: %v", err)
	}
	if !strings.Contains(result, "Test Page") {
		t.Errorf("expected title, got: %s", result)
	}
	if !strings.Contains(result, "Hello world") {
		t.Errorf("expected content, got: %s", result)
	}
	if strings.Contains(result, "bad") {
		t.Error("script content should be removed")
	}
}

func TestFetchSummary(t *testing.T) {
	args, _ := json.Marshal(fetchArgs{URL: "https://example.com/page"})
	summary := FetchSummary(string(args))
	if summary != "Fetching: example.com" {
		t.Errorf("unexpected summary: %s", summary)
	}
}
