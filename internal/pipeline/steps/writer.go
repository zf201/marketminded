package steps

import (
	"context"
	"encoding/json"
	"fmt"
	"strings"

	"github.com/zanfridau/marketminded/internal/ai"
	"github.com/zanfridau/marketminded/internal/content"
	"github.com/zanfridau/marketminded/internal/pipeline"
	"github.com/zanfridau/marketminded/internal/prompt"
	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/internal/types"
)

type WriterStep struct {
	AI       *ai.Client
	Prompt   *prompt.Builder
	Content  store.ContentStore
	Pipeline store.PipelineStore
	Model    func() string
}

func (s *WriterStep) Type() string { return "write" }

func (s *WriterStep) Run(ctx context.Context, input pipeline.StepInput, stream pipeline.StepStream) (pipeline.StepResult, error) {
	editorOutput := input.PriorOutputs["editor"]

	platform := "blog"
	format := "post"
	ct, ctOk := content.LookupType(platform, format)

	var toneGuide string
	if toneOutput, ok := input.PriorOutputs["tone_analyzer"]; ok {
		var toneResult struct{ ToneGuide string `json:"tone_guide"` }
		if json.Unmarshal([]byte(toneOutput), &toneResult) == nil {
			toneGuide = toneResult.ToneGuide
		}
	}

	var rejectionReason string
	pieces, _ := s.Content.ListContentByPipelineRun(input.RunID)
	for _, p := range pieces {
		if p.ParentID == nil && p.Status == "rejected" && p.RejectionReason != "" {
			rejectionReason = p.RejectionReason
			break
		}
	}

	promptFile := ""
	if ctOk {
		promptFile = ct.PromptFile
	}
	systemPrompt := s.Prompt.ForWriter(promptFile, input.Profile, editorOutput, rejectionReason, toneGuide)

	aiMsgs := []types.Message{
		{Role: "system", Content: systemPrompt},
		{Role: "user", Content: "Write the cornerstone blog post now."},
	}

	var toolList []ai.Tool
	if ctOk {
		toolList = []ai.Tool{ct.Tool}
	}

	var savedPieceID int64
	var thinkingBuf strings.Builder

	executor := func(ctx context.Context, name, args string) (string, error) {
		if content.IsWriteTool(name) {
			var writeArgs struct{ Title string `json:"title"` }
			_ = json.Unmarshal([]byte(args), &writeArgs)
			title := writeArgs.Title
			if title == "" {
				title = input.Topic
			}

			piece, err := s.Content.CreateContentPiece(input.ProjectID, input.RunID, platform, format, title, 0, nil)
			if err != nil {
				return "", fmt.Errorf("failed to create content piece: %w", err)
			}
			savedPieceID = piece.ID

			s.Pipeline.UpdatePipelineTopic(input.RunID, title)
			s.Content.UpdateContentPieceBody(piece.ID, title, args)
			s.Content.SetContentPieceStatus(piece.ID, "draft")

			return "Content piece created successfully.", ai.ErrToolDone
		}
		return "", fmt.Errorf("unknown tool: %s", name)
	}

	onToolEvent := func(event ai.ToolEvent) {
		if event.Type == "tool_result" && content.IsWriteTool(event.Tool) && savedPieceID > 0 {
			piece, err := s.Content.GetContentPiece(savedPieceID)
			if err == nil {
				stream.SendEvent(map[string]any{
					"type":     "content_written",
					"platform": piece.Platform,
					"format":   piece.Format,
					"data":     json.RawMessage(piece.Body),
				})
			}
			stream.SendDone()
		}
	}

	sendChunk := func(chunk string) error {
		return stream.SendChunk(chunk)
	}
	sendThinking := func(chunk string) error {
		thinkingBuf.WriteString(chunk)
		return stream.SendThinking(chunk)
	}

	temp := 0.3
	_, err := s.AI.StreamWithTools(ctx, s.Model(), aiMsgs, toolList, executor, onToolEvent, sendChunk, sendThinking, &temp)

	result := pipeline.StepResult{
		Output:   fmt.Sprintf(`{"piece_id":%d}`, savedPieceID),
		Thinking: thinkingBuf.String(),
	}

	if err != nil && savedPieceID == 0 {
		return result, err
	}

	if savedPieceID == 0 {
		return result, fmt.Errorf("writer did not submit content via tool call")
	}

	return result, nil
}
