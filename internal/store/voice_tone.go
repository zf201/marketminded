package store

import (
	"encoding/json"
	"fmt"
	"strings"
	"time"

	"github.com/zanfridau/marketminded/internal/content"
)

type FrameworkSelection struct {
	Key  string `json:"key"`
	Note string `json:"note"`
}

type VoiceToneProfile struct {
	ID                     int64
	ProjectID              int64
	VoiceAnalysis          string
	ContentTypes           string
	ShouldAvoid            string
	ShouldUse              string
	StyleInspiration       string
	StorytellingFrameworks string // JSON: [{"key":"storybrand","note":"..."},...]
	PreferredLength        int
	CreatedAt              time.Time
}

func (vt *VoiceToneProfile) ParseFrameworks() []FrameworkSelection {
	var fs []FrameworkSelection
	json.Unmarshal([]byte(vt.StorytellingFrameworks), &fs)
	return fs
}

// BuildFrameworkBlock builds the storytelling frameworks prompt block
// with full instructions and brand adaptation notes for each selected framework.
func (vt *VoiceToneProfile) BuildFrameworkBlock() string {
	frameworks := vt.ParseFrameworks()
	if len(frameworks) == 0 {
		return ""
	}
	var fb strings.Builder
	fb.WriteString("## Storytelling frameworks\n")
	for _, f := range frameworks {
		fw := content.FrameworkByKey(f.Key)
		if fw == nil {
			continue
		}
		fmt.Fprintf(&fb, "\n### %s (%s)\n", fw.Name, fw.Attribution)
		fb.WriteString(fw.PromptInstruction)
		fmt.Fprintf(&fb, "\nBrand adaptation: %s\n", f.Note)
	}
	return fb.String()
}

func (q *Queries) UpsertVoiceToneProfile(projectID int64, vt VoiceToneProfile) error {
	_, err := q.db.Exec(
		`INSERT INTO voice_tone_profiles (project_id, voice_analysis, content_types, should_avoid, should_use, style_inspiration, storytelling_frameworks, preferred_length)
		 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
		 ON CONFLICT(project_id) DO UPDATE SET
		   voice_analysis = ?, content_types = ?, should_avoid = ?, should_use = ?, style_inspiration = ?,
		   storytelling_frameworks = ?, preferred_length = ?`,
		projectID, vt.VoiceAnalysis, vt.ContentTypes, vt.ShouldAvoid, vt.ShouldUse, vt.StyleInspiration, vt.StorytellingFrameworks, vt.PreferredLength,
		vt.VoiceAnalysis, vt.ContentTypes, vt.ShouldAvoid, vt.ShouldUse, vt.StyleInspiration, vt.StorytellingFrameworks, vt.PreferredLength,
	)
	return err
}

func (q *Queries) GetVoiceToneProfile(projectID int64) (*VoiceToneProfile, error) {
	vt := &VoiceToneProfile{}
	err := q.db.QueryRow(
		`SELECT id, project_id, voice_analysis, content_types, should_avoid, should_use, style_inspiration, storytelling_frameworks, preferred_length, created_at
		 FROM voice_tone_profiles WHERE project_id = ?`, projectID,
	).Scan(&vt.ID, &vt.ProjectID, &vt.VoiceAnalysis, &vt.ContentTypes, &vt.ShouldAvoid, &vt.ShouldUse, &vt.StyleInspiration, &vt.StorytellingFrameworks, &vt.PreferredLength, &vt.CreatedAt)
	return vt, err
}

func (q *Queries) DeleteVoiceToneProfile(projectID int64) error {
	_, err := q.db.Exec("DELETE FROM voice_tone_profiles WHERE project_id = ?", projectID)
	return err
}

func (q *Queries) BuildVoiceToneString(projectID int64) (string, error) {
	vt, err := q.GetVoiceToneProfile(projectID)
	if err != nil {
		return "", nil
	}

	var b strings.Builder
	sections := []struct{ title, content string }{
		{"Voice Analysis", vt.VoiceAnalysis},
		{"Content Types", vt.ContentTypes},
		{"Should Avoid", vt.ShouldAvoid},
		{"Should Use", vt.ShouldUse},
		{"Style Inspiration", vt.StyleInspiration},
	}
	for _, s := range sections {
		if s.content != "" {
			fmt.Fprintf(&b, "### %s\n%s\n\n", s.title, s.content)
		}
	}

	// Storytelling frameworks summary
	frameworks := vt.ParseFrameworks()
	if len(frameworks) > 0 {
		b.WriteString("### Storytelling Frameworks\n")
		for _, f := range frameworks {
			fw := content.FrameworkByKey(f.Key)
			if fw != nil {
				fmt.Fprintf(&b, "- %s: %s\n", fw.Name, f.Note)
			}
		}
		b.WriteString("\n")
	}

	// Preferred length
	if vt.PreferredLength > 0 {
		fmt.Fprintf(&b, "### Preferred Length\nTarget: ~%d words\n\n", vt.PreferredLength)
	}

	return b.String(), nil
}
