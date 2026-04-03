package store

import (
	"fmt"
	"strings"
	"time"
)

type VoiceToneProfile struct {
	ID               int64
	ProjectID        int64
	VoiceAnalysis    string
	ContentTypes     string
	ShouldAvoid      string
	ShouldUse        string
	StyleInspiration string
	CreatedAt        time.Time
}

func (q *Queries) UpsertVoiceToneProfile(projectID int64, vt VoiceToneProfile) error {
	_, err := q.db.Exec(
		`INSERT INTO voice_tone_profiles (project_id, voice_analysis, content_types, should_avoid, should_use, style_inspiration)
		 VALUES (?, ?, ?, ?, ?, ?)
		 ON CONFLICT(project_id) DO UPDATE SET
		   voice_analysis = ?, content_types = ?, should_avoid = ?, should_use = ?, style_inspiration = ?`,
		projectID, vt.VoiceAnalysis, vt.ContentTypes, vt.ShouldAvoid, vt.ShouldUse, vt.StyleInspiration,
		vt.VoiceAnalysis, vt.ContentTypes, vt.ShouldAvoid, vt.ShouldUse, vt.StyleInspiration,
	)
	return err
}

func (q *Queries) GetVoiceToneProfile(projectID int64) (*VoiceToneProfile, error) {
	vt := &VoiceToneProfile{}
	err := q.db.QueryRow(
		`SELECT id, project_id, voice_analysis, content_types, should_avoid, should_use, style_inspiration, created_at
		 FROM voice_tone_profiles WHERE project_id = ?`, projectID,
	).Scan(&vt.ID, &vt.ProjectID, &vt.VoiceAnalysis, &vt.ContentTypes, &vt.ShouldAvoid, &vt.ShouldUse, &vt.StyleInspiration, &vt.CreatedAt)
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
	return b.String(), nil
}
