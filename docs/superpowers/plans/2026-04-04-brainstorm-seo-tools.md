# Brainstorm SEO Tools Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add three DataForSEO-powered tools (keyword_research, keyword_suggestions, domain_keywords) to the brainstorm agent, plus a settings UI for credentials.

**Architecture:** New `internal/seo` package for the DataForSEO HTTP client. New tool definitions in `internal/tools/seo.go`. Brainstorm handler wires up SEO tools conditionally (only when credentials are configured). Settings UI gets a new "SEO / DataForSEO" card.

**Tech Stack:** Go, net/http, encoding/json, DataForSEO REST API (HTTP Basic Auth)

**Branch:** `feat/brainstorm-seo-tools` (create from main before starting)

**Spec:** `docs/superpowers/specs/2026-04-04-brainstorm-seo-tools-design.md`

---

### Task 0: Create feature branch

- [ ] **Step 1: Create and switch to feature branch**

```bash
git checkout -b feat/brainstorm-seo-tools main
```

---

### Task 1: DataForSEO client — types

Create the request/response types for all three DataForSEO endpoints.

**Files:**
- Create: `internal/seo/types.go`

- [ ] **Step 1: Create `internal/seo/types.go`**

```go
package seo

// --- Clean output types (what tools return to the agent) ---

type KeywordMetric struct {
	Keyword          string  `json:"keyword"`
	SearchVolume     int     `json:"search_volume"`
	CPC              float64 `json:"cpc"`
	Competition      string  `json:"competition"`       // HIGH, MEDIUM, LOW
	CompetitionIndex float64 `json:"competition_index"` // 0-100
}

type KeywordSuggestion struct {
	Keyword           string  `json:"keyword"`
	SearchVolume      int     `json:"search_volume"`
	KeywordDifficulty float64 `json:"keyword_difficulty"` // 0-100
	CPC               float64 `json:"cpc"`
	Competition       string  `json:"competition"`
}

type RankedKeyword struct {
	Keyword           string  `json:"keyword"`
	Position          int     `json:"position"`
	SearchVolume      int     `json:"search_volume"`
	KeywordDifficulty float64 `json:"keyword_difficulty"`
	CPC               float64 `json:"cpc"`
	URL               string  `json:"url"`
}

// --- DataForSEO API request/response wrappers ---

// Shared envelope for all DataForSEO responses
type apiResponse struct {
	StatusCode    int       `json:"status_code"`
	StatusMessage string    `json:"status_message"`
	Tasks         []apiTask `json:"tasks"`
}

type apiTask struct {
	StatusCode    int             `json:"status_code"`
	StatusMessage string          `json:"status_message"`
	Result        []apiTaskResult `json:"result"`
}

type apiTaskResult struct {
	Items []apiItem `json:"items"`
}

// Search Volume endpoint items
type apiItem struct {
	// search_volume/live fields
	Keyword          string          `json:"keyword,omitempty"`
	SearchVolume     int             `json:"search_volume"`
	CPC              float64         `json:"cpc"`
	Competition      *float64        `json:"competition,omitempty"`
	CompetitionLevel string          `json:"competition_level,omitempty"`
	CompetitionIndex *float64        `json:"competition_index,omitempty"`
	// related_keywords/live + keyword_suggestions/live fields
	KeywordData      *apiKeywordData `json:"keyword_data,omitempty"`
	// ranked_keywords/live fields
	RankedSerpElement *apiRankedSERP `json:"ranked_serp_element,omitempty"`
}

type apiKeywordData struct {
	Keyword     string          `json:"keyword"`
	KeywordInfo apiKeywordInfo  `json:"keyword_info"`
	KeywordProperties apiKeywordProps `json:"keyword_properties,omitempty"`
}

type apiKeywordInfo struct {
	SearchVolume      int     `json:"search_volume"`
	CPC               float64 `json:"cpc"`
	Competition       float64 `json:"competition"`
	CompetitionLevel  string  `json:"competition_level"`
	KeywordDifficulty float64 `json:"keyword_difficulty"`
}

type apiKeywordProps struct {
	KeywordDifficulty float64 `json:"keyword_difficulty"`
}

type apiRankedSERP struct {
	SERPItem apiSERPItem `json:"serp_item"`
}

type apiSERPItem struct {
	Type        string `json:"type"`
	RankGroup   int    `json:"rank_group"`
	RankAbsolute int   `json:"rank_absolute"`
	Position    string `json:"position"`
	URL         string `json:"url"`
	KeywordData *apiKeywordData `json:"keyword_data,omitempty"`
}
```

- [ ] **Step 2: Verify it compiles**

```bash
cd /Users/zanfridau/CODE/AI/marketminded && go build ./internal/seo/...
```

Expected: Success (no output)

- [ ] **Step 3: Commit**

