package config

import (
	"fmt"
	"os"
)

type Config struct {
	Port               string
	DBPath             string
	OpenRouterAPIKey   string
	DataForSEOLogin    string
	DataForSEOPassword string
	ModelContent       string
	ModelCopywriting   string
	ModelIdeation      string
}

func Load() (*Config, error) {
	orKey := os.Getenv("OPENROUTER_API_KEY")

	if orKey == "" {
		return nil, fmt.Errorf("OPENROUTER_API_KEY is required")
	}

	return &Config{
		Port:               envOr("MARKETMINDED_PORT", "8080"),
		DBPath:             envOr("MARKETMINDED_DB_PATH", "./marketminded.db"),
		OpenRouterAPIKey:   orKey,
		DataForSEOLogin:    os.Getenv("DATAFORSEO_LOGIN"),
		DataForSEOPassword: os.Getenv("DATAFORSEO_PASSWORD"),
		ModelContent:       envOr("MARKETMINDED_MODEL_CONTENT", "x-ai/grok-4.1-fast"),
		ModelCopywriting:   envOr("MARKETMINDED_MODEL_COPYWRITING", "x-ai/grok-4.1-fast"),
		ModelIdeation:      envOr("MARKETMINDED_MODEL_IDEATION", "x-ai/grok-4.1-fast"),
	}, nil
}

func envOr(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}
