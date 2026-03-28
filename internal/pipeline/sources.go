package pipeline

import (
	"encoding/json"
	"fmt"
	"strings"

	"github.com/zanfridau/marketminded/internal/store"
)

// Source represents a research source from pipeline step outputs.
type Source struct {
	URL     string
	Title   string
	Summary string
	Date    string
}

// CollectSources gathers all unique sources from completed pipeline steps.
func CollectSources(steps []store.PipelineStep) []Source {
	seen := map[string]bool{}
	var sources []Source
	for _, s := range steps {
		if s.Output == "" {
			continue
		}
		var parsed struct {
			Sources []struct {
				URL     string `json:"url"`
				Title   string `json:"title"`
				Summary string `json:"summary"`
				Date    string `json:"date"`
			} `json:"sources"`
		}
		if json.Unmarshal([]byte(s.Output), &parsed) == nil {
			for _, src := range parsed.Sources {
				if src.URL != "" && !seen[src.URL] {
					seen[src.URL] = true
					sources = append(sources, Source{src.URL, src.Title, src.Summary, src.Date})
				}
			}
		}
	}
	return sources
}

// FormatSourcesText formats sources for inclusion in prompts.
func FormatSourcesText(sources []Source) string {
	if len(sources) == 0 {
		return ""
	}
	var b strings.Builder
	b.WriteString("\n## Sources (from research, brand analysis, and fact-checking)\n")
	for _, s := range sources {
		line := fmt.Sprintf("- [%s](%s): %s", s.Title, s.URL, s.Summary)
		if s.Date != "" {
			line += fmt.Sprintf(" (%s)", s.Date)
		}
		b.WriteString(line + "\n")
	}
	return b.String()
}

// ToolCallsJSON serializes tool call records to JSON string.
func ToolCallsJSON(calls []ToolCallRecord) string {
	if len(calls) == 0 {
		return ""
	}
	data, _ := json.Marshal(calls)
	return string(data)
}