```bash
git add internal/seo/types.go
git commit -m "feat(seo): add DataForSEO request/response types"
```

---

### Task 2: DataForSEO client — HTTP client

Create the HTTP client that talks to the DataForSEO API.

**Files:**
- Create: `internal/seo/client.go`

- [ ] **Step 1: Create `internal/seo/client.go`**

```go
package seo

import (
	"bytes"
	"context"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"time"
)

const baseURL = "https://api.dataforseo.com/v3"

// CredentialFunc returns (login, password). Resolved at call time so DB
// settings changes take effect without restart.
type CredentialFunc func() (login, password string)

type Client struct {
	creds      CredentialFunc
	httpClient *http.Client
}

func NewClient(creds CredentialFunc) *Client {
	return &Client{
		creds:      creds,
		httpClient: &http.Client{Timeout: 30 * time.Second},
	}
}

// HasCredentials returns true if both login and password are non-empty.
func (c *Client) HasCredentials() bool {
	login, password := c.creds()
	return login != "" && password != ""
}

// SearchVolume returns keyword metrics for up to 5 keywords.
func (c *Client) SearchVolume(ctx context.Context, keywords []string, location string) ([]KeywordMetric, error) {
	if len(keywords) > 5 {
		keywords = keywords[:5]
	}
	if location == "" {
		location = "United States"
	}

	body := []map[string]any{{
		"keywords":      keywords,
		"location_name": location,
		"language_name": "English",
	}}

	var resp apiResponse
	if err := c.post(ctx, "/keywords_data/google_ads/search_volume/live", body, &resp); err != nil {
		return nil, err
	}

	var metrics []KeywordMetric
	for _, task := range resp.Tasks {
		for _, result := range task.Result {
			for _, item := range result.Items {
				comp := "LOW"
				if item.CompetitionLevel != "" {
					comp = item.CompetitionLevel
				}
				ci := 0.0
				if item.CompetitionIndex != nil {
					ci = *item.CompetitionIndex
				}
				metrics = append(metrics, KeywordMetric{
					Keyword:          item.Keyword,
					SearchVolume:     item.SearchVolume,
					CPC:              item.CPC,
					Competition:      comp,
					CompetitionIndex: ci,
				})
			}
		}
	}
	return metrics, nil
}

// RelatedKeywords returns keyword suggestions for a seed keyword.
func (c *Client) RelatedKeywords(ctx context.Context, seed string, location string) ([]KeywordSuggestion, error) {
	if location == "" {
		location = "United States"
	}

	body := []map[string]any{{
		"keyword":                seed,
		"location_name":          location,
		"language_name":          "English",
		"depth":                  1,
		"limit":                  10,
		"include_serp_info":      false,
		"include_clickstream_data": false,
	}}

	var resp apiResponse
	if err := c.post(ctx, "/dataforseo_labs/google/related_keywords/live", body, &resp); err != nil {
		return nil, err
	}

	var suggestions []KeywordSuggestion
	for _, task := range resp.Tasks {
		for _, result := range task.Result {
			for _, item := range result.Items {
				if item.KeywordData == nil {
					continue
				}
				kd := item.KeywordData
				suggestions = append(suggestions, KeywordSuggestion{
					Keyword:           kd.Keyword,
					SearchVolume:      kd.KeywordInfo.SearchVolume,
					KeywordDifficulty: kd.KeywordInfo.KeywordDifficulty,
					CPC:               kd.KeywordInfo.CPC,
					Competition:       kd.KeywordInfo.CompetitionLevel,
				})
			}
		}
	}
	return suggestions, nil
}

// RankedKeywords returns the top keywords a domain ranks for.
func (c *Client) RankedKeywords(ctx context.Context, domain string, location string) ([]RankedKeyword, error) {
	if location == "" {
		location = "United States"
	}

	body := []map[string]any{{
		"target":                   domain,
		"location_name":            location,
		"language_name":            "English",
		"limit":                    10,
		"include_clickstream_data": false,
		"item_types":               []string{"organic"},
		"order_by":                 []string{"keyword_data.keyword_info.search_volume,desc"},
	}}

	var resp apiResponse
	if err := c.post(ctx, "/dataforseo_labs/google/ranked_keywords/live", body, &resp); err != nil {
		return nil, err
	}

	var ranked []RankedKeyword
	for _, task := range resp.Tasks {
		for _, result := range task.Result {
			for _, item := range result.Items {
				if item.RankedSerpElement == nil || item.RankedSerpElement.SERPItem.KeywordData == nil {
					continue
				}
				se := item.RankedSerpElement.SERPItem
				kd := se.KeywordData
				ranked = append(ranked, RankedKeyword{
					Keyword:           kd.Keyword,
					Position:          se.RankGroup,
					SearchVolume:      kd.KeywordInfo.SearchVolume,
					KeywordDifficulty: kd.KeywordInfo.KeywordDifficulty,
					CPC:               kd.KeywordInfo.CPC,
					URL:               se.URL,
				})
			}
		}
	}
	return ranked, nil
}

// post sends a POST request to the DataForSEO API with Basic Auth.
func (c *Client) post(ctx context.Context, path string, payload any, out *apiResponse) error {
	login, password := c.creds()
	if login == "" || password == "" {
		return fmt.Errorf("DataForSEO credentials not configured")
	}

	jsonBody, err := json.Marshal(payload)
	if err != nil {
		return fmt.Errorf("marshal request: %w", err)
	}

	req, err := http.NewRequestWithContext(ctx, "POST", baseURL+path, bytes.NewReader(jsonBody))
	if err != nil {
		return fmt.Errorf("create request: %w", err)
	}

	req.Header.Set("Content-Type", "application/json")
	auth := base64.StdEncoding.EncodeToString([]byte(login + ":" + password))
	req.Header.Set("Authorization", "Basic "+auth)

	resp, err := c.httpClient.Do(req)
	if err != nil {
		return fmt.Errorf("DataForSEO request failed: %w", err)
	}
	defer resp.Body.Close()

	body, err := io.ReadAll(io.LimitReader(resp.Body, 1<<20)) // 1MB limit
	if err != nil {
		return fmt.Errorf("read response: %w", err)
	}

	if err := json.Unmarshal(body, out); err != nil {
		return fmt.Errorf("decode response: %w", err)
	}

	if out.StatusCode != 20000 {
		return fmt.Errorf("DataForSEO error %d: %s", out.StatusCode, out.StatusMessage)
	}

	for _, task := range out.Tasks {
		if task.StatusCode != 20000 {
			return fmt.Errorf("DataForSEO task error %d: %s", task.StatusCode, task.StatusMessage)
		}
	}

	return nil
}
```

