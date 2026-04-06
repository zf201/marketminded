package seo

import (
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"testing"
)

func newTestClient(serverURL, login, password string) *Client {
	c := NewClient(func() (string, string) { return login, password })
	c.baseURL = serverURL + "/v3"
	return c
}

func ptrFloat(f float64) *float64 {
	return &f
}

func TestSearchVolume(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		resp := apiResponse{
			StatusCode:    20000,
			StatusMessage: "Ok.",
			Tasks: []apiTask{{
				StatusCode:    20000,
				StatusMessage: "Ok.",
				Result: []apiTaskResult{{
					Items: []apiItem{{
						Keyword:          "test keyword",
						SearchVolume:     1200,
						CPC:              1.5,
						CompetitionLevel: "HIGH",
						CompetitionIndex: ptrFloat(78.0),
					}},
				}},
			}},
		}
		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(resp)
	}))
	defer srv.Close()

	c := newTestClient(srv.URL, "user", "pass")
	metrics, err := c.SearchVolume(context.Background(), []string{"test keyword"}, "United States")
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(metrics) != 1 {
		t.Fatalf("expected 1 metric, got %d", len(metrics))
	}
	m := metrics[0]
	if m.Keyword != "test keyword" {
		t.Errorf("keyword = %q, want %q", m.Keyword, "test keyword")
	}
	if m.SearchVolume != 1200 {
		t.Errorf("search_volume = %d, want 1200", m.SearchVolume)
	}
	if m.CPC != 1.5 {
		t.Errorf("cpc = %f, want 1.5", m.CPC)
	}
	if m.Competition != "HIGH" {
		t.Errorf("competition = %q, want HIGH", m.Competition)
	}
	if m.CompetitionIndex != 78.0 {
		t.Errorf("competition_index = %f, want 78.0", m.CompetitionIndex)
	}
}

func TestSearchVolumeMaxFiveKeywords(t *testing.T) {
	var receivedKeywords []string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body, _ := io.ReadAll(r.Body)
		var payload []map[string]any
		json.Unmarshal(body, &payload)
		if kws, ok := payload[0]["keywords"].([]any); ok {
			for _, k := range kws {
				receivedKeywords = append(receivedKeywords, k.(string))
			}
		}

		resp := apiResponse{
			StatusCode:    20000,
			StatusMessage: "Ok.",
			Tasks: []apiTask{{
				StatusCode:    20000,
				StatusMessage: "Ok.",
				Result:        []apiTaskResult{{Items: []apiItem{}}},
			}},
		}
		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(resp)
	}))
	defer srv.Close()

	c := newTestClient(srv.URL, "user", "pass")
	keywords := []string{"a", "b", "c", "d", "e", "f", "g"}
	_, err := c.SearchVolume(context.Background(), keywords, "")
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(receivedKeywords) != 5 {
		t.Fatalf("expected 5 keywords sent to API, got %d", len(receivedKeywords))
	}
}

func TestRelatedKeywords(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		resp := apiResponse{
			StatusCode:    20000,
			StatusMessage: "Ok.",
			Tasks: []apiTask{{
				StatusCode:    20000,
				StatusMessage: "Ok.",
				Result: []apiTaskResult{{
					Items: []apiItem{{
						KeywordData: &apiKeywordData{
							Keyword: "related term",
							KeywordInfo: apiKeywordInfo{
								SearchVolume:      800,
								CPC:               2.3,
								CompetitionLevel:  "MEDIUM",
								KeywordDifficulty: 45.0,
							},
						},
					}},
				}},
			}},
		}
		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(resp)
	}))
	defer srv.Close()

	c := newTestClient(srv.URL, "user", "pass")
	suggestions, err := c.RelatedKeywords(context.Background(), "seed", "United States")
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(suggestions) != 1 {
		t.Fatalf("expected 1 suggestion, got %d", len(suggestions))
	}
	s := suggestions[0]
	if s.Keyword != "related term" {
		t.Errorf("keyword = %q, want %q", s.Keyword, "related term")
	}
	if s.SearchVolume != 800 {
		t.Errorf("search_volume = %d, want 800", s.SearchVolume)
	}
	if s.CPC != 2.3 {
		t.Errorf("cpc = %f, want 2.3", s.CPC)
	}
	if s.Competition != "MEDIUM" {
		t.Errorf("competition = %q, want MEDIUM", s.Competition)
	}
	if s.KeywordDifficulty != 45.0 {
		t.Errorf("keyword_difficulty = %f, want 45.0", s.KeywordDifficulty)
	}
}

func TestRankedKeywords(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		resp := apiResponse{
			StatusCode:    20000,
			StatusMessage: "Ok.",
			Tasks: []apiTask{{
				StatusCode:    20000,
				StatusMessage: "Ok.",
				Result: []apiTaskResult{{
					Items: []apiItem{{
						RankedSerpElement: &apiRankedSERP{
							SERPItem: apiSERPItem{
								Type:      "organic",
								RankGroup: 3,
								URL:       "https://example.com/page",
								KeywordData: &apiKeywordData{
									Keyword: "ranked term",
									KeywordInfo: apiKeywordInfo{
										SearchVolume:      5000,
										CPC:               3.2,
										KeywordDifficulty: 60.0,
									},
								},
							},
						},
					}},
				}},
			}},
		}
		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(resp)
	}))
	defer srv.Close()

	c := newTestClient(srv.URL, "user", "pass")
	ranked, err := c.RankedKeywords(context.Background(), "example.com", "United States")
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(ranked) != 1 {
		t.Fatalf("expected 1 ranked keyword, got %d", len(ranked))
	}
	rk := ranked[0]
	if rk.Keyword != "ranked term" {
		t.Errorf("keyword = %q, want %q", rk.Keyword, "ranked term")
	}
	if rk.Position != 3 {
		t.Errorf("position = %d, want 3", rk.Position)
	}
	if rk.SearchVolume != 5000 {
		t.Errorf("search_volume = %d, want 5000", rk.SearchVolume)
	}
	if rk.CPC != 3.2 {
		t.Errorf("cpc = %f, want 3.2", rk.CPC)
	}
	if rk.KeywordDifficulty != 60.0 {
		t.Errorf("keyword_difficulty = %f, want 60.0", rk.KeywordDifficulty)
	}
	if rk.URL != "https://example.com/page" {
		t.Errorf("url = %q, want %q", rk.URL, "https://example.com/page")
	}
}

func TestAPIError(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		resp := apiResponse{
			StatusCode:    40000,
			StatusMessage: "You can set only one task at a time",
			Tasks:         nil,
		}
		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(resp)
	}))
	defer srv.Close()

	c := newTestClient(srv.URL, "user", "pass")
	_, err := c.SearchVolume(context.Background(), []string{"test"}, "")
	if err == nil {
		t.Fatal("expected error, got nil")
	}
	if got := err.Error(); got == "" {
		t.Error("error message should not be empty")
	}
}

func TestNoCredentials(t *testing.T) {
	c := NewClient(func() (string, string) { return "", "" })

	if c.HasCredentials() {
		t.Error("HasCredentials() should return false for empty credentials")
	}

	_, err := c.SearchVolume(context.Background(), []string{"test"}, "")
	if err == nil {
		t.Fatal("expected error for empty credentials, got nil")
	}
}
