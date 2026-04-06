package ai

import (
	"bufio"
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"strings"
)

const defaultBaseURL = "https://openrouter.ai/api/v1"

// Message represents a chat message for LLM calls.
type Message struct {
	Role    string `json:"role"`
	Content string `json:"content"`
}

// StreamFunc is called for each chunk during streaming LLM responses.
type StreamFunc func(chunk string) error

type chatRequest struct {
	Model    string          `json:"model"`
	Messages []Message `json:"messages"`
	Stream   bool            `json:"stream,omitempty"`
}

type chatResponse struct {
	Choices []choice `json:"choices"`
}

type choice struct {
	Message Message `json:"message"`
	Delta   Message `json:"delta"`
}

type Client struct {
	apiKey  string
	baseURL string
	http    *http.Client
}

type Option func(*Client)

func WithBaseURL(url string) Option {
	return func(c *Client) { c.baseURL = url }
}

func NewClient(apiKey string, opts ...Option) *Client {
	c := &Client{
		apiKey:  apiKey,
		baseURL: defaultBaseURL,
		http:    &http.Client{},
	}
	for _, opt := range opts {
		opt(c)
	}
	return c
}

func (c *Client) Complete(ctx context.Context, model string, messages []Message) (string, error) {
	body, _ := json.Marshal(chatRequest{
		Model:    model,
		Messages: messages,
	})

	req, err := http.NewRequestWithContext(ctx, "POST", c.baseURL+"/chat/completions", bytes.NewReader(body))
	if err != nil {
		return "", err
	}
	req.Header.Set("Authorization", "Bearer "+c.apiKey)
	req.Header.Set("Content-Type", "application/json")

	resp, err := c.http.Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		b, _ := io.ReadAll(resp.Body)
		return "", fmt.Errorf("openrouter: %d: %s", resp.StatusCode, string(b))
	}

	var chatResp chatResponse
	if err := json.NewDecoder(resp.Body).Decode(&chatResp); err != nil {
		return "", err
	}
	if len(chatResp.Choices) == 0 {
		return "", fmt.Errorf("openrouter: no choices returned")
	}
	return chatResp.Choices[0].Message.Content, nil
}

func (c *Client) Stream(ctx context.Context, model string, messages []Message, fn StreamFunc) (string, error) {
	body, _ := json.Marshal(chatRequest{
		Model:    model,
		Messages: messages,
		Stream:   true,
	})

	req, err := http.NewRequestWithContext(ctx, "POST", c.baseURL+"/chat/completions", bytes.NewReader(body))
	if err != nil {
		return "", err
	}
	req.Header.Set("Authorization", "Bearer "+c.apiKey)
	req.Header.Set("Content-Type", "application/json")

	resp, err := c.http.Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		b, _ := io.ReadAll(resp.Body)
		return "", fmt.Errorf("openrouter: %d: %s", resp.StatusCode, string(b))
	}

	var full strings.Builder
	scanner := bufio.NewScanner(resp.Body)
	for scanner.Scan() {
		line := scanner.Text()
		if !strings.HasPrefix(line, "data: ") {
			continue
		}
		data := strings.TrimPrefix(line, "data: ")
		if data == "[DONE]" {
			break
		}

		var chunk chatResponse
		if err := json.Unmarshal([]byte(data), &chunk); err != nil {
			continue
		}
		if len(chunk.Choices) > 0 && chunk.Choices[0].Delta.Content != "" {
			content := chunk.Choices[0].Delta.Content
			full.WriteString(content)
			if err := fn(content); err != nil {
				return full.String(), err
			}
		}
	}
	return full.String(), scanner.Err()
}