- [ ] **Step 2: Verify it compiles**

```bash
cd /Users/zanfridau/CODE/AI/marketminded && go build ./internal/seo/...
```

Expected: Success

- [ ] **Step 3: Commit**

```bash
git add internal/seo/client.go
git commit -m "feat(seo): add DataForSEO HTTP client with three endpoints"
```

---

### Task 3: DataForSEO client — unit tests

**Files:**
- Create: `internal/seo/client_test.go`

- [ ] **Step 1: Write tests with a mock HTTP server**

```go
package seo

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
)

func TestSearchVolume(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		// Verify Basic Auth is present
		if r.Header.Get("Authorization") == "" {
			t.Error("expected Authorization header")
		}
		if r.URL.Path != "/v3/keywords_data/google_ads/search_volume/live" {
			t.Errorf("unexpected path: %s", r.URL.Path)
		}

		json.NewEncoder(w).Encode(apiResponse{
			StatusCode:    20000,
			StatusMessage: "Ok.",
			Tasks: []apiTask{{
				StatusCode:    20000,
				StatusMessage: "Ok.",
				Result: []apiTaskResult{{
					Items: []apiItem{
						{
							Keyword:          "test keyword",
							SearchVolume:     1200,
							CPC:              1.5,
							CompetitionLevel: "MEDIUM",
							CompetitionIndex: ptrFloat(45.0),
						},
					},
				}},
			}},
		})
	}))
	defer srv.Close()

	client := newTestClient(srv.URL, "login", "pass")
	metrics, err := client.SearchVolume(context.Background(), []string{"test keyword"}, "")
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(metrics) != 1 {
		t.Fatalf("expected 1 metric, got %d", len(metrics))
	}
	m := metrics[0]
	if m.Keyword != "test keyword" {
		t.Errorf("expected keyword 'test keyword', got %q", m.Keyword)
	}
	if m.SearchVolume != 1200 {
		t.Errorf("expected search volume 1200, got %d", m.SearchVolume)
	}
	if m.Competition != "MEDIUM" {
		t.Errorf("expected competition MEDIUM, got %s", m.Competition)
	}
}

func TestSearchVolumeMaxFiveKeywords(t *testing.T) {
	var receivedKeywords []string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var body []map[string]any
		json.NewDecoder(r.Body).Decode(&body)
		if kws, ok := body[0]["keywords"].([]any); ok {
			for _, k := range kws {
				receivedKeywords = append(receivedKeywords, k.(string))
			}
		}
		json.NewEncoder(w).Encode(apiResponse{
			StatusCode: 20000, StatusMessage: "Ok.",
			Tasks: []apiTask{{StatusCode: 20000, StatusMessage: "Ok.", Result: []apiTaskResult{{Items: []apiItem{}}}}},
		})
	}))
	defer srv.Close()

	client := newTestClient(srv.URL, "login", "pass")
	client.SearchVolume(context.Background(), []string{"a", "b", "c", "d", "e", "f", "g"}, "")
	if len(receivedKeywords) != 5 {
		t.Errorf("expected 5 keywords sent, got %d", len(receivedKeywords))
	}
}

func TestRelatedKeywords(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/v3/dataforseo_labs/google/related_keywords/live" {
			t.Errorf("unexpected path: %s", r.URL.Path)
		}
		json.NewEncoder(w).Encode(apiResponse{
			StatusCode: 20000, StatusMessage: "Ok.",
			Tasks: []apiTask{{
				StatusCode: 20000, StatusMessage: "Ok.",
				Result: []apiTaskResult{{
					Items: []apiItem{{
						KeywordData: &apiKeywordData{
							Keyword: "related term",
							KeywordInfo: apiKeywordInfo{
								SearchVolume:      800,
								CPC:               0.9,
								CompetitionLevel:  "LOW",
								KeywordDifficulty: 25,
							},
						},
					}},
				}},
			}},
		})
	}))
	defer srv.Close()

	client := newTestClient(srv.URL, "login", "pass")
	suggestions, err := client.RelatedKeywords(context.Background(), "test", "")
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(suggestions) != 1 {
		t.Fatalf("expected 1 suggestion, got %d", len(suggestions))
	}
	if suggestions[0].KeywordDifficulty != 25 {
		t.Errorf("expected difficulty 25, got %f", suggestions[0].KeywordDifficulty)
	}
}

func TestRankedKeywords(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/v3/dataforseo_labs/google/ranked_keywords/live" {
			t.Errorf("unexpected path: %s", r.URL.Path)
		}
		json.NewEncoder(w).Encode(apiResponse{
			StatusCode: 20000, StatusMessage: "Ok.",
			Tasks: []apiTask{{
				StatusCode: 20000, StatusMessage: "Ok.",
				Result: []apiTaskResult{{
					Items: []apiItem{{
						RankedSerpElement: &apiRankedSERP{
							SERPItem: apiSERPItem{
								RankGroup: 3,
								URL:       "https://example.com/page",
								KeywordData: &apiKeywordData{
									Keyword: "example keyword",
									KeywordInfo: apiKeywordInfo{
										SearchVolume:      5000,
										CPC:               2.1,
										KeywordDifficulty: 60,
									},
								},
							},
						},
					}},
				}},
			}},
		})
	}))
	defer srv.Close()

	client := newTestClient(srv.URL, "login", "pass")
	ranked, err := client.RankedKeywords(context.Background(), "example.com", "")
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(ranked) != 1 {
		t.Fatalf("expected 1 ranked keyword, got %d", len(ranked))
	}
	if ranked[0].Position != 3 {
		t.Errorf("expected position 3, got %d", ranked[0].Position)
	}
	if ranked[0].URL != "https://example.com/page" {
		t.Errorf("unexpected URL: %s", ranked[0].URL)
	}
}

func TestAPIError(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		json.NewEncoder(w).Encode(apiResponse{
			StatusCode:    40000,
			StatusMessage: "You have exceeded the rate limit.",
		})
	}))
	defer srv.Close()

	client := newTestClient(srv.URL, "login", "pass")
	_, err := client.SearchVolume(context.Background(), []string{"test"}, "")
	if err == nil {
		t.Fatal("expected error for non-20000 status")
	}
}

func TestNoCredentials(t *testing.T) {
	client := NewClient(func() (string, string) { return "", "" })
	_, err := client.SearchVolume(context.Background(), []string{"test"}, "")
	if err == nil {
		t.Fatal("expected error when credentials are empty")
	}
	if !client.HasCredentials() == true {
		// HasCredentials should return false
	}
	if client.HasCredentials() {
		t.Error("HasCredentials should return false with empty creds")
	}
}

// --- helpers ---

func newTestClient(serverURL, login, password string) *Client {
	c := NewClient(func() (string, string) { return login, password })
	// Override the base URL to point to our test server
	// We need to temporarily swap baseURL — but since it's a const,
	// we'll use a different approach: override the httpClient transport
	// Actually, let's just make the client's post method configurable for tests.
	// Simpler: override via a package-level var.
	return c
}

func ptrFloat(f float64) *float64 {
	return &f
}
```

