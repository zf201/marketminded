package search

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strconv"
)

const defaultBraveURL = "https://api.search.brave.com/res/v1/web/search"

type BraveClient struct {
	apiKey  string
	baseURL string
	http    *http.Client
}

type BraveOption func(*BraveClient)

func WithBraveBaseURL(u string) BraveOption {
	return func(c *BraveClient) { c.baseURL = u }
}

func NewBraveClient(apiKey string, opts ...BraveOption) *BraveClient {
	c := &BraveClient{
		apiKey:  apiKey,
		baseURL: defaultBraveURL,
		http:    &http.Client{},
	}
	for _, opt := range opts {
		opt(c)
	}
	return c
}

type braveResponse struct {
	Web webResults `json:"web"`
}

type webResults struct {
	Results []webResult `json:"results"`
}

type webResult struct {
	Title       string `json:"title"`
	URL         string `json:"url"`
	Description string `json:"description"`
}

// SearchResult represents a single web search result.
type SearchResult struct {
	Title       string
	URL         string
	Description string
}

func (c *BraveClient) Search(ctx context.Context, query string, count int) ([]SearchResult, error) {
	params := url.Values{}
	params.Set("q", query)
	params.Set("count", strconv.Itoa(count))

	req, err := http.NewRequestWithContext(ctx, "GET", c.baseURL+"?"+params.Encode(), nil)
	if err != nil {
		return nil, err
	}
	req.Header.Set("X-Subscription-Token", c.apiKey)
	req.Header.Set("Accept", "application/json")

	resp, err := c.http.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		b, _ := io.ReadAll(resp.Body)
		return nil, fmt.Errorf("brave: %d: %s", resp.StatusCode, string(b))
	}

	var braveResp braveResponse
	if err := json.NewDecoder(resp.Body).Decode(&braveResp); err != nil {
		return nil, err
	}

	results := make([]SearchResult, len(braveResp.Web.Results))
	for i, r := range braveResp.Web.Results {
		results[i] = SearchResult{
			Title:       r.Title,
			URL:         r.URL,
			Description: r.Description,
		}
	}
	return results, nil
}
