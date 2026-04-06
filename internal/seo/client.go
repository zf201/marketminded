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

const defaultBaseURL = "https://api.dataforseo.com/v3"

// CredentialFunc returns (login, password). Resolved at call time so DB
// settings changes take effect without restart.
type CredentialFunc func() (login, password string)

type Client struct {
	creds      CredentialFunc
	httpClient *http.Client
	baseURL    string
}

func NewClient(creds CredentialFunc) *Client {
	return &Client{
		creds:      creds,
		httpClient: &http.Client{Timeout: 30 * time.Second},
		baseURL:    defaultBaseURL,
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
		"keyword":                  seed,
		"location_name":            location,
		"language_name":            "English",
		"depth":                    1,
		"limit":                    10,
		"include_serp_info":        false,
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

	req, err := http.NewRequestWithContext(ctx, "POST", c.baseURL+path, bytes.NewReader(jsonBody))
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

	respBody, err := io.ReadAll(io.LimitReader(resp.Body, 1<<20)) // 1MB limit
	if err != nil {
		return fmt.Errorf("read response: %w", err)
	}

	if err := json.Unmarshal(respBody, out); err != nil {
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