Wait — `baseURL` is a `const`, so the test client can't override it. We need to make it configurable on the Client struct.

- [ ] **Step 2: Update `internal/seo/client.go` — make base URL configurable for testing**

In `client.go`, change the `Client` struct and `NewClient` to support an overridable base URL:

Replace in `internal/seo/client.go`:

```go
type Client struct {
	creds      CredentialFunc
	httpClient *http.Client
}

func NewClient(creds CredentialFunc) *Client {
	return &Client{
		creds:      creds,
		httpClient: &http.Client{Timeout: 30 * time.Second},
	}
}
```

With:

```go
type Client struct {
	creds      CredentialFunc
	httpClient *http.Client
	baseURL    string
}

func NewClient(creds CredentialFunc) *Client {
	return &Client{
		creds:      creds,
		httpClient: &http.Client{Timeout: 30 * time.Second},
		baseURL:    baseURL,
	}
}
```

And in the `post` method, replace `baseURL+path` with `c.baseURL+path`.

- [ ] **Step 3: Fix the test helper**

Replace the `newTestClient` function in the test file:

```go
func newTestClient(serverURL, login, password string) *Client {
	c := NewClient(func() (string, string) { return login, password })
	c.baseURL = serverURL + "/v3"
	return c
}
```

- [ ] **Step 4: Run tests**

