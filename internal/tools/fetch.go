package tools

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"

	"github.com/PuerkitoBio/goquery"
	"github.com/zanfridau/marketminded/internal/ai"
)

var fetchHTTPClient = &http.Client{Timeout: 10 * time.Second}

func NewFetchTool() ai.Tool {
	return ai.Tool{
		Type: "function",
		Function: ai.ToolFunction{
			Name:        "fetch_url",
			Description: "Fetch a URL and extract the main text content from the page. Use this when the user shares a link or you need to read a webpage.",
			Parameters:  json.RawMessage(`{"type":"object","properties":{"url":{"type":"string","description":"The URL to fetch"}},"required":["url"]}`),
		},
	}
}

type fetchArgs struct {
	URL string `json:"url"`
}

func ExecuteFetch(ctx context.Context, argsJSON string) (string, error) {
	var args fetchArgs
	if err := json.Unmarshal([]byte(argsJSON), &args); err != nil {
		return "", fmt.Errorf("invalid arguments: %w", err)
	}

	req, err := http.NewRequestWithContext(ctx, "GET", args.URL, nil)
	if err != nil {
		return "", fmt.Errorf("invalid URL: %w", err)
	}
	req.Header.Set("User-Agent", "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36")
	req.Header.Set("Accept", "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8")
	req.Header.Set("Accept-Language", "en-US,en;q=0.5")

	resp, err := fetchHTTPClient.Do(req)
	if err != nil {
		return "", fmt.Errorf("fetch failed: %w", err)
	}
	defer resp.Body.Close()

	// Limit to 1MB
	limited := io.LimitReader(resp.Body, 1<<20)

	doc, err := goquery.NewDocumentFromReader(limited)
	if err != nil {
		return "", fmt.Errorf("parse HTML failed: %w", err)
	}

	// Extract title
	title := strings.TrimSpace(doc.Find("title").First().Text())

	// Remove noise elements
	doc.Find("script, style, nav, footer, header, aside, iframe, noscript").Remove()

	// Get text content
	text := strings.TrimSpace(doc.Find("body").Text())

	// Collapse whitespace
	lines := strings.Split(text, "\n")
	var cleaned []string
	for _, line := range lines {
		line = strings.TrimSpace(line)
		if line != "" {
			cleaned = append(cleaned, line)
		}
	}
	text = strings.Join(cleaned, "\n")

	// Truncate
	if len(text) > 4000 {
		text = text[:4000] + "..."
	}

	return fmt.Sprintf("Title: %s\n\n%s", title, text), nil
}

// FetchSummary returns a human-readable summary for the frontend indicator
func FetchSummary(argsJSON string) string {
	var args fetchArgs
	if err := json.Unmarshal([]byte(argsJSON), &args); err != nil {
		return "Fetching URL..."
	}
	u, err := url.Parse(args.URL)
	if err != nil {
		return "Fetching URL..."
	}
	return fmt.Sprintf("Fetching: %s", u.Host)
}
