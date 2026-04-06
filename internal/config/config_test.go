package config

import (
	"testing"
)

func TestLoad_Defaults(t *testing.T) {
	t.Setenv("OPENROUTER_API_KEY", "test-key")

	cfg, err := Load()
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if cfg.Port != "8080" {
		t.Errorf("expected port 8080, got %s", cfg.Port)
	}
	if cfg.OpenRouterAPIKey != "test-key" {
		t.Errorf("expected test-key, got %s", cfg.OpenRouterAPIKey)
	}
}

func TestLoad_MissingRequiredKeys(t *testing.T) {
	t.Setenv("OPENROUTER_API_KEY", "")

	_, err := Load()
	if err == nil {
		t.Fatal("expected error for missing API key")
	}
}