```bash
cd /Users/zanfridau/CODE/AI/marketminded && go test ./internal/seo/... -v
```

Expected: All tests pass.

- [ ] **Step 5: Commit**

```bash
git add internal/seo/client.go internal/seo/client_test.go
git commit -m "test(seo): add unit tests for DataForSEO client"
```

---

### Task 4: SEO tool definitions and executors

Create the tool definitions (JSON schema for the LLM) and executors (functions that call the seo client and format results).

**Files:**
- Create: `internal/tools/seo.go`

- [ ] **Step 1: Create `internal/tools/seo.go`**

```go
package tools

import (
	"context"
	"encoding/json"
	"fmt"
	"strings"

	"github.com/zanfridau/marketminded/internal/ai"
	"github.com/zanfridau/marketminded/internal/seo"
)

func NewKeywordResearchTool() ai.Tool {
	return ai.Tool{
		Type: "function",
		Function: ai.ToolFunction{
			Name:        "keyword_research",
			Description: "Look up SEO metrics for specific keywords: monthly search volume, CPC, competition level. Use this to validate whether a topic has real search demand. Max 5 keywords per call. COSTS MONEY — use sparingly.",
			Parameters: json.RawMessage(`{
				"type": "object",
				"properties": {
					"keywords": {
						"type": "array",
						"items": {"type": "string"},
						"maxItems": 5,
						"description": "Keywords to look up (max 5 per call)"
					},
					"location": {
						"type": "string",
						"description": "Target country, e.g. 'United States'. Defaults to United States."
					}
				},
				"required": ["keywords"]
			}`),
		},
	}
}

func NewKeywordSuggestionsTool() ai.Tool {
	return ai.Tool{
		Type: "function",
		Function: ai.ToolFunction{
			Name:        "keyword_suggestions",
			Description: "Discover related keywords from a seed keyword. Returns up to 10 related terms with search volume and difficulty. Use this to expand a topic into specific keyword opportunities. COSTS MONEY — use sparingly.",
			Parameters: json.RawMessage(`{
				"type": "object",
				"properties": {
					"seed_keyword": {
						"type": "string",
						"description": "The seed keyword to find related terms for"
					},
					"location": {
						"type": "string",
						"description": "Target country. Defaults to United States."
					}
				},
				"required": ["seed_keyword"]
			}`),
		},
	}
}

func NewDomainKeywordsTool() ai.Tool {
	return ai.Tool{
		Type: "function",
		Function: ai.ToolFunction{
			Name:        "domain_keywords",
			Description: "See the top 10 keywords a domain ranks for in organic search. Use this for competitive analysis — find what topics drive traffic to a competitor. COSTS MONEY — use sparingly.",
			Parameters: json.RawMessage(`{
				"type": "object",
				"properties": {
					"domain": {
						"type": "string",
						"description": "The domain to analyze, e.g. 'competitor.com' (no https://)"
					},
					"location": {
						"type": "string",
						"description": "Target country. Defaults to United States."
					}
				},
				"required": ["domain"]
			}`),
		},
	}
}

// NewSEOExecutor returns an executor function that handles all three SEO tools.
func NewSEOExecutor(client *seo.Client) func(ctx context.Context, name, argsJSON string) (string, error) {
	return func(ctx context.Context, name, argsJSON string) (string, error) {
		switch name {
		case "keyword_research":
			return execKeywordResearch(ctx, client, argsJSON)
		case "keyword_suggestions":
			return execKeywordSuggestions(ctx, client, argsJSON)
		case "domain_keywords":
			return execDomainKeywords(ctx, client, argsJSON)
		default:
			return "", fmt.Errorf("unknown SEO tool: %s", name)
		}
	}
}

func execKeywordResearch(ctx context.Context, client *seo.Client, argsJSON string) (string, error) {
	var args struct {
		Keywords []string `json:"keywords"`
		Location string   `json:"location"`
	}
	if err := json.Unmarshal([]byte(argsJSON), &args); err != nil {
		return "", fmt.Errorf("invalid arguments: %w", err)
	}
	if len(args.Keywords) == 0 {
		return "", fmt.Errorf("keywords array is required")
	}

	metrics, err := client.SearchVolume(ctx, args.Keywords, args.Location)
	if err != nil {
		return "", err
	}

	if len(metrics) == 0 {
		return "No data found for the given keywords.", nil
	}

	var b strings.Builder
	b.WriteString("Keyword Metrics:\n\n")
	for _, m := range metrics {
		fmt.Fprintf(&b, "- %s: %d searches/mo, $%.2f CPC, competition: %s (index: %.0f)\n",
			m.Keyword, m.SearchVolume, m.CPC, m.Competition, m.CompetitionIndex)
	}
	return b.String(), nil
}

func execKeywordSuggestions(ctx context.Context, client *seo.Client, argsJSON string) (string, error) {
	var args struct {
		SeedKeyword string `json:"seed_keyword"`
		Location    string `json:"location"`
	}
	if err := json.Unmarshal([]byte(argsJSON), &args); err != nil {
		return "", fmt.Errorf("invalid arguments: %w", err)
	}
	if args.SeedKeyword == "" {
		return "", fmt.Errorf("seed_keyword is required")
	}

	suggestions, err := client.RelatedKeywords(ctx, args.SeedKeyword, args.Location)
	if err != nil {
		return "", err
	}

	if len(suggestions) == 0 {
		return "No related keywords found for this seed.", nil
	}

	var b strings.Builder
	b.WriteString("Related Keywords:\n\n")
	for _, s := range suggestions {
		fmt.Fprintf(&b, "- %s: %d searches/mo, difficulty: %.0f/100, $%.2f CPC, competition: %s\n",
			s.Keyword, s.SearchVolume, s.KeywordDifficulty, s.CPC, s.Competition)
	}
	return b.String(), nil
}

func execDomainKeywords(ctx context.Context, client *seo.Client, argsJSON string) (string, error) {
	var args struct {
		Domain   string `json:"domain"`
		Location string `json:"location"`
	}
	if err := json.Unmarshal([]byte(argsJSON), &args); err != nil {
		return "", fmt.Errorf("invalid arguments: %w", err)
	}
	if args.Domain == "" {
		return "", fmt.Errorf("domain is required")
	}

	ranked, err := client.RankedKeywords(ctx, args.Domain, args.Location)
	if err != nil {
		return "", err
	}

	if len(ranked) == 0 {
		return "No ranking data found for this domain.", nil
	}

	var b strings.Builder
	fmt.Fprintf(&b, "Top keywords for %s:\n\n", args.Domain)
	for _, r := range ranked {
		fmt.Fprintf(&b, "- %s: position #%d, %d searches/mo, difficulty: %.0f/100, $%.2f CPC\n  URL: %s\n",
			r.Keyword, r.Position, r.SearchVolume, r.KeywordDifficulty, r.CPC, r.URL)
	}
	return b.String(), nil
}

// SEO tool summaries for the streaming UI

func SEOToolSummary(name, argsJSON string) string {
	switch name {
	case "keyword_research":
		var args struct{ Keywords []string `json:"keywords"` }
		json.Unmarshal([]byte(argsJSON), &args)
		if len(args.Keywords) > 0 {
			return fmt.Sprintf("Looking up: %s", strings.Join(args.Keywords, ", "))
		}
		return "Looking up keywords..."
	case "keyword_suggestions":
		var args struct{ SeedKeyword string `json:"seed_keyword"` }
		json.Unmarshal([]byte(argsJSON), &args)
		if args.SeedKeyword != "" {
			return fmt.Sprintf("Finding related keywords for: %s", args.SeedKeyword)
		}
		return "Finding related keywords..."
	case "domain_keywords":
		var args struct{ Domain string `json:"domain"` }
		json.Unmarshal([]byte(argsJSON), &args)
		if args.Domain != "" {
			return fmt.Sprintf("Analyzing domain: %s", args.Domain)
		}
		return "Analyzing domain..."
	default:
		return "SEO lookup..."
	}
}
```

