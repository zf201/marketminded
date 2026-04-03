package store

import (
	"fmt"
	"strings"
	"time"
)

type AudiencePersona struct {
	ID             int64
	ProjectID      int64
	Label          string
	Description    string
	PainPoints     string
	Push           string
	Pull           string
	Anxiety        string
	Habit          string
	Role           string
	Demographics   string
	CompanyInfo    string
	ContentHabits  string
	BuyingTriggers string
	SortOrder      int
	CreatedAt      time.Time
}

func (q *Queries) CreateAudiencePersona(projectID int64, p AudiencePersona) (*AudiencePersona, error) {
	result, err := q.db.Exec(
		`INSERT INTO audience_personas (project_id, label, description, pain_points, push, pull, anxiety, habit, role, demographics, company_info, content_habits, buying_triggers, sort_order)
		 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
		projectID, p.Label, p.Description, p.PainPoints, p.Push, p.Pull, p.Anxiety, p.Habit,
		p.Role, p.Demographics, p.CompanyInfo, p.ContentHabits, p.BuyingTriggers, p.SortOrder,
	)
	if err != nil {
		return nil, err
	}
	id, _ := result.LastInsertId()
	p.ID = id
	p.ProjectID = projectID
	return &p, nil
}

func (q *Queries) GetAudiencePersona(id int64) (*AudiencePersona, error) {
	p := &AudiencePersona{}
	err := q.db.QueryRow(
		`SELECT id, project_id, label, description, pain_points, push, pull, anxiety, habit,
		        role, demographics, company_info, content_habits, buying_triggers, sort_order, created_at
		 FROM audience_personas WHERE id = ?`, id,
	).Scan(&p.ID, &p.ProjectID, &p.Label, &p.Description, &p.PainPoints, &p.Push, &p.Pull, &p.Anxiety, &p.Habit,
		&p.Role, &p.Demographics, &p.CompanyInfo, &p.ContentHabits, &p.BuyingTriggers, &p.SortOrder, &p.CreatedAt)
	return p, err
}

func (q *Queries) ListAudiencePersonas(projectID int64) ([]AudiencePersona, error) {
	rows, err := q.db.Query(
		`SELECT id, project_id, label, description, pain_points, push, pull, anxiety, habit,
		        role, demographics, company_info, content_habits, buying_triggers, sort_order, created_at
		 FROM audience_personas WHERE project_id = ? ORDER BY sort_order, id`, projectID,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var personas []AudiencePersona
	for rows.Next() {
		var p AudiencePersona
		if err := rows.Scan(&p.ID, &p.ProjectID, &p.Label, &p.Description, &p.PainPoints, &p.Push, &p.Pull, &p.Anxiety, &p.Habit,
			&p.Role, &p.Demographics, &p.CompanyInfo, &p.ContentHabits, &p.BuyingTriggers, &p.SortOrder, &p.CreatedAt); err != nil {
			return nil, err
		}
		personas = append(personas, p)
	}
	return personas, rows.Err()
}

func (q *Queries) UpdateAudiencePersona(id int64, p AudiencePersona) error {
	_, err := q.db.Exec(
		`UPDATE audience_personas SET label=?, description=?, pain_points=?, push=?, pull=?, anxiety=?, habit=?,
		        role=?, demographics=?, company_info=?, content_habits=?, buying_triggers=?, sort_order=?
		 WHERE id=?`,
		p.Label, p.Description, p.PainPoints, p.Push, p.Pull, p.Anxiety, p.Habit,
		p.Role, p.Demographics, p.CompanyInfo, p.ContentHabits, p.BuyingTriggers, p.SortOrder, id,
	)
	return err
}

func (q *Queries) DeleteAudiencePersona(id int64) error {
	_, err := q.db.Exec("DELETE FROM audience_personas WHERE id = ?", id)
	return err
}

func (q *Queries) DeleteAllAudiencePersonas(projectID int64) error {
	_, err := q.db.Exec("DELETE FROM audience_personas WHERE project_id = ?", projectID)
	return err
}

func (q *Queries) BuildAudienceString(projectID int64) (string, error) {
	personas, err := q.ListAudiencePersonas(projectID)
	if err != nil {
		return "", err
	}
	if len(personas) == 0 {
		return "", nil
	}

	var b strings.Builder
	for i, p := range personas {
		fmt.Fprintf(&b, "### Persona %d: %s\n", i+1, p.Label)
		fmt.Fprintf(&b, "**Description:** %s\n", p.Description)
		fmt.Fprintf(&b, "**Pain points:** %s\n", p.PainPoints)
		fmt.Fprintf(&b, "**Push:** %s\n", p.Push)
		fmt.Fprintf(&b, "**Pull:** %s\n", p.Pull)
		fmt.Fprintf(&b, "**Anxiety:** %s\n", p.Anxiety)
		fmt.Fprintf(&b, "**Habit:** %s\n", p.Habit)
		if p.Role != "" {
			fmt.Fprintf(&b, "**Role:** %s\n", p.Role)
		}
		if p.Demographics != "" {
			fmt.Fprintf(&b, "**Demographics:** %s\n", p.Demographics)
		}
		if p.CompanyInfo != "" {
			fmt.Fprintf(&b, "**Company:** %s\n", p.CompanyInfo)
		}
		if p.ContentHabits != "" {
			fmt.Fprintf(&b, "**Content habits:** %s\n", p.ContentHabits)
		}
		if p.BuyingTriggers != "" {
			fmt.Fprintf(&b, "**Buying triggers:** %s\n", p.BuyingTriggers)
		}
		b.WriteString("\n")
	}
	return b.String(), nil
}
