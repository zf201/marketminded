package main

import (
	"log"
	"net/http"
	"os"
	"strings"

	"github.com/zanfridau/marketminded/internal/ai"
	"github.com/zanfridau/marketminded/internal/config"
	"github.com/zanfridau/marketminded/internal/pipeline"
	"github.com/zanfridau/marketminded/internal/pipeline/steps"
	"github.com/zanfridau/marketminded/internal/prompt"
	"github.com/zanfridau/marketminded/internal/search"
	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/internal/tools"
	"github.com/zanfridau/marketminded/web/handlers"
)

func main() {
	cfg, err := config.Load()
	if err != nil {
		log.Fatalf("config: %v", err)
	}

	db, err := store.Open(cfg.DBPath, os.DirFS("migrations"))
	if err != nil {
		log.Fatalf("db: %v", err)
	}
	defer db.Close()

	queries := store.NewQueries(db)

	// Clients
	aiClient := ai.NewClient(cfg.OpenRouterAPIKey)
	braveClient := search.NewBraveClient(cfg.BraveAPIKey)

	// Model resolvers: DB setting > env var default
	contentModel := func() string {
		if v, err := queries.GetSetting("model_content"); err == nil && v != "" {
			return v
		}
		return cfg.ModelContent
	}
	copywritingModel := func() string {
		if v, err := queries.GetSetting("model_copywriting"); err == nil && v != "" {
			return v
		}
		return cfg.ModelCopywriting
	}
	ideationModel := func() string {
		if v, err := queries.GetSetting("model_ideation"); err == nil && v != "" {
			return v
		}
		return cfg.ModelIdeation
	}
	// Prompt builder
	promptBuilder, err := prompt.NewBuilder("prompts")
	if err != nil {
		log.Fatalf("prompts: %v", err)
	}

	// Tool registry and orchestrator
	toolRegistry := tools.NewRegistry(braveClient)

	orchestrator := pipeline.NewOrchestrator(
		queries,
		&steps.ResearchStep{AI: aiClient, Tools: toolRegistry, Prompt: promptBuilder, Model: contentModel},
		&steps.BrandEnricherStep{AI: aiClient, Tools: toolRegistry, Prompt: promptBuilder, Profile: queries, Model: contentModel},
		&steps.FactcheckStep{AI: aiClient, Tools: toolRegistry, Prompt: promptBuilder, Model: contentModel},
		&steps.EditorStep{AI: aiClient, Tools: toolRegistry, Prompt: promptBuilder, Pipeline: queries, VoiceTone: queries, Model: contentModel},
		&steps.WriterStep{AI: aiClient, Prompt: promptBuilder, Content: queries, Pipeline: queries, Model: copywritingModel},
	)

	// Handlers
	dashboardHandler := handlers.NewDashboardHandler(queries)
	projectHandler := handlers.NewProjectHandler(queries)
	pipelineHandler := handlers.NewPipelineHandler(queries, orchestrator, aiClient, copywritingModel, promptBuilder)
	contentHandler := handlers.NewContentHandler(queries)
	settingsHandler := handlers.NewSettingsHandler(queries)
	profileHandler := handlers.NewProfileHandler(queries, aiClient, braveClient, contentModel)
	contextHandler := handlers.NewContextHandler(queries, aiClient, contentModel)
	projectSettingsHandler := handlers.NewProjectSettingsHandler(queries)
	topicHandler := handlers.NewTopicHandler(queries, aiClient, braveClient, toolRegistry, promptBuilder, ideationModel)

	mux := http.NewServeMux()

	// Static files
	mux.Handle("/static/", http.StripPrefix("/static/", http.FileServer(http.Dir("web/static"))))

	// Dashboard + Settings
	mux.Handle("/", dashboardHandler)
	mux.Handle("/settings", settingsHandler)

	// Project routes
	projectHandler.Register(mux)

	// Sub-router for /projects/{id}/...
	mux.HandleFunc("/projects/", func(w http.ResponseWriter, r *http.Request) {
		path := r.URL.Path
		projectID, rest, err := handlers.ParseProjectID(path)
		if err != nil {
			http.NotFound(w, r)
			return
		}

		// If no sub-path, show project overview
		if rest == "" {
			projectHandler.ShowProject(w, r, projectID)
			return
		}

		switch {
		case strings.HasPrefix(rest, "pipeline"):
			pipelineHandler.Handle(w, r, projectID, rest)
		case strings.HasPrefix(rest, "topics"):
			topicHandler.Handle(w, r, projectID, rest)
		case strings.HasPrefix(rest, "content"):
			contentHandler.Handle(w, r, projectID, rest)
case strings.HasPrefix(rest, "profile"):
			profileHandler.Handle(w, r, projectID, rest)
		case rest == "context-memory" || rest == "context-memory/":
			projectHandler.HandleContextMemory(w, r, projectID)
		case strings.HasPrefix(rest, "context"):
			contextHandler.Handle(w, r, projectID, rest)
		case rest == "settings" || rest == "settings/":
			projectSettingsHandler.Handle(w, r, projectID, rest)
		default:
			http.NotFound(w, r)
		}
	})

	log.Printf("Starting MarketMinded on :%s", cfg.Port)
	log.Fatal(http.ListenAndServe(":"+cfg.Port, mux))
}