- [ ] **Step 2: Verify it compiles**

```bash
cd /Users/zanfridau/CODE/AI/marketminded && go build ./internal/tools/...
```

Expected: Success

- [ ] **Step 3: Commit**

```bash
git add internal/tools/seo.go
git commit -m "feat(tools): add SEO tool definitions and executors for brainstorm"
```

---

### Task 5: Config + Settings UI for DataForSEO credentials

Add DataForSEO login/password to config, settings data struct, settings handler, and settings template.

**Files:**
- Modify: `internal/config/config.go`
- Modify: `web/templates/settings.templ`
- Modify: `web/handlers/settings.go`

- [ ] **Step 1: Add DataForSEO fields to config**

In `internal/config/config.go`, add to the `Config` struct:

```go
DataForSEOLogin    string
DataForSEOPassword string
```

And in the `Load()` return, add:

```go
DataForSEOLogin:    os.Getenv("DATAFORSEO_LOGIN"),
DataForSEOPassword: os.Getenv("DATAFORSEO_PASSWORD"),
```

These are optional — no error if empty.

- [ ] **Step 2: Add fields to SettingsData**

In `web/templates/settings.templ`, add to `SettingsData`:

```go
DataForSEOLogin    string
DataForSEOPassword string
```

- [ ] **Step 3: Add DataForSEO card to settings template**

