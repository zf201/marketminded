package handlers

import (
	"fmt"
	"net/http"
	"strconv"
	"strings"

	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/web/templates"
)

type ContentHandler struct {
	queries *store.Queries
}

func NewContentHandler(q *store.Queries) *ContentHandler {
	return &ContentHandler{queries: q}
}

func (h *ContentHandler) Handle(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	// rest = "content/123"
	idStr := strings.TrimPrefix(rest, "content/")
	pieceID, err := strconv.ParseInt(idStr, 10, 64)
	if err != nil {
		http.NotFound(w, r)
		return
	}

	if r.Method == "POST" {
		h.update(w, r, projectID, pieceID)
		return
	}
	h.edit(w, r, projectID, pieceID)
}

func (h *ContentHandler) edit(w http.ResponseWriter, r *http.Request, projectID, pieceID int64) {
	piece, err := h.queries.GetContentPiece(pieceID)
	if err != nil {
		http.NotFound(w, r)
		return
	}

	templates.ContentEditPage(templates.ContentEditData{
		ProjectID: projectID,
		Piece: templates.ContentPieceView{
			ID:     piece.ID,
			Type:   piece.Type,
			Title:  piece.Title,
			Body:   piece.Body,
			Status: piece.Status,
		},
	}).Render(r.Context(), w)
}

func (h *ContentHandler) update(w http.ResponseWriter, r *http.Request, projectID, pieceID int64) {
	r.ParseForm()
	title := r.FormValue("title")
	body := r.FormValue("body")
	action := r.FormValue("action")

	h.queries.UpdateContentPiece(pieceID, title, body)

	if action == "approve" {
		h.queries.ApproveContentPiece(pieceID)
	}

	http.Redirect(w, r, fmt.Sprintf("/projects/%d/content/%d", projectID, pieceID), http.StatusSeeOther)
}
