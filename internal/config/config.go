package config

import (
	"fmt"
	"os"
)

type Config struct {
	Port             string
	DBPath           string
	OpenRouterAPIKey string
	BraveAPIKey      string
	ModelContent     string
	ModelIdeation    string
}

func Load() (*Config, error) {
	orKey := os.Getenv("OPENROUTER_API_KEY")
	braveKey := os.Getenv("BRAVE_API_KEY")

	if orKey == "" || braveKey == "" {
		return nil, fmt.Errorf("OPENROUTER_API_KEY and BRAVE_API_KEY are required")
	}

	return &Config{
		Port:             envOr("MARKETMINDED_PORT", "8080"),
		DBPath:           envOr("MARKETMINDED_DB_PATH", "./marketminded.db"),
		OpenRouterAPIKey: orKey,
		BraveAPIKey:      braveKey,
		ModelContent:     envOr("MARKETMINDED_MODEL_CONTENT", "anthropic/claude-sonnet-4-20250514"),
		ModelIdeation:    envOr("MARKETMINDED_MODEL_IDEATION", "anthropic/claude-sonnet-4-20250514"),
	}, nil
}

func envOr(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}