In `web/templates/settings.templ`, add a new card after the AI Models card (but still inside the `<form>`), before the closing `</form>`:

```templ
@components.Card("SEO / DataForSEO") {
	<p class="text-zinc-500 text-xs mb-4">Connect DataForSEO to enable keyword research tools in brainstorming. Leave blank to disable SEO tools.</p>
	@components.FormGroup("Login (email)") {
		<input type="text" id="dataforseo_login" name="dataforseo_login" value={ data.DataForSEOLogin } placeholder="your@email.com" class="input" autocomplete="off"/>
	}
	@components.FormGroup("API Password") {
		<input type="password" id="dataforseo_password" name="dataforseo_password" value={ data.DataForSEOPassword } placeholder="API password" class="input" autocomplete="off"/>
	}
	<p class="text-zinc-600 text-xs mt-2">
		Get credentials at <a href="https://app.dataforseo.com/api-access" class="text-blue-400 hover:underline" target="_blank">dataforseo.com</a>. The brainstorm agent will use these for keyword research, suggestions, and competitor analysis.
	</p>
}
```

Move the submit button outside both cards so it saves everything:

The submit button should be after both `@components.Card` blocks but inside the `<form>`. Currently it's inside the AI Models card. Move it out:

Remove the `<div class="mt-4">@components.SubmitButton("Save Settings")</div>` from inside the AI Models card and add it after the last card but still inside the form:

```templ
<div class="mt-6">
	@components.SubmitButton("Save Settings")
</div>
```

- [ ] **Step 4: Update settings handler to save/load DataForSEO credentials**

In `web/handlers/settings.go`, update `show()` to include:

```go
DataForSEOLogin:    settings["dataforseo_login"],
DataForSEOPassword: settings["dataforseo_password"],
```

And in `save()`, add:

```go
h.queries.SetSetting("dataforseo_login", r.FormValue("dataforseo_login"))
h.queries.SetSetting("dataforseo_password", r.FormValue("dataforseo_password"))
```

- [ ] **Step 5: Generate templ code**

```bash
cd /Users/zanfridau/CODE/AI/marketminded && templ generate
```

Expected: Success, `settings_templ.go` gets updated.

- [ ] **Step 6: Verify it compiles**

```bash
cd /Users/zanfridau/CODE/AI/marketminded && go build ./...
```

Expected: Success

- [ ] **Step 7: Commit**

```bash
git add internal/config/config.go web/templates/settings.templ web/templates/settings_templ.go web/handlers/settings.go
git commit -m "feat: add DataForSEO credentials to settings UI"
```

---

### Task 6: Wire SEO tools into brainstorm handler

Connect everything: create the SEO client in main.go, pass it to the brainstorm handler, register tools conditionally, update the system prompt.

**Files:**
- Modify: `cmd/server/main.go`
- Modify: `web/handlers/brainstorm.go`

- [ ] **Step 1: Create SEO credential resolver and client in main.go**

In `cmd/server/main.go`, after the `ideationModel` resolver (around line 57), add:

```go
// SEO client (optional — credentials from DB settings or env vars)
seoCredentials := func() (string, string) {
	login, lerr := queries.GetSetting("dataforseo_login")
	password, perr := queries.GetSetting("dataforseo_password")
	if lerr == nil && login != "" && perr == nil && password != "" {
		return login, password
	}
	return cfg.DataForSEOLogin, cfg.DataForSEOPassword
}
seoClient := seo.NewClient(seoCredentials)
```

Add import: `"github.com/zanfridau/marketminded/internal/seo"`

Update the brainstorm handler constructor call:

```go
brainstormHandler := handlers.NewBrainstormHandler(queries, aiClient, braveClient, seoClient, ideationModel)
```

- [ ] **Step 2: Update BrainstormHandler to accept and use SEO client**

In `web/handlers/brainstorm.go`:

Add import: `"github.com/zanfridau/marketminded/internal/seo"`

Update the struct:

```go
type BrainstormHandler struct {
	queries     *store.Queries
	aiClient    *ai.Client
	braveClient *search.BraveClient
	seoClient   *seo.Client
	model       func() string
}
```

Update the constructor:

```go
func NewBrainstormHandler(q *store.Queries, aiClient *ai.Client, braveClient *search.BraveClient, seoClient *seo.Client, model func() string) *BrainstormHandler {
	return &BrainstormHandler{queries: q, aiClient: aiClient, braveClient: braveClient, seoClient: seoClient, model: model}
}
```

- [ ] **Step 3: Add SEO tools to the tool list conditionally**

