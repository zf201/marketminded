package store

import (
	"database/sql"
	"time"
)

type Queries struct {
	db *sql.DB
}

func NewQueries(db *sql.DB) *Queries {
	return &Queries{db: db}
}

type Project struct {
	ID          int64
	Name        string
	Description string
	CreatedAt   time.Time
	UpdatedAt   time.Time
}

func (q *Queries) CreateProject(name, description string) (*Project, error) {
	res, err := q.db.Exec(
		"INSERT INTO projects (name, description) VALUES (?, ?)",
		name, description,
	)
	if err != nil {
		return nil, err
	}
	id, _ := res.LastInsertId()
	return q.GetProject(id)
}

func (q *Queries) GetProject(id int64) (*Project, error) {
	p := &Project{}
	err := q.db.QueryRow(
		"SELECT id, name, COALESCE(description,''), created_at, updated_at FROM projects WHERE id = ?", id,
	).Scan(&p.ID, &p.Name, &p.Description, &p.CreatedAt, &p.UpdatedAt)
	if err != nil {
		return nil, err
	}
	return p, nil
}

func (q *Queries) ListProjects() ([]Project, error) {
	rows, err := q.db.Query("SELECT id, name, COALESCE(description,''), created_at, updated_at FROM projects ORDER BY created_at DESC")
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var projects []Project
	for rows.Next() {
		var p Project
		if err := rows.Scan(&p.ID, &p.Name, &p.Description, &p.CreatedAt, &p.UpdatedAt); err != nil {
			return nil, err
		}
		projects = append(projects, p)
	}
	return projects, rows.Err()
}

func (q *Queries) DeleteProject(id int64) error {
	_, err := q.db.Exec("DELETE FROM projects WHERE id = ?", id)
	return err
}
