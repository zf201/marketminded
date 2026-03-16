package main

import (
	"log"
	"net/http"
	"os"
	"strings"

	"github.com/zanfridau/marketminded/internal/agents"
	"github.com/zanfridau/marketminded/internal/ai"
	"github.com/zanfridau/marketminded/internal/config"
	"github.com/zanfridau/marketminded/internal/pipeline"
	"github.com/zanfridau/marketminded/internal/search"
	"github.com/zanfridau/marketminded/internal/store"
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
	ideationModel := func() string {
		if v, err := queries.GetSetting("model_ideation"); err == nil && v != "" {
			return v
		}
		return cfg.ModelIdeation
	}

	// Agents
	ideaAgent := agents.NewIdeaAgent(aiClient, braveClient, ideationModel)
	contentAgent := agents.NewContentAgent(aiClient, contentModel)

	// Pipeline
	pipelineStore := &pipelineStoreAdapter{queries: queries}
	pip := pipeline.New(pipelineStore)

	// Handlers
	dashboardHandler := handlers.NewDashboardHandler(queries)
	projectHandler := handlers.NewProjectHandler(queries)
	pipelineHandler := handlers.NewPipelineHandler(queries, pip, ideaAgent, contentAgent)
	contentHandler := handlers.NewContentHandler(queries)
	templateHandler := handlers.NewTemplateHandler(queries)
	brainstormHandler := handlers.NewBrainstormHandler(queries, aiClient, ideationModel)
	settingsHandler := handlers.NewSettingsHandler(queries)

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
		case strings.HasPrefix(rest, "content"):
			contentHandler.Handle(w, r, projectID, rest)
		case strings.HasPrefix(rest, "templates"):
			templateHandler.Handle(w, r, projectID, rest)
		case strings.HasPrefix(rest, "brainstorm"):
			brainstormHandler.Handle(w, r, projectID, rest)
		default:
			http.NotFound(w, r)
		}
	})

	log.Printf("Starting MarketMinded on :%s", cfg.Port)
	log.Fatal(http.ListenAndServe(":"+cfg.Port, mux))
}

// pipelineStoreAdapter adapts store.Queries to pipeline.Store interface
type pipelineStoreAdapter struct {
	queries *store.Queries
}

func (a *pipelineStoreAdapter) GetPipelineRun(id int64) (*pipeline.Run, error) {
	run, err := a.queries.GetPipelineRun(id)
	if err != nil {
		return nil, err
	}
	return &pipeline.Run{
		ID:            run.ID,
		ProjectID:     run.ProjectID,
		Status:        run.Status,
		SelectedTopic: run.SelectedTopic,
	}, nil
}

func (a *pipelineStoreAdapter) AdvancePipelineRun(id int64, status string) error {
	return a.queries.AdvancePipelineRun(id, status)
}

func (a *pipelineStoreAdapter) SetPipelineTopic(id int64, topic string) error {
	return a.queries.SetPipelineTopic(id, topic)
}