In `brainstorm.go` in the `streamResponse` method, replace the tool list and executor section (lines 212-231):

```go
// Build tools (fetch + search always available)
toolList := []ai.Tool{
	tools.NewFetchTool(),
	tools.NewSearchTool(),
}

// Add SEO tools if DataForSEO credentials are configured
hasSEO := h.seoClient != nil && h.seoClient.HasCredentials()
if hasSEO {
	toolList = append(toolList,
		tools.NewKeywordResearchTool(),
		tools.NewKeywordSuggestionsTool(),
		tools.NewDomainKeywordsTool(),
	)
}

// Create executors
searchExec := tools.NewSearchExecutor(h.braveClient)
var seoExec func(ctx context.Context, name, argsJSON string) (string, error)
if hasSEO {
	seoExec = tools.NewSEOExecutor(h.seoClient)
}

// Executor switch
executor := func(ctx context.Context, name, args string) (string, error) {
	switch name {
	case "fetch_url":
		return tools.ExecuteFetch(ctx, args)
	case "web_search":
		return searchExec(ctx, args)
	case "keyword_research", "keyword_suggestions", "domain_keywords":
		if seoExec != nil {
			return seoExec(ctx, name, args)
		}
		return "", fmt.Errorf("SEO tools not available: DataForSEO credentials not configured")
	default:
		return "", fmt.Errorf("unknown tool: %s", name)
	}
}
```

- [ ] **Step 4: Add SEO tool summaries to the tool event callback**

In the `onToolEvent` callback in `streamResponse`, add SEO tool cases to the `tool_start` switch:

```go
case "tool_start":
	summary := ""
	switch event.Tool {
	case "fetch_url":
		summary = tools.FetchSummary(event.Args)
	case "web_search":
		summary = tools.SearchSummary(event.Args)
	case "keyword_research", "keyword_suggestions", "domain_keywords":
		summary = tools.SEOToolSummary(event.Tool, event.Args)
	}
	sendEvent(map[string]string{"type": "tool_start", "tool": event.Tool, "summary": summary})
```

- [ ] **Step 5: Add SEO guardrail text to the system prompt**

In `streamResponse`, after the existing system prompt string (after the `WRITING STYLE:` section, around line 188), append SEO instructions if SEO tools are available. After `systemPrompt` is built, add:

```go
if hasSEO {
	systemPrompt += `

## SEO Tools

You have access to SEO research tools powered by DataForSEO. These cost real money per call.

RULES:
- You have a budget of MAX 5 SEO tool calls per session. Use them wisely.
- Batch keywords: use keyword_research with up to 5 keywords at once instead of separate calls.
- Use your existing knowledge first. Only call SEO tools to validate or discover specific data.
- Don't call keyword_suggestions repeatedly for similar seeds.
- Present SEO data concisely — highlight the most actionable insights, not raw data dumps.
- When suggesting topics, mention search volume and difficulty to help the user prioritize.`
}
```

- [ ] **Step 6: Verify it compiles**

```bash
cd /Users/zanfridau/CODE/AI/marketminded && go build ./...
```

Expected: Success

- [ ] **Step 7: Commit**

```bash
git add cmd/server/main.go web/handlers/brainstorm.go
git commit -m "feat: wire SEO tools into brainstorm agent with cost guardrails"
```

---

### Task 7: Manual E2E test

Verify the full flow works end-to-end.

- [ ] **Step 1: Set up credentials**

Ensure DataForSEO credentials are available either via:
- Settings UI at `/settings` (preferred)
- Or environment variables `DATAFORSEO_LOGIN` and `DATAFORSEO_PASSWORD`

- [ ] **Step 2: Start the server**

```bash
make start
```

- [ ] **Step 3: Test via Settings UI**

1. Go to `/settings`
2. Verify the new "SEO / DataForSEO" card appears with login and password fields
3. Enter credentials and save
4. Verify "Settings saved." confirmation appears
5. Reload and verify credentials persist

- [ ] **Step 4: Test via Brainstorm**

1. Open a project and start a new brainstorm chat
2. Ask: "What are some high-traffic keyword opportunities around content marketing for SaaS companies?"
3. Verify the agent calls `keyword_research` or `keyword_suggestions` tools
4. Verify tool start/result events appear in the streaming UI
5. Verify the response includes real search volume/difficulty data

- [ ] **Step 5: Test competitor analysis**

1. In the same or new brainstorm chat, ask: "What keywords does hubspot.com rank for?"
2. Verify the agent calls `domain_keywords`
3. Verify results show real ranking data

- [ ] **Step 6: Test without credentials**

1. Clear DataForSEO credentials from Settings
2. Start a new brainstorm session
3. Verify the agent works normally without SEO tools (no errors, just no SEO data)

- [ ] **Step 7: Commit any fixes if needed**
